<?php

namespace App\Http\Controllers;

use App\Models\CallAttempt;
use App\Models\Contact;
use App\Models\DialerSession;
use App\Services\DialerEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ingest point for Asterisk call events forwarded by mkb-dialer-core.
 *
 * Core owns the Stasis WebSocket; this owns the decisions. The important
 * one is `lead_answered`: core asks who to bridge to, and the answer is
 * computed here under a row lock so two simultaneous answers can never
 * be handed the same agent.
 */
class CallEventController extends Controller
{
    public function __construct(private DialerEngine $engine) {}

    public function store(Request $request)
    {
        $validated = $request->validate([
            'event' => 'required|string',
            'attempt_id' => 'nullable|integer',
            'channel_id' => 'nullable|string',
            'agent_channel_id' => 'nullable|string',
            'bridge_id' => 'nullable|string',
            'cause' => 'nullable|string',
            'agent_extension' => 'nullable|string',
            'destination' => 'nullable|string',
            'recording_name' => 'nullable|string|max:255',
            'recording_format' => 'nullable|string|max:8',
            'recording_status' => 'nullable|string|max:32',
        ]);

        // Registers a brand new call, so it runs before attempt resolution.
        if ($validated['event'] === 'agent_dial_start') {
            return $this->agentDialStart($validated);
        }

        $attempt = $this->resolveAttempt($validated);
        if (! $attempt) {
            Log::info('Call event for unknown attempt', $validated);
            return response()->json(['message' => 'Unknown attempt'], 404);
        }

        return match ($validated['event']) {
            'lead_answered' => $this->leadAnswered($attempt, $validated),
            'agent_ringing' => $this->agentRinging($attempt, $validated),
            'bridged' => $this->bridged($attempt, $validated),
            'third_party_left' => $this->thirdPartyLeft($attempt),
            'recording' => $this->recording($attempt, $validated),
            'ended' => $this->ended($attempt, $validated),
            default => response()->json(['message' => 'Ignored event'], 202),
        };
    }

    /**
     * An agent dialed a number from their phone. Create the call record and
     * tell core which caller ID to present and what to actually dial.
     *
     * The agent is identified by SIP extension, which is the only thing the
     * channel carries — there is no session here.
     */
    private function agentDialStart(array $data)
    {
        $extension = $data['agent_extension'] ?? null;
        $destination = $data['destination'] ?? null;

        if (! $extension || ! $destination) {
            return response()->json(['message' => 'agent_extension and destination are required.'], 422);
        }

        $member = DB::table('company_user')->where('sip_extension', $extension)->first();
        if (! $member) {
            Log::warning('Agent dial from an unknown extension', ['extension' => $extension]);
            return response()->json(['message' => "No agent owns extension {$extension}."], 404);
        }

        $e164 = $this->engine->toE164($destination);
        if (! $e164) {
            return response()->json([
                'message' => "\"{$destination}\" is not a dialable number.",
            ], 422);
        }

        $attempt = CallAttempt::create([
            'company_id' => $member->company_id,
            'campaign_id' => $member->active_campaign_id,
            'contact_id' => Contact::withoutGlobalScope('company')
                ->where('company_id', $member->company_id)
                ->where('phone', $destination)->value('id'),
            'user_id' => $member->user_id,
            'phone' => $e164,
            'direction' => 'outbound',
            'status' => 'ringing',
            'channel_id' => $data['channel_id'] ?? null,
            'dialed_at' => now(),
        ]);

        return response()->json([
            'attemptId' => (string) $attempt->id,
            'callerId' => config('services.dialer.caller_id'),
            'dialString' => $e164,
        ], 201);
    }

    private function resolveAttempt(array $data): ?CallAttempt
    {
        if (! empty($data['attempt_id'])) {
            return CallAttempt::find($data['attempt_id']);
        }
        if (! empty($data['channel_id'])) {
            return CallAttempt::where('channel_id', $data['channel_id'])
                ->orWhere('agent_channel_id', $data['channel_id'])
                ->latest('id')->first();
        }
        return null;
    }

    /**
     * The lead picked up. Reserve a free agent and tell core where to bridge.
     *
     * The transaction + lockForUpdate is load-bearing: two leads answering in
     * the same instant would otherwise both be told to use the same agent,
     * and the second bridge would land on a busy phone.
     */
    private function leadAnswered(CallAttempt $attempt, array $data)
    {
        if ($attempt->answered_at) {
            return response()->json([
                'agentExtension' => null,
                'message' => 'Already answered',
            ], 202);
        }

        $session = $attempt->session;

        $assigned = DB::transaction(function () use ($attempt, $session) {
            $attempt->update([
                'status' => 'answered',
                'answered_at' => now(),
            ]);

            if (! $session) {
                return null;
            }

            // Lock the pivot rows so concurrent answers serialise here.
            $busyUserIds = CallAttempt::where('dialer_session_id', $session->id)
                ->whereIn('status', CallAttempt::LIVE_STATUSES)
                ->whereNotNull('user_id')
                ->pluck('user_id')->all();

            $agent = DB::table('company_user')
                ->where('company_id', $session->company_id)
                ->where('queue_eligible', true)
                ->whereNotNull('sip_extension')
                ->whereIn('status', ['available', 'online'])
                ->when($busyUserIds, fn ($q) => $q->whereNotIn('user_id', $busyUserIds))
                ->lockForUpdate()
                ->first();

            if (! $agent) {
                return null;
            }

            // Claiming the agent on the attempt row is what makes them "busy"
            // for every other concurrent answer.
            $attempt->update(['user_id' => $agent->user_id]);

            return $agent;
        });

        if (! $assigned) {
            // Nobody to hand this to. Regulators call this an abandoned call;
            // we mark it explicitly rather than silently dropping the channel.
            $attempt->update([
                'status' => 'abandoned',
                'hangup_cause' => 'no-agent-available',
            ]);

            return response()->json([
                'agentExtension' => null,
                'action' => 'playback_and_hangup',
                'message' => 'No agent available',
            ]);
        }

        return response()->json([
            'agentExtension' => $assigned->sip_extension,
            'userId' => $assigned->user_id,
            'attemptId' => $attempt->id,
            'action' => 'bridge_to_agent',
        ]);
    }

    private function agentRinging(CallAttempt $attempt, array $data)
    {
        $attempt->update([
            'agent_channel_id' => $data['agent_channel_id'] ?? $attempt->agent_channel_id,
        ]);

        return response()->json(['ok' => true]);
    }

    private function bridged(CallAttempt $attempt, array $data)
    {
        $attempt->update([
            'status' => 'bridged',
            'answered_at' => $attempt->answered_at ?: now(),
            'bridge_id' => $data['bridge_id'] ?? null,
            'agent_channel_id' => $data['agent_channel_id'] ?? $attempt->agent_channel_id,
            'bridged_at' => $attempt->bridged_at ?: now(),
        ]);

        return response()->json(['ok' => true]);
    }

    private function recording(CallAttempt $attempt, array $data)
    {
        $attempt->update(array_filter([
            'recording_name' => $data['recording_name'] ?? null,
            'recording_format' => $data['recording_format'] ?? null,
            'recording_status' => $data['recording_status'] ?? null,
        ], fn ($v) => $v !== null));

        return response()->json(['ok' => true]);
    }

    /**
     * Outcome for a call no agent ever spoke on. Kept deliberately coarse —
     * these are machine facts, not judgements, and must stay distinguishable
     * from anything an agent chose.
     */
    public static function autoDisposition(string $status): string
    {
        return match ($status) {
            'busy' => 'busy',
            'no-answer' => 'no-answer',
            'abandoned' => 'abandoned',
            'completed' => 'answered-no-agent',
            default => 'failed',
        };
    }

    /** The third party hung up; agent and customer stay connected. */
    private function thirdPartyLeft(CallAttempt $attempt)
    {
        $attempt->update(['third_party_channel_id' => null, 'third_party_number' => null]);

        return response()->json(['ok' => true]);
    }

    private function ended(CallAttempt $attempt, array $data)
    {
        if (in_array($attempt->status, CallAttempt::TERMINAL_STATUSES, true) && $attempt->ended_at) {
            return response()->json(['ok' => true, 'message' => 'Already terminal'], 202);
        }

        $endedAt = now();
        $cause = $data['cause'] ?? null;

        $normalised = strtolower((string) $cause);

        $status = match (true) {
            $attempt->bridged_at !== null => 'completed',
            $attempt->answered_at !== null => $attempt->status === 'abandoned' ? 'abandoned' : 'completed',
            str_contains($normalised, 'busy') => 'busy',
            str_contains($normalised, 'no answer'),
            str_contains($normalised, 'no-answer'),
            str_contains($normalised, 'noanswer'),
            str_contains($normalised, 'timeout'),
            str_contains($normalised, 'cancel') => 'no-answer',
            str_contains($normalised, 'congestion'),
            str_contains($normalised, 'unallocated'),
            str_contains($normalised, 'unassigned') => 'failed',
            default => 'failed',
        };

        $attempt->update([
            'status' => $status,
            // A call that never reached an agent gets its outcome recorded
            // automatically — there is no human to ask. Only calls that
            // actually connected are left blank for the agent to disposition.
            'disposition' => $attempt->disposition
                ?: ($attempt->bridged_at ? null : self::autoDisposition($status)),
            'hangup_cause' => $cause ?: $attempt->hangup_cause,
            'ended_at' => $endedAt,
            // Carbon 3 returns signed diffs, so subtracting in the wrong
            // direction yields a negative that an unsigned column rejects —
            // which silently 500'd every hangup and left calls stuck "bridged".
            'duration' => $attempt->answered_at
                ? max(0, (int) $attempt->answered_at->diffInSeconds($endedAt))
                : 0,
            'talk_duration' => $attempt->bridged_at
                ? max(0, (int) $attempt->bridged_at->diffInSeconds($endedAt))
                : 0,
        ]);

        return response()->json(['ok' => true, 'status' => $status]);
    }

    /** Agent picks a disposition after wrap-up. */
    public function disposition(Request $request, int $id)
    {
        $validated = $request->validate([
            'disposition' => 'required|string|max:64',
            'notes' => 'nullable|string|max:2000',
            'stage' => 'nullable|string|max:64',
            'value' => 'nullable|numeric|min:0',
            'callback_date' => 'nullable|date',
        ]);

        $attempt = CallAttempt::find($id);
        if (! $attempt) {
            return response()->json(['message' => 'Call not found.'], 404);
        }

        $attempt->update([
            'disposition' => $validated['disposition'],
            'notes' => $validated['notes'] ?? $attempt->notes,
        ]);

        $contact = $attempt->contact_id
            ? Contact::withoutGlobalScope('company')->find($attempt->contact_id)
            : null;

        if ($contact) {
            $contact->update(array_filter([
                'stage' => $validated['stage'] ?? $validated['disposition'],
                'notes' => $validated['notes'] ?? null,
                'value' => $validated['value'] ?? null,
                'callback_date' => $validated['callback_date'] ?? null,
            ], fn ($v) => $v !== null));
        }

        return response()->json([
            'ok' => true,
            'attemptId' => (string) $attempt->id,
            'contactUpdated' => (bool) $contact,
            'lead' => $contact?->toLeadPayload(),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\CallAttempt;
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
        ]);

        $attempt = $this->resolveAttempt($validated);
        if (! $attempt) {
            Log::info('Call event for unknown attempt', $validated);
            return response()->json(['message' => 'Unknown attempt'], 404);
        }

        return match ($validated['event']) {
            'lead_answered' => $this->leadAnswered($attempt, $validated),
            'agent_ringing' => $this->agentRinging($attempt, $validated),
            'bridged' => $this->bridged($attempt, $validated),
            'ended' => $this->ended($attempt, $validated),
            default => response()->json(['message' => 'Ignored event'], 202),
        };
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
            'bridge_id' => $data['bridge_id'] ?? null,
            'agent_channel_id' => $data['agent_channel_id'] ?? $attempt->agent_channel_id,
            'bridged_at' => $attempt->bridged_at ?: now(),
        ]);

        return response()->json(['ok' => true]);
    }

    private function ended(CallAttempt $attempt, array $data)
    {
        if (in_array($attempt->status, CallAttempt::TERMINAL_STATUSES, true) && $attempt->ended_at) {
            return response()->json(['ok' => true, 'message' => 'Already terminal'], 202);
        }

        $endedAt = now();
        $cause = $data['cause'] ?? null;

        $status = match (true) {
            $attempt->bridged_at !== null => 'completed',
            $attempt->answered_at !== null => $attempt->status === 'abandoned' ? 'abandoned' : 'completed',
            in_array($cause, ['busy', 'user-busy'], true) => 'busy',
            in_array($cause, ['no-answer', 'noanswer', 'timeout'], true) => 'no-answer',
            default => 'failed',
        };

        $attempt->update([
            'status' => $status,
            'hangup_cause' => $cause ?: $attempt->hangup_cause,
            'ended_at' => $endedAt,
            'duration' => $attempt->answered_at ? $endedAt->diffInSeconds($attempt->answered_at) : 0,
            'talk_duration' => $attempt->bridged_at ? $endedAt->diffInSeconds($attempt->bridged_at) : 0,
        ]);

        return response()->json(['ok' => true, 'status' => $status]);
    }

    /** Agent picks a disposition after wrap-up. */
    public function disposition(Request $request, int $id)
    {
        $validated = $request->validate([
            'disposition' => 'required|string|max:64',
            'notes' => 'nullable|string|max:2000',
        ]);

        $attempt = CallAttempt::findOrFail($id);
        $attempt->update($validated);

        return response()->json(['ok' => true]);
    }
}

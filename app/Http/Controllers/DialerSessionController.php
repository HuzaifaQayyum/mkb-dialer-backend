<?php

namespace App\Http\Controllers;

use App\Http\Middleware\TenantScopeMiddleware;
use App\Models\CallAttempt;
use App\Models\Contact;
use App\Models\DialerSession;
use App\Services\AsteriskAri;
use App\Services\DialerEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DialerSessionController extends Controller
{
    public function __construct(
        private DialerEngine $engine,
        private AsteriskAri $ari,
    ) {}

    private function companyId(): ?int
    {
        return TenantScopeMiddleware::$companyId;
    }

    /** Everything the bulk dashboard renders, in one call. */
    public function show()
    {
        $companyId = $this->companyId();
        if (! $companyId) {
            return response()->json(['message' => 'No active company'], 422);
        }

        $session = DialerSession::where('company_id', $companyId)
            ->whereIn('status', ['active', 'draining'])
            ->latest('id')
            ->first();

        $agents = DB::table('company_user')
            ->join('users', 'users.id', '=', 'company_user.user_id')
            ->where('company_user.company_id', $companyId)
            ->select(
                'users.id', 'users.name', 'company_user.role',
                'company_user.status', 'company_user.queue_eligible',
                'company_user.sip_extension'
            )
            ->get();

        $liveAttempts = CallAttempt::with('contact')
            ->where('company_id', $companyId)
            ->whereIn('status', CallAttempt::LIVE_STATUSES)
            ->get()
            ->keyBy('user_id');

        $agentStates = $agents->map(function ($a) use ($liveAttempts) {
            $call = $liveAttempts->get($a->id);
            return [
                'agentId' => $a->id,
                'name' => $a->name,
                'role' => $a->role,
                'sipExtension' => $a->sip_extension,
                'queueEligible' => (bool) $a->queue_eligible,
                'loggedIn' => in_array($a->status, ['available', 'online'], true),
                'status' => $this->agentStatus($a, $call),
                'contactName' => $call?->contact?->name,
                'phone' => $call?->phone,
                'callDurationSec' => $call?->bridged_at
                    ? max(0, (int) $call->bridged_at->diffInSeconds(now()))
                    : 0,
                // Full lead on the live call, so the console can show who the
                // agent is actually talking to rather than just a name.
                'callId' => $call ? (string) $call->id : null,
                'lead' => $call?->contact?->toLeadPayload(),
                'thirdParty' => $call?->third_party_number,
            ];
        })->values();

        return response()->json([
            'session' => $session ? $this->serializeSession($session) : null,
            'agents' => $agentStates,
            'stats' => $this->statsFor($companyId, $session),
            'asteriskUp' => $this->ari->isUp(),
            'leadsRemaining' => $this->leadsRemaining($companyId, $session),
        ]);
    }

    private function agentStatus($agent, ?CallAttempt $call): string
    {
        if (! in_array($agent->status, ['available', 'online'], true)) {
            return 'offline';
        }
        if (! $call) {
            return 'available';
        }
        return match ($call->status) {
            'bridged' => 'connected',
            'answered', 'ringing', 'dialing' => 'ringing',
            default => 'available',
        };
    }

    public function start(Request $request)
    {
        $companyId = $this->companyId();
        if (! $companyId) {
            return response()->json(['message' => 'No active company'], 422);
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'concurrency' => 'nullable|integer|min:1|max:'.config('services.dialer.max_concurrency'),
            // Above ~1.5 this is blast dialing: more lines than agents, so
            // some answered calls have nobody to go to and are abandoned.
            // The ceiling is deliberately generous for testing; the
            // abandoned counter on the dashboard is what to watch in anger.
            'pacing_ratio' => 'nullable|numeric|min:0.5|max:20',
            'lead_tag' => 'nullable|string|max:255',
            'auto_close_at' => 'nullable|date',
        ]);

        if (! $this->ari->isUp()) {
            return response()->json([
                'message' => 'Asterisk is not reachable — start the container before opening a session.',
            ], 503);
        }

        // One live session per company keeps pacing math unambiguous.
        $existing = DialerSession::where('company_id', $companyId)
            ->whereIn('status', ['active', 'draining'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'A dialer session is already open.',
                'session' => $this->serializeSession($existing),
            ], 409);
        }

        $session = DialerSession::create([
            'company_id' => $companyId,
            'name' => $validated['name'] ?? 'Bulk Session '.now()->format('M j, H:i'),
            'status' => 'active',
            'concurrency' => $validated['concurrency'] ?? 2,
            'pacing_ratio' => $validated['pacing_ratio'] ?? 1.0,
            'caller_id' => config('services.dialer.caller_id'),
            'lead_tag' => $validated['lead_tag'] ?? null,
            'auto_close_at' => $validated['auto_close_at'] ?? null,
            'started_at' => now(),
        ]);

        return response()->json($this->serializeSession($session), 201);
    }

    /** Stop dialing new leads but let calls in progress finish. */
    public function drain()
    {
        $session = $this->liveSessionOrFail();
        if (! $session instanceof DialerSession) {
            return $session;
        }

        $session->update(['status' => 'draining']);
        return response()->json($this->serializeSession($session->fresh()));
    }

    /** Hard stop: hang up every live channel immediately. */
    public function stop()
    {
        $session = $this->liveSessionOrFail();
        if (! $session instanceof DialerSession) {
            return $session;
        }

        $live = $session->liveAttempts()->get();
        foreach ($live as $attempt) {
            foreach (array_filter([$attempt->channel_id, $attempt->agent_channel_id]) as $ch) {
                $this->ari->hangup($ch);
            }
            $attempt->update([
                'status' => 'abandoned',
                'hangup_cause' => 'session-stopped',
                'ended_at' => now(),
            ]);
        }

        $session->update(['status' => 'closed', 'ended_at' => now()]);
        return response()->json($this->serializeSession($session->fresh()));
    }

    private function liveSessionOrFail()
    {
        $companyId = $this->companyId();
        $session = DialerSession::where('company_id', $companyId)
            ->whereIn('status', ['active', 'draining'])
            ->latest('id')
            ->first();

        return $session ?: response()->json(['message' => 'No open dialer session.'], 404);
    }

    private function serializeSession(DialerSession $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'status' => $s->status,
            'concurrency' => $s->concurrency,
            'pacingRatio' => (float) $s->pacing_ratio,
            'callerId' => $s->caller_id,
            'leadTag' => $s->lead_tag,
            'startedAt' => $s->started_at?->toIso8601String(),
            'autoCloseAt' => $s->auto_close_at?->toIso8601String(),
            'endedAt' => $s->ended_at?->toIso8601String(),
        ];
    }

    private function statsFor(int $companyId, ?DialerSession $session): array
    {
        $scope = CallAttempt::where('company_id', $companyId)
            ->when($session, fn ($q) => $q->where('dialer_session_id', $session->id));

        $total = (clone $scope)->count();
        $answered = (clone $scope)->whereNotNull('answered_at')->count();
        $bridged = (clone $scope)->whereNotNull('bridged_at')->count();
        $live = (clone $scope)->whereIn('status', CallAttempt::LIVE_STATUSES)->count();
        $talkSeconds = (clone $scope)->sum('talk_duration');

        return [
            'dialed' => $total,
            'answered' => $answered,
            'connected' => $bridged,
            'live' => $live,
            'answerRate' => $total > 0 ? round($answered / $total * 100, 1) : 0.0,
            'talkSeconds' => (int) $talkSeconds,
            // Abandoned = lead picked up but no agent took it. Regulators care.
            'abandoned' => (clone $scope)->whereNotNull('answered_at')->whereNull('bridged_at')
                ->whereIn('status', CallAttempt::TERMINAL_STATUSES)->count(),
        ];
    }

    private function leadsRemaining(int $companyId, ?DialerSession $session): int
    {
        return Contact::where('company_id', $companyId)
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->when($session, function ($q) use ($session) {
                $q->whereNotExists(function ($sub) use ($session) {
                    $sub->select(DB::raw(1))
                        ->from('call_attempts')
                        ->whereColumn('call_attempts.contact_id', 'contacts.id')
                        ->where('call_attempts.dialer_session_id', $session->id);
                });
                if ($session->lead_tag) {
                    $q->whereJsonContains('tags', $session->lead_tag);
                }
            })
            ->count();
    }

    /**
     * Credentials the Agent Station uses to register its in-browser phone.
     *
     * SECURITY: this hands out a SIP password and is currently unauthenticated,
     * matching the rest of this API. Put it behind auth:sanctum and check that
     * the caller *is* $userId before this is exposed anywhere but localhost.
     */
    public function softphone(int $userId)
    {
        $companyId = $this->companyId();

        $row = DB::table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->first();

        if (! $row || ! $row->sip_extension) {
            return response()->json(['message' => 'No SIP extension assigned to this agent.'], 404);
        }

        // A remote-agent endpoint (contains "/") is dialed directly by Asterisk
        // and has no browser phone to register.
        if (str_contains($row->sip_extension, '/')) {
            return response()->json([
                'registerable' => false,
                'extension' => $row->sip_extension,
                'message' => 'This agent is configured as an external endpoint, not a browser phone.',
            ]);
        }

        return response()->json([
            'registerable' => true,
            'extension' => $row->sip_extension,
            'password' => config('services.asterisk.agent_password_prefix').$row->sip_extension
                .config('services.asterisk.agent_password_suffix'),
            'wsUrl' => config('services.asterisk.ws_url'),
            'realm' => config('services.asterisk.sip_realm'),
        ]);
    }

    /**
     * Stream a call recording.
     *
     * Asterisk writes into a host-mounted directory; we only ever join the
     * stored basename onto it and reject anything with a path separator, so
     * a crafted recording_name cannot walk out of that directory.
     */
    public function recording(int $id)
    {
        $attempt = CallAttempt::where('company_id', $this->companyId())->find($id);
        if (! $attempt) {
            return response()->json(['message' => 'Call not found.'], 404);
        }
        if (! $attempt->recording_name) {
            return response()->json(['message' => 'This call has no recording.'], 404);
        }

        $name = basename($attempt->recording_name);
        $format = preg_replace('/[^a-z0-9]/i', '', (string) ($attempt->recording_format ?: 'wav'));
        $path = rtrim(config('services.asterisk.recording_path'), '/').'/'.$name.'.'.$format;

        if (! is_file($path)) {
            return response()->json([
                'message' => 'Recording not found on disk yet.',
                'expectedAt' => $path,
                'status' => $attempt->recording_status,
            ], 404);
        }

        return response()->file($path, [
            'Content-Type' => $format === 'wav' ? 'audio/wav' : 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$name.'.'.$format.'"',
        ]);
    }

    /** Recent call history for the bulk dashboard feed. */
    public function attempts(Request $request)
    {
        $companyId = $this->companyId();

        $attempts = CallAttempt::with(['contact', 'agent'])
            ->where('company_id', $companyId)
            ->latest('id')
            ->limit((int) $request->query('limit', 50))
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'phone' => $a->phone,
                'contactName' => $a->contact?->name,
                'agentName' => $a->agent?->name,
                'status' => $a->status,
                'disposition' => $a->disposition,
                'hangupCause' => $a->hangup_cause,
                'duration' => $a->duration,
                'talkDuration' => $a->talk_duration,
                'dialedAt' => $a->dialed_at?->toIso8601String(),
                'endedAt' => $a->ended_at?->toIso8601String(),
                'direction' => $a->direction,
                'notes' => $a->notes,
                'hasRecording' => (bool) $a->recording_name,
                // Path, not an absolute URL: url() builds from APP_URL, which
                // points at the deployed host and is wrong for whichever API
                // base the client is actually talking to.
                'recordingPath' => $a->recording_name
                    ? "/dialer/attempts/{$a->id}/recording"
                    : null,
                'recordingStatus' => $a->recording_status,
            ]);

        return response()->json($attempts);
    }
}

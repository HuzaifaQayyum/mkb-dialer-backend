<?php

namespace App\Http\Controllers;

use App\Http\Middleware\TenantScopeMiddleware;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\Contact;
use App\Services\AsteriskAri;
use App\Services\DialerEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single-agent call control: pulling the next lead, pulling a third party
 * into the call, and handing the call to another agent.
 *
 * NOTE ON IDENTITY: this API has no authentication, so "the logged-in agent"
 * has to be told to us. Every endpoint here takes `agentId` (body) or an
 * X-Agent-ID header. Once auth lands, resolve it from the token instead and
 * drop the parameter — until then any caller can act as any agent.
 */
class AgentCallController extends Controller
{
    public function __construct(
        private AsteriskAri $ari,
        private DialerEngine $engine,
    ) {}

    private function companyId(): ?int
    {
        return TenantScopeMiddleware::$companyId;
    }

    private function agentId(Request $request): ?int
    {
        $id = $request->input('agentId') ?? $request->header('X-Agent-ID');
        return $id ? (int) $id : null;
    }

    /** The company_user row, which carries extension + active campaign. */
    private function membership(int $companyId, int $agentId)
    {
        return DB::table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', $agentId)
            ->first();
    }

    /**
     * Hand the agent their next lead.
     *
     * Claims it in the same transaction that selects it, so two agents asking
     * at the same moment can never be handed the same number.
     */
    public function nextCall(Request $request)
    {
        $companyId = $this->companyId();
        $agentId = $this->agentId($request);

        if (! $companyId) {
            return response()->json(['message' => 'No active company'], 422);
        }
        if (! $agentId) {
            return response()->json(['message' => 'agentId is required (body or X-Agent-ID header).'], 422);
        }

        $member = $this->membership($companyId, $agentId);
        if (! $member) {
            return response()->json(['message' => 'Agent is not a member of this company.'], 404);
        }

        // An explicit campaign wins; otherwise the agent's assigned campaign.
        $campaignId = $request->input('campaignId') ?? $member->active_campaign_id;

        if ($campaignId) {
            $campaign = Campaign::where('company_id', $companyId)->find($campaignId);
            if (! $campaign) {
                return response()->json(['message' => 'Campaign not found for this company.'], 404);
            }
            // An agent may only pull from a campaign they're assigned to.
            if (! $campaign->agents()->where('users.id', $agentId)->exists()) {
                return response()->json([
                    'message' => 'Agent is not assigned to this campaign.',
                ], 403);
            }
        }

        $cooldown = Carbon::now()->subHours((int) config('services.dialer.redial_cooldown_hours'));

        $result = DB::transaction(function () use ($companyId, $campaignId, $agentId, $cooldown) {
            $query = Contact::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->whereNotNull('phone')->where('phone', '!=', '')
                // Not dialed by anyone recently, and not currently claimed.
                ->whereNotExists(function ($q) use ($cooldown) {
                    $q->select(DB::raw(1))->from('call_attempts')
                        ->whereColumn('call_attempts.contact_id', 'contacts.id')
                        ->where(function ($w) use ($cooldown) {
                            $w->where('call_attempts.created_at', '>=', $cooldown)
                                ->orWhereIn('call_attempts.status', CallAttempt::LIVE_STATUSES);
                        });
                });

            // Campaign leads are walled off from the global list, both ways.
            $campaignId
                ? $query->where('campaign_id', $campaignId)
                : $query->whereNull('campaign_id');

            $lead = $query->orderByRaw('callback_date IS NULL, callback_date ASC')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $lead) {
                return null;
            }

            $attempt = CallAttempt::create([
                'company_id' => $companyId,
                'campaign_id' => $campaignId,
                'contact_id' => $lead->id,
                'user_id' => $agentId,
                'phone' => $this->engine->toE164($lead->phone) ?? $lead->phone,
                'direction' => 'outbound',
                'status' => 'queued',
            ]);

            return ['lead' => $lead, 'attempt' => $attempt];
        });

        if (! $result) {
            return response()->json([
                'message' => $campaignId
                    ? 'No leads left in this campaign.'
                    : 'No leads left in the contact list.',
                'lead' => null,
            ], 404);
        }

        return response()->json($result['lead']->toLeadPayload() + [
            'attemptId' => (string) $result['attempt']->id,
            'dialString' => $result['attempt']->phone,
        ]);
    }

    /**
     * Pull a third party into the agent's current call.
     *
     * The ring presents `caller_id` — the customer's number the agent is
     * already talking to — not the agent's line, so the third party sees a
     * number they recognise.
     */
    public function threeWay(Request $request)
    {
        $validated = $request->validate([
            'destination' => 'required_without:slot|nullable|string|max:32',
            'slot' => 'nullable|integer|min:1|max:6',
            'caller_id' => 'nullable|string|max:32',
            'customer_name' => 'nullable|string|max:255',
        ]);

        $companyId = $this->companyId();
        $agentId = $this->agentId($request);
        if (! $agentId) {
            return response()->json(['message' => 'agentId is required (body or X-Agent-ID header).'], 422);
        }

        $attempt = $this->liveCallFor($companyId, $agentId);
        if (! $attempt) {
            return response()->json(['message' => 'That agent is not on a live call.'], 409);
        }
        if ($attempt->third_party_channel_id) {
            return response()->json(['message' => 'A third party is already on this call.'], 409);
        }
        if ($attempt->status !== 'bridged' || ! $attempt->bridge_id) {
            // Distinguish "claimed a lead but hasn't dialed" from "connected",
            // otherwise the agent is told the call isn't bridged when in fact
            // there is no call at all.
            return response()->json([
                'message' => $attempt->status === 'queued'
                    ? 'That agent has a lead queued but is not on a connected call yet.'
                    : 'Wait for the call to connect before adding a third party.',
                'status' => $attempt->status,
            ], 409);
        }

        // A slot number resolves against the company's six admin-managed slots.
        $destination = $validated['destination'] ?? null;
        if (! $destination && ! empty($validated['slot'])) {
            $slots = $this->slotsFor($companyId);
            $destination = $slots[$validated['slot'] - 1] ?? null;
            if (! $destination) {
                return response()->json(['message' => "Slot {$validated['slot']} is empty."], 422);
            }
        }

        $isEndpoint = str_contains((string) $destination, '/');
        $e164 = $isEndpoint ? $destination : $this->engine->toE164($destination);
        if (! $e164) {
            return response()->json(['message' => "\"{$destination}\" is not a dialable number."], 422);
        }

        // Default the presented CID to the customer already on the call.
        $callerId = ($validated['caller_id'] ?? null) ?: $attempt->phone;

        $channelId = $this->ari->originateLead($e164, $callerId, [
            'MKB_ATTEMPT_ID' => (string) $attempt->id,
            'MKB_ROLE' => 'threeway',
            'MKB_BRIDGE_ID' => $attempt->bridge_id,
        ], 30, 'threeway');

        if (! $channelId) {
            return response()->json(['message' => 'Could not reach the third party — the carrier refused the call.'], 502);
        }

        $attempt->update([
            'third_party_channel_id' => $channelId,
            'third_party_number' => $e164,
        ]);

        return response()->json([
            'ok' => true,
            'attemptId' => (string) $attempt->id,
            'destination' => $e164,
            'callerId' => $callerId,
            'channelId' => $channelId,
            'customerName' => $validated['customer_name'] ?? null,
        ], 201);
    }

    /** Hang up only the third party; agent and customer stay connected. */
    public function threeWayDrop(Request $request)
    {
        $companyId = $this->companyId();
        $agentId = $this->agentId($request);
        if (! $agentId) {
            return response()->json(['message' => 'agentId is required (body or X-Agent-ID header).'], 422);
        }

        $attempt = $this->liveCallFor($companyId, $agentId);
        if (! $attempt || ! $attempt->third_party_channel_id) {
            return response()->json(['message' => 'No third party on this call.'], 404);
        }

        $this->ari->hangup($attempt->third_party_channel_id);
        $attempt->update(['third_party_channel_id' => null, 'third_party_number' => null]);

        return response()->json(['ok' => true]);
    }

    /**
     * Hand the live call to another agent. The current agent drops off; the
     * customer stays up throughout.
     */
    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'agentId' => 'required|integer',   // the agent receiving the call
            'callId' => 'nullable',
            'fromAgentId' => 'nullable|integer',
        ]);

        $companyId = $this->companyId();
        $toAgentId = (int) $validated['agentId'];
        // validate() omits absent nullable keys, so these must be defaulted.
        $fromAgentId = (int) ($validated['fromAgentId'] ?? $request->header('X-Agent-ID') ?? 0);

        $attempt = ! empty($validated['callId'])
            ? CallAttempt::where('company_id', $companyId)->find($validated['callId'])
            : ($fromAgentId ? $this->liveCallFor($companyId, $fromAgentId) : null);

        if (! $attempt || ! $attempt->isLive()) {
            return response()->json(['message' => 'No live call to transfer.'], 409);
        }
        if (! $attempt->bridge_id) {
            return response()->json(['message' => 'This call is not in a bridge yet.'], 409);
        }
        if ($toAgentId === (int) $attempt->user_id) {
            return response()->json(['message' => 'That call is already with this agent.'], 422);
        }

        $target = $this->membership($companyId, $toAgentId);
        if (! $target || ! $target->sip_extension) {
            return response()->json(['message' => 'Target agent has no SIP extension.'], 404);
        }
        if ($this->liveCallFor($companyId, $toAgentId)) {
            return response()->json(['message' => 'Target agent is already on a call.'], 409);
        }

        $channelId = $this->ari->originateAgent(
            $target->sip_extension,
            $attempt->phone,
            [
                'MKB_ATTEMPT_ID' => (string) $attempt->id,
                'MKB_ROLE' => 'transfer',
                'MKB_BRIDGE_ID' => $attempt->bridge_id,
                'MKB_DROP_CHANNEL' => (string) $attempt->agent_channel_id,
            ],
            30,
            'transfer'
        );

        if (! $channelId) {
            return response()->json(['message' => 'Could not ring the target agent.'], 502);
        }

        // Ownership moves now; core drops the old leg once the new one joins.
        $attempt->update(['user_id' => $toAgentId]);

        return response()->json([
            'ok' => true,
            'attemptId' => (string) $attempt->id,
            'toAgentId' => (string) $toAgentId,
            'channelId' => $channelId,
        ]);
    }

    /** The six admin-managed 3-way slot numbers for this company. */
    public function getSlots()
    {
        return response()->json($this->slotsFor($this->companyId()));
    }

    public function putSlots(Request $request)
    {
        $companyId = $this->companyId();
        $company = Company::find($companyId);
        if (! $company) {
            return response()->json(['message' => 'No active company'], 422);
        }

        // Accept a bare array body or { slots: [...] }.
        $slots = $request->input('slots', $request->json()->all());
        if (! is_array($slots)) {
            return response()->json(['message' => 'Expected an array of 6 numbers.'], 422);
        }

        $clean = [];
        for ($i = 0; $i < 6; $i++) {
            $raw = trim((string) ($slots[$i] ?? ''));
            // Empty clears the slot; anything else must be dialable.
            $clean[] = $raw === '' ? '' : ($this->engine->toE164($raw) ?? $raw);
        }

        $company->update(['three_way_slots' => $clean]);

        return response()->json($clean);
    }

    private function slotsFor(?int $companyId): array
    {
        $stored = $companyId ? (Company::find($companyId)?->three_way_slots ?? []) : [];
        $stored = is_array($stored) ? $stored : [];

        return array_slice(array_pad($stored, 6, ''), 0, 6);
    }

    private function liveCallFor(?int $companyId, int $agentId): ?CallAttempt
    {
        return CallAttempt::where('company_id', $companyId)
            ->where('user_id', $agentId)
            ->whereIn('status', CallAttempt::LIVE_STATUSES)
            ->latest('id')
            ->first();
    }
}

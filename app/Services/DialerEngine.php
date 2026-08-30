<?php

namespace App\Services;

use App\Models\CallAttempt;
use App\Models\Contact;
use App\Models\DialerSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The pacing brain.
 *
 * tick() is called roughly once a second by `php artisan dialer:work`.
 * Every tick it: reaps calls Asterisk no longer knows about, closes
 * sessions whose grace period expired, and originates new leads up to
 * the pacing budget.
 */
class DialerEngine
{
    public function __construct(private AsteriskAri $ari) {}

    public function tick(): array
    {
        $summary = ['reaped' => 0, 'dialed' => 0, 'closed' => 0];

        $sessions = DialerSession::whereIn('status', ['active', 'draining'])->get();
        if ($sessions->isEmpty()) {
            return $summary;
        }

        $summary['reaped'] = $this->reapStaleAttempts();

        foreach ($sessions as $session) {
            if ($session->status === 'active') {
                if ($session->auto_close_at && now()->greaterThanOrEqualTo($session->auto_close_at)) {
                    $session->update(['status' => 'draining']);
                    $session->refresh();
                } else {
                    $summary['dialed'] += $this->paceSession($session);
                }
            }

            // A draining session dials nothing and closes once its lines clear.
            if ($session->status === 'draining' && $session->liveAttempts()->count() === 0) {
                $session->update(['status' => 'closed', 'ended_at' => now()]);
                $summary['closed']++;
            }
        }

        return $summary;
    }

    /**
     * Originate up to the pacing budget for one session.
     *
     * Budget = min(concurrency, floor(free agents * pacing_ratio)) - live calls.
     * Tying the budget to free agents is what stops us abandoning answered
     * calls with nobody to hand them to.
     */
    private function paceSession(DialerSession $session): int
    {
        $liveCount = $session->liveAttempts()->count();

        $freeAgents = $this->freeAgents($session);
        if ($freeAgents->isEmpty()) {
            return 0;
        }

        $ceiling = min(
            $session->concurrency,
            (int) config('services.dialer.max_concurrency'),
            (int) floor($freeAgents->count() * max($session->pacing_ratio, 0.1))
        );

        $budget = $ceiling - $liveCount;
        if ($budget < 1) {
            return 0;
        }

        $leads = $this->nextLeads($session, $budget);
        if ($leads->isEmpty()) {
            return 0;
        }

        $callerId = $session->caller_id ?: config('services.dialer.caller_id');
        $dialed = 0;

        foreach ($leads as $lead) {
            $e164 = $this->toE164($lead->phone);
            if (! $e164) {
                Log::info('Skipping unusable lead phone', ['contact' => $lead->id, 'phone' => $lead->phone]);
                continue;
            }

            // Insert first so a fast StasisStart always finds a row to update.
            $attempt = CallAttempt::create([
                'company_id' => $session->company_id,
                'dialer_session_id' => $session->id,
                'contact_id' => $lead->id,
                'phone' => $e164,
                'direction' => 'outbound',
                'status' => 'dialing',
                'dialed_at' => now(),
            ]);

            $channelId = $this->ari->originateLead(
                $e164,
                $callerId,
                [
                    'MKB_ATTEMPT_ID' => (string) $attempt->id,
                    'MKB_SESSION_ID' => (string) $session->id,
                ],
                (int) config('services.dialer.lead_timeout')
            );

            if (! $channelId) {
                $attempt->update([
                    'status' => 'failed',
                    'hangup_cause' => 'originate-rejected',
                    'ended_at' => now(),
                ]);
                continue;
            }

            $attempt->update(['status' => 'ringing', 'channel_id' => $channelId]);
            $dialed++;
        }

        return $dialed;
    }

    /**
     * Agents eligible to receive a bridged call: online, queue-eligible,
     * holding a SIP extension, and not already on a live call.
     */
    public function freeAgents(DialerSession $session)
    {
        $busyUserIds = CallAttempt::where('dialer_session_id', $session->id)
            ->whereIn('status', CallAttempt::LIVE_STATUSES)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->all();

        return DB::table('company_user')
            ->where('company_id', $session->company_id)
            ->where('queue_eligible', true)
            ->whereNotNull('sip_extension')
            ->whereIn('status', ['available', 'online'])
            ->when($busyUserIds, fn ($q) => $q->whereNotIn('user_id', $busyUserIds))
            ->get();
    }

    /** Contacts not yet dialed in this session and outside the redial cooldown. */
    private function nextLeads(DialerSession $session, int $limit)
    {
        $cooldown = Carbon::now()->subHours((int) config('services.dialer.redial_cooldown_hours'));

        return Contact::where('company_id', $session->company_id)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            // never attempted inside this session
            ->whereNotExists(function ($q) use ($session) {
                $q->select(DB::raw(1))
                    ->from('call_attempts')
                    ->whereColumn('call_attempts.contact_id', 'contacts.id')
                    ->where('call_attempts.dialer_session_id', $session->id);
            })
            // and not recently dialed by any session
            ->whereNotExists(function ($q) use ($cooldown) {
                $q->select(DB::raw(1))
                    ->from('call_attempts')
                    ->whereColumn('call_attempts.contact_id', 'contacts.id')
                    ->where('call_attempts.created_at', '>=', $cooldown);
            })
            ->when($session->lead_tag, function ($q) use ($session) {
                $q->whereJsonContains('tags', $session->lead_tag);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Close out rows whose channel Asterisk has forgotten.
     *
     * Without this a dropped ARI event would pin a line as "live" forever
     * and the pacer would slowly starve itself down to zero budget.
     */
    public function reapStaleAttempts(): int
    {
        $stale = CallAttempt::whereIn('status', CallAttempt::LIVE_STATUSES)
            ->where('updated_at', '<', now()->subSeconds(120))
            ->get();

        if ($stale->isEmpty()) {
            return 0;
        }

        $active = $this->ari->activeChannelIds();
        $reaped = 0;

        foreach ($stale as $attempt) {
            if ($attempt->channel_id && in_array($attempt->channel_id, $active, true)) {
                continue; // genuinely still up
            }
            $attempt->update([
                'status' => $attempt->answered_at ? 'completed' : 'failed',
                'hangup_cause' => $attempt->hangup_cause ?: 'reaped-stale',
                'ended_at' => $attempt->ended_at ?: now(),
            ]);
            $reaped++;
        }

        return $reaped;
    }

    /** US-centric E.164 normalisation; returns null when unusable. */
    public function toE164(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $raw);
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($raw, '+')) {
            return strlen($digits) >= 8 ? '+'.$digits : null;
        }
        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return strlen($digits) >= 8 ? '+'.$digits : null;
    }
}

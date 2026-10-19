<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client over Asterisk's ARI REST interface.
 *
 * Only the synchronous verbs live here (originate / hangup / inspect).
 * The asynchronous Stasis event stream is a WebSocket and is held by
 * mkb-dialer-core, which posts events back to /api/dialer/events.
 */
class AsteriskAri
{
    private string $base;
    private string $user;
    private string $pass;
    private string $app;

    public function __construct()
    {
        $this->base = rtrim(config('services.asterisk.url'), '/');
        $this->user = config('services.asterisk.user');
        $this->pass = config('services.asterisk.password');
        $this->app  = config('services.asterisk.app');
    }

    private function request()
    {
        return Http::withBasicAuth($this->user, $this->pass)
            ->timeout(10)
            ->acceptJson();
    }

    public function isUp(): bool
    {
        try {
            return $this->request()->get("{$this->base}/ari/asterisk/info")->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Dial a PSTN number out of the Telnyx trunk and drop the answered
     * channel into our Stasis app so core can bridge it to an agent.
     *
     * Returns the Asterisk channel id, or null when the originate is
     * refused outright (trunk down, bad number, ARI unreachable).
     */
    public function originateLead(
        string $e164,
        string $callerId,
        array $vars = [],
        int $timeout = 30,
        string $role = 'lead',
    ): ?string {
        $trunk  = config('services.asterisk.trunk');
        $domain = config('services.asterisk.sip_domain');
        $endpoint = str_contains($e164, '/')
            ? $e164
            : "PJSIP/{$trunk}/sip:{$e164}@{$domain}";

        try {
            $res = $this->request()->post("{$this->base}/ari/channels", [
                'endpoint' => $endpoint,
                'app' => $this->app,
                // Tells core what to do with the channel when it answers:
                // bridge it as the lead, or join an existing bridge.
                'appArgs' => $role,
                'callerId' => $callerId,
                'timeout' => $timeout,
                'variables' => $vars,
            ]);

            if ($res->successful()) {
                return $res->json('id');
            }

            Log::warning('ARI originate rejected', [
                'endpoint' => $endpoint,
                'status' => $res->status(),
                'body' => $res->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('ARI originate failed: '.$e->getMessage(), ['endpoint' => $endpoint]);
        }

        return null;
    }

    /** Ring an agent's registered softphone into Stasis so it can be bridged. */
    public function originateAgent(
        string $extension,
        string $callerId,
        array $vars = [],
        int $timeout = 25,
        string $role = 'agent',
    ): ?string {
        // A bare extension is a registered phone; anything containing "/" is
        // passed through verbatim, which is how a remote agent on a mobile works.
        $endpoint = str_contains($extension, '/') ? $extension : "PJSIP/{$extension}";

        try {
            $res = $this->request()->post("{$this->base}/ari/channels", [
                'endpoint' => $endpoint,
                'app' => $this->app,
                'appArgs' => $role,
                'callerId' => $callerId,
                'timeout' => $timeout,
                'variables' => $vars,
            ]);

            if ($res->successful()) {
                return $res->json('id');
            }

            Log::warning('ARI agent originate rejected', [
                'extension' => $extension,
                'status' => $res->status(),
                'body' => $res->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('ARI agent originate failed: '.$e->getMessage());
        }

        return null;
    }

    public function hangup(string $channelId): bool
    {
        try {
            return $this->request()->delete("{$this->base}/ari/channels/{$channelId}")->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Channel ids Asterisk currently knows about — used to reconcile stale rows. */
    public function activeChannelIds(): array
    {
        try {
            $res = $this->request()->get("{$this->base}/ari/channels");
            if (! $res->successful()) {
                return [];
            }
            return array_column($res->json() ?: [], 'id');
        } catch (\Throwable $e) {
            return [];
        }
    }
}

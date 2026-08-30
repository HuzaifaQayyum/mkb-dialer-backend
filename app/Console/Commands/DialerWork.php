<?php

namespace App\Console\Commands;

use App\Services\AsteriskAri;
use App\Services\DialerEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Long-running pacing daemon.
 *
 * Laravel's scheduler only resolves to the minute, which is far too coarse
 * for a dialer, so this runs as its own always-on process alongside
 * `queue:work` — start it with `php artisan dialer:work`.
 */
class DialerWork extends Command
{
    protected $signature = 'dialer:work
                            {--interval=1 : Seconds between pacing ticks}
                            {--once : Run a single tick and exit (useful for tests)}';

    protected $description = 'Run the bulk dialer pacing loop';

    public function handle(DialerEngine $engine, AsteriskAri $ari): int
    {
        if (! $ari->isUp()) {
            $this->error('Asterisk ARI is unreachable at '.config('services.asterisk.url'));
            $this->line('Start it with: docker start asterisk');
            return self::FAILURE;
        }

        $interval = max(1, (int) $this->option('interval'));

        if ($this->option('once')) {
            $this->renderTick($engine->tick());
            return self::SUCCESS;
        }

        $this->info('Dialer pacing loop started (interval '.$interval.'s). Ctrl+C to stop.');

        // Let a Ctrl+C finish the current tick instead of tearing out mid-originate.
        $running = true;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use (&$running) { $running = false; });
            pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
        }

        while ($running) {
            try {
                $result = $engine->tick();
                if (array_sum($result) > 0) {
                    $this->renderTick($result);
                }
            } catch (\Throwable $e) {
                // A bad tick must never kill the daemon.
                Log::error('Dialer tick failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
                $this->error('tick error: '.$e->getMessage());
            }

            sleep($interval);
        }

        $this->info('Dialer loop stopped.');
        return self::SUCCESS;
    }

    private function renderTick(array $r): void
    {
        $this->line(sprintf(
            '[%s] dialed=%d reaped=%d closed=%d',
            now()->format('H:i:s'),
            $r['dialed'],
            $r['reaped'],
            $r['closed']
        ));
    }
}

<?php

namespace Consys\Ecosystem\Monitor;

use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Hooked into the host's exception handler: sends each reported exception to the admin
 * through the queue, after the exceptions Laravel does not report (404, validation...)
 * were left out. When the queue cannot take it, such as with the database down, the report
 * goes straight away. It never lets its own failure reach the handler.
 */
class ErrorReporter
{
    private static bool $reporting = false;

    public static function enabled(): bool
    {
        return (bool) config('consys.monitor.report_errors')
            && filled(config('consys.directory.admin_url'))
            && filled(config('consys.directory.secret'))
            && filled(config('consys.directory.application'));
    }

    public function report(Throwable $exception): void
    {
        if (self::$reporting || ! self::enabled()) {
            return;
        }

        self::$reporting = true;

        try {
            $report = ErrorReport::fromThrowable($exception);

            if ($this->throttled($report->fingerprint)) {
                return;
            }

            $this->send($report->toArray());
        } catch (Throwable) {
        } finally {
            self::$reporting = false;
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function send(array $report): void
    {
        try {
            SendErrorReport::dispatch($report);
        } catch (Throwable) {
            (new SendErrorReport($report))->deliver();
        }
    }

    private function throttled(string $fingerprint): bool
    {
        try {
            $key = 'consys-monitor-error:'.$fingerprint;

            if (RateLimiter::tooManyAttempts($key, (int) config('consys.monitor.errors_per_minute', 10))) {
                return true;
            }

            RateLimiter::hit($key, 60);
        } catch (Throwable) {
        }

        return false;
    }
}

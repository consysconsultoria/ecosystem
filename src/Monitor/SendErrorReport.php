<?php

namespace Consys\Ecosystem\Monitor;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Delivers one error report to the admin. It never throws: an exception here would be
 * reported again and could loop, so a failed delivery is retried a few times and then
 * dropped.
 */
class SendErrorReport implements ShouldQueue
{
    use Queueable;

    public const int MAX_ATTEMPTS = 3;

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(public array $report) {}

    public function handle(): void
    {
        if ($this->deliver()) {
            return;
        }

        if ($this->job !== null && $this->attempts() < self::MAX_ATTEMPTS) {
            $this->release(30 * $this->attempts());
        }
    }

    public function deliver(): bool
    {
        try {
            return Http::connectTimeout(3)
                ->timeout(5)
                ->withToken((string) config('consys.directory.secret'))
                ->acceptJson()
                ->post(rtrim((string) config('consys.directory.admin_url'), '/').'/api/v1/monitor/errors', [
                    'application' => (string) config('consys.directory.application'),
                    ...$this->report,
                ])
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}

<?php

namespace Consys\Ecosystem\Monitor;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * The state of the host app the admin reads every minute. Each check stands on its own:
 * one that fails reports its error and the others still answer.
 */
class HealthReport
{
    public const string SCHEDULER_HEARTBEAT_KEY = 'consys:monitor:scheduler-heartbeat';

    public function __construct(private ServerMetrics $server) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        return [
            'application' => (string) config('consys.directory.application'),
            'checked_at' => now()->toIso8601String(),
            'app' => [
                'environment' => app()->environment(),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'version' => Release::version(),
                'deployed_at' => Release::deployedAt()?->toIso8601String(),
                'maintenance' => app()->isDownForMaintenance(),
                'storage_writable' => is_writable(storage_path('framework')),
            ],
            'database' => $this->database(),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'server' => $this->server->collect(),
        ];
    }

    public static function recordSchedulerHeartbeat(): void
    {
        Cache::forever(self::SCHEDULER_HEARTBEAT_KEY, now()->timestamp);
    }

    /**
     * @return array{ok: bool, driver: ?string, latency_ms: ?int, error: ?string}
     */
    private function database(): array
    {
        $startedAt = hrtime(true);

        try {
            $connection = DB::connection();
            $connection->select('select 1');

            return ['ok' => true, 'driver' => $connection->getDriverName(), 'latency_ms' => $this->elapsedMilliseconds($startedAt), 'error' => null];
        } catch (Throwable $exception) {
            return ['ok' => false, 'driver' => null, 'latency_ms' => null, 'error' => $this->errorOf($exception)];
        }
    }

    /**
     * @return array{driver: ?string, pending: ?int, oldest_wait_seconds: ?int, failed_last_day: ?int, error: ?string}
     */
    private function queue(): array
    {
        $name = (string) config('queue.default');
        $connection = (array) config("queue.connections.{$name}", []);
        $driver = $connection['driver'] ?? null;

        try {
            [$pending, $oldestWait] = match ($driver) {
                'database' => $this->databaseQueue($connection),
                'sync', 'null' => [0, null],
                default => [Queue::connection($name)->size(), null],
            };

            return ['driver' => $driver, 'pending' => $pending, 'oldest_wait_seconds' => $oldestWait, 'failed_last_day' => $this->failedLastDay(), 'error' => null];
        } catch (Throwable $exception) {
            return ['driver' => $driver, 'pending' => null, 'oldest_wait_seconds' => null, 'failed_last_day' => null, 'error' => $this->errorOf($exception)];
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array{0: int, 1: ?int}
     */
    private function databaseQueue(array $connection): array
    {
        $now = now()->timestamp;

        $jobs = DB::connection($connection['connection'] ?? null)
            ->table($connection['table'] ?? 'jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now);

        $oldestAvailableAt = (clone $jobs)->min('available_at');

        return [$jobs->count(), $oldestAvailableAt === null ? null : max(0, $now - (int) $oldestAvailableAt)];
    }

    private function failedLastDay(): ?int
    {
        if (! in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            return null;
        }

        return DB::connection(config('queue.failed.database'))
            ->table((string) config('queue.failed.table', 'failed_jobs'))
            ->where('failed_at', '>=', now()->subDay())
            ->count();
    }

    /**
     * @return array{last_run_at: ?string, error: ?string}
     */
    private function scheduler(): array
    {
        try {
            $lastRun = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);

            return ['last_run_at' => is_numeric($lastRun) ? now()->setTimestamp((int) $lastRun)->toIso8601String() : null, 'error' => null];
        } catch (Throwable $exception) {
            return ['last_run_at' => null, 'error' => $this->errorOf($exception)];
        }
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function errorOf(Throwable $exception): string
    {
        return mb_substr(class_basename($exception).': '.$exception->getMessage(), 0, 300);
    }
}

<?php

use Consys\Ecosystem\Monitor\HealthReport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

function requestHealth(string $secret = 'directory-secret', ?int $timestamp = null): TestResponse
{
    $timestamp = (string) ($timestamp ?? now()->timestamp);

    return test()->get('/consys/health', [
        'Accept' => 'application/json',
        'X-Consys-Timestamp' => $timestamp,
        'X-Consys-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.', $secret),
    ]);
}

it('answers the admin with the state of the app and of its server', function (): void {
    requestHealth()
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('application', 'erp')
        ->assertJsonPath('database.ok', true)
        ->assertJsonPath('queue.driver', 'sync')
        ->assertJsonPath('queue.pending', 0)
        ->assertJsonPath('app.maintenance', false)
        ->assertJsonPath('app.php', PHP_VERSION)
        ->assertJsonStructure([
            'checked_at',
            'app' => ['environment', 'laravel', 'version', 'deployed_at', 'storage_writable'],
            'database' => ['driver', 'latency_ms', 'error'],
            'queue' => ['oldest_wait_seconds', 'failed_last_day', 'error'],
            'scheduler' => ['last_run_at'],
            'server' => ['hostname', 'cpus', 'cpu_used_percent', 'load', 'memory_total', 'memory_available', 'disk_total', 'disk_free', 'uptime_seconds'],
        ]);
});

it('refuses a health request that the admin did not sign', function (): void {
    requestHealth(secret: 'forged-secret')->assertUnauthorized();
    requestHealth(timestamp: now()->subMinutes(10)->timestamp)->assertUnauthorized();
    $this->get('/consys/health', ['Accept' => 'application/json'])->assertUnauthorized();
});

it('reports the jobs waiting in a database queue and how long the oldest waits', function (): void {
    config(['queue.default' => 'database', 'queue.failed.driver' => 'database-uuids']);

    DB::table('jobs')->insert([
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(6)->timestamp, 'created_at' => now()->subMinutes(6)->timestamp],
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinute()->timestamp, 'created_at' => now()->subMinute()->timestamp],
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->addHour()->timestamp, 'created_at' => now()->timestamp],
    ]);

    DB::table('failed_jobs')->insert([
        ['uuid' => 'a', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subHours(2)],
        ['uuid' => 'b', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(3)],
    ]);

    requestHealth()
        ->assertOk()
        ->assertJsonPath('queue.driver', 'database')
        ->assertJsonPath('queue.pending', 2)
        ->assertJsonPath('queue.oldest_wait_seconds', 360)
        ->assertJsonPath('queue.failed_last_day', 1);
})->freezeSecond();

it('keeps answering when one of the checks fails', function (): void {
    config(['queue.default' => 'database', 'queue.connections.database.table' => 'missing_jobs']);

    requestHealth()
        ->assertOk()
        ->assertJsonPath('database.ok', true)
        ->assertJsonPath('queue.pending', null)
        ->assertJson(fn ($json) => $json->where('queue.error', fn (?string $error): bool => str_contains((string) $error, 'QueryException'))->etc());
});

it('records the scheduler heartbeat every minute so the admin sees when the cron stops', function (): void {
    requestHealth()->assertJsonPath('scheduler.last_run_at', null);

    $heartbeat = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'consys:monitor-heartbeat');

    expect($heartbeat)->not->toBeNull()
        ->and($heartbeat->expression)->toBe('* * * * *');

    $heartbeat->run(app());

    expect(Cache::get(HealthReport::SCHEDULER_HEARTBEAT_KEY))->toBe(now()->timestamp);
    requestHealth()->assertJsonPath('scheduler.last_run_at', now()->toIso8601String());
})->freezeSecond();

it('answers during maintenance and says so', function (): void {
    $this->artisan('down');

    try {
        requestHealth()->assertOk()->assertJsonPath('app.maintenance', true);
    } finally {
        $this->artisan('up');
    }
});

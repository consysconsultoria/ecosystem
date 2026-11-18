<?php

use Consys\Ecosystem\Monitor\ServerMetrics;
use Illuminate\Support\Facades\Cache;

function fakeProc(string $stat): string
{
    $directory = sys_get_temp_dir().'/consys-proc-'.getmypid();

    if (! is_dir($directory)) {
        mkdir($directory);
    }

    file_put_contents("{$directory}/stat", "cpu  {$stat}\ncpu0 {$stat}\n");
    file_put_contents("{$directory}/cpuinfo", "processor\t: 0\nprocessor\t: 1\n");

    return $directory;
}

it('measures the cpu usage since the previous report', function (): void {
    Cache::put(ServerMetrics::CPU_SAMPLE_KEY, ['total' => 1000, 'idle' => 760], now()->addMinutes(10));

    $server = (new ServerMetrics(fakeProc('300 0 100 700 100 0 0 0 0 0')))->collect();

    expect($server['cpus'])->toBe(2)
        ->and($server['cpu_used_percent'])->toBe(80.0)
        ->and(Cache::get(ServerMetrics::CPU_SAMPLE_KEY))->toBe(['total' => 1200, 'idle' => 800]);
});

it('leaves the cpu usage empty where the server has no proc files', function (): void {
    expect((new ServerMetrics(sys_get_temp_dir().'/consys-no-proc'))->collect()['cpu_used_percent'])->toBeNull();
});

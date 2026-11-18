<?php

namespace Consys\Ecosystem\Monitor;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What the server the host runs on reports about itself, read without any extension or
 * shell command. Outside Linux the values that come from /proc are null. The CPU usage is
 * the share of busy time since the previous report, or over a short sample on the first.
 */
final class ServerMetrics
{
    public const string CPU_SAMPLE_KEY = 'consys:monitor:cpu-sample';

    private const int CPU_SAMPLE_MICROSECONDS = 250_000;

    public function __construct(private string $procPath = '/proc') {}

    /**
     * @return array{hostname: string, cpus: ?int, cpu_used_percent: ?float, load: ?array{0: float, 1: float, 2: float}, memory_total: ?int, memory_available: ?int, disk_total: ?int, disk_free: ?int, uptime_seconds: ?int}
     */
    public function collect(): array
    {
        $memory = $this->memory();
        $diskTotal = @disk_total_space(base_path());
        $diskFree = @disk_free_space(base_path());

        return [
            'hostname' => (string) gethostname(),
            'cpus' => $this->cpus(),
            'cpu_used_percent' => $this->cpuUsedPercent(),
            'load' => $this->load(),
            'memory_total' => $memory['MemTotal'] ?? null,
            'memory_available' => $memory['MemAvailable'] ?? null,
            'disk_total' => $diskTotal === false ? null : (int) $diskTotal,
            'disk_free' => $diskFree === false ? null : (int) $diskFree,
            'uptime_seconds' => $this->uptime(),
        ];
    }

    /**
     * @return ?array{0: float, 1: float, 2: float}
     */
    private function load(): ?array
    {
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;

        return is_array($load) && count($load) === 3 ? array_map(fn (float $value): float => round($value, 2), $load) : null;
    }

    private function cpuUsedPercent(): ?float
    {
        $current = $this->cpuTimes();

        if ($current === null) {
            return null;
        }

        $previous = $this->rememberCpuTimes($current);

        if ($previous === null || $current['total'] <= $previous['total']) {
            usleep(self::CPU_SAMPLE_MICROSECONDS);
            $previous = $current;
            $current = $this->cpuTimes() ?? $current;
            $this->rememberCpuTimes($current);
        }

        $total = $current['total'] - $previous['total'];
        $idle = $current['idle'] - $previous['idle'];

        return $total > 0 ? round(max(0, min(100, 100 * (1 - $idle / $total))), 1) : null;
    }

    /**
     * @return ?array{total: int, idle: int}
     */
    private function cpuTimes(): ?array
    {
        if (! preg_match('/^cpu\s+([\d ]+)$/m', $this->read('stat') ?? '', $match)) {
            return null;
        }

        $times = array_map('intval', preg_split('/\s+/', trim($match[1])));

        if (count($times) < 4) {
            return null;
        }

        return ['total' => array_sum(array_slice($times, 0, 8)), 'idle' => $times[3] + ($times[4] ?? 0)];
    }

    /**
     * @param  array{total: int, idle: int}  $times
     * @return ?array{total: int, idle: int}
     */
    private function rememberCpuTimes(array $times): ?array
    {
        try {
            $previous = Cache::get(self::CPU_SAMPLE_KEY);
            Cache::put(self::CPU_SAMPLE_KEY, $times, now()->addMinutes(10));

            return is_array($previous) && isset($previous['total'], $previous['idle']) ? $previous : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function cpus(): ?int
    {
        $cpuInfo = $this->read('cpuinfo');

        if ($cpuInfo === null) {
            return null;
        }

        $count = preg_match_all('/^processor\s*:/m', $cpuInfo);

        return $count > 0 ? $count : null;
    }

    /**
     * @return array<string, int> bytes by /proc/meminfo key
     */
    private function memory(): array
    {
        preg_match_all('/^(\w+):\s+(\d+) kB$/m', $this->read('meminfo') ?? '', $matches, PREG_SET_ORDER);

        $memory = [];

        foreach ($matches as $match) {
            $memory[$match[1]] = (int) $match[2] * 1024;
        }

        return $memory;
    }

    private function uptime(): ?int
    {
        $uptime = $this->read('uptime');

        return $uptime === null ? null : (int) explode(' ', $uptime)[0];
    }

    private function read(string $file): ?string
    {
        $path = "{$this->procPath}/{$file}";

        if (! @is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}

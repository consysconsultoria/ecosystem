<?php

namespace Consys\Ecosystem\Monitor;

/**
 * What the server the host runs on reports about itself, read without any extension or
 * shell command. Outside Linux the values that come from /proc are null.
 */
final class ServerMetrics
{
    /**
     * @return array{hostname: string, cpus: ?int, load: ?array{0: float, 1: float, 2: float}, memory_total: ?int, memory_available: ?int, disk_total: ?int, disk_free: ?int, uptime_seconds: ?int}
     */
    public function collect(): array
    {
        $memory = $this->memory();
        $diskTotal = @disk_total_space(base_path());
        $diskFree = @disk_free_space(base_path());

        return [
            'hostname' => (string) gethostname(),
            'cpus' => $this->cpus(),
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

    private function cpus(): ?int
    {
        $cpuInfo = $this->read('/proc/cpuinfo');

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
        preg_match_all('/^(\w+):\s+(\d+) kB$/m', $this->read('/proc/meminfo') ?? '', $matches, PREG_SET_ORDER);

        $memory = [];

        foreach ($matches as $match) {
            $memory[$match[1]] = (int) $match[2] * 1024;
        }

        return $memory;
    }

    private function uptime(): ?int
    {
        $uptime = $this->read('/proc/uptime');

        return $uptime === null ? null : (int) explode(' ', $uptime)[0];
    }

    private function read(string $path): ?string
    {
        if (! @is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}

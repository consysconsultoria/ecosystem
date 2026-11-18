<?php

namespace Consys\Ecosystem\Monitor;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The heavier picture the admin asks for now and then, apart from the minute check: the
 * system the app runs on, its database with the size of each table, and its latest log.
 */
final class HealthDetails
{
    public const int MAX_TABLES = 60;

    public const int LOG_TAIL_LINES = 80;

    private const int LOG_TAIL_BYTES = 131_072;

    private const int LOG_LINE_LENGTH = 1_000;

    public function __construct(private string $osReleasePath = '/etc/os-release') {}

    /**
     * @return array{system: array<string, mixed>, database: array<string, mixed>, logs: array<string, mixed>}
     */
    public function collect(): array
    {
        return [
            'system' => $this->system(),
            'database' => $this->database(),
            'logs' => $this->logs(),
        ];
    }

    /**
     * @return array{os: ?string, kernel: string, php: string, php_extensions: int}
     */
    private function system(): array
    {
        $release = @is_readable($this->osReleasePath) ? (string) @file_get_contents($this->osReleasePath) : '';

        return [
            'os' => preg_match('/^PRETTY_NAME="?([^"\n]+)"?$/m', $release, $match) ? $match[1] : php_uname('s'),
            'kernel' => php_uname('r'),
            'php' => PHP_VERSION,
            'php_extensions' => count(get_loaded_extensions()),
        ];
    }

    /**
     * @return array{driver: ?string, version: ?string, name: ?string, size: ?int, tables: list<array{name: string, size: ?int}>, error: ?string}
     */
    private function database(): array
    {
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();

            $tables = collect(Schema::getTables())
                ->map(fn (array $table): array => ['name' => (string) $table['name'], 'size' => isset($table['size']) ? (int) $table['size'] : null])
                ->sortByDesc('size')
                ->values();

            $size = $driver === 'sqlite'
                ? (@filesize((string) $connection->getConfig('database')) ?: null)
                : ($tables->sum('size') ?: null);

            return [
                'driver' => $driver,
                'version' => $connection->getServerVersion(),
                'name' => $connection->getDatabaseName(),
                'size' => $size,
                'tables' => $tables->take(self::MAX_TABLES)->all(),
                'tables_count' => $tables->count(),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return ['driver' => null, 'version' => null, 'name' => null, 'size' => null, 'tables' => [], 'tables_count' => 0, 'error' => mb_substr($exception->getMessage(), 0, 300)];
        }
    }

    /**
     * @return array{files: list<array{name: string, size: int, modified_at: string}>, tail: list<string>, tail_file: ?string}
     */
    private function logs(): array
    {
        $files = collect(glob(storage_path('logs/*.log')) ?: [])
            ->map(fn (string $path): array => ['path' => $path, 'name' => basename($path), 'size' => (int) @filesize($path), 'modified' => (int) @filemtime($path)])
            ->sortByDesc('modified')
            ->values();

        $newest = $files->first();

        return [
            'files' => $files->map(fn (array $file): array => ['name' => $file['name'], 'size' => $file['size'], 'modified_at' => date(DATE_ATOM, $file['modified'])])->all(),
            'tail' => $newest === null ? [] : $this->tail($newest['path']),
            'tail_file' => $newest['name'] ?? null,
        ];
    }

    /**
     * @return list<string>
     */
    private function tail(string $path): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $size = (int) @filesize($path);
        fseek($handle, max(0, $size - self::LOG_TAIL_BYTES));
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        $lines = array_values(array_filter(preg_split('/\r?\n/', $contents) ?: [], fn (string $line): bool => $line !== ''));

        if ($size > self::LOG_TAIL_BYTES) {
            array_shift($lines);
        }

        return array_map(
            fn (string $line): string => ErrorReport::scrub(mb_substr($line, 0, self::LOG_LINE_LENGTH)),
            array_slice($lines, -self::LOG_TAIL_LINES),
        );
    }
}

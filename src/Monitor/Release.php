<?php

namespace Consys\Ecosystem\Monitor;

use Illuminate\Support\Carbon;

/**
 * The release of the host app that is running: the commit it was built from and when it
 * was deployed. Forge zero-downtime deploys run each release from its own folder, so
 * without a git checkout the folder name stands in for the commit.
 */
final class Release
{
    private static ?string $version = null;

    public static function version(): ?string
    {
        return self::$version ??= self::configuredVersion() ?? self::gitCommit() ?? self::releaseFolder();
    }

    public static function deployedAt(): ?Carbon
    {
        $path = realpath(base_path());
        $modifiedAt = $path === false ? false : @filemtime($path);

        return $modifiedAt === false ? null : Carbon::createFromTimestamp($modifiedAt);
    }

    public static function flush(): void
    {
        self::$version = null;
    }

    private static function configuredVersion(): ?string
    {
        $version = config('consys.monitor.version');

        return is_string($version) && $version !== '' ? $version : null;
    }

    private static function gitCommit(): ?string
    {
        $head = self::read(base_path('.git/HEAD'));

        if ($head === null) {
            return null;
        }

        if (! str_starts_with($head, 'ref: ')) {
            return self::commitOrNull($head);
        }

        $reference = substr($head, 5);

        return self::commitOrNull(self::read(base_path('.git/'.$reference)) ?? self::packedReference($reference));
    }

    private static function packedReference(string $reference): ?string
    {
        foreach (explode("\n", self::read(base_path('.git/packed-refs')) ?? '') as $line) {
            $parts = explode(' ', trim($line));

            if (count($parts) === 2 && $parts[1] === $reference) {
                return $parts[0];
            }
        }

        return null;
    }

    private static function releaseFolder(): ?string
    {
        $path = realpath(base_path());

        return $path !== false && basename(dirname($path)) === 'releases' ? basename($path) : null;
    }

    private static function commitOrNull(?string $commit): ?string
    {
        return $commit !== null && preg_match('/^[0-9a-f]{40}$/', $commit) === 1 ? $commit : null;
    }

    private static function read(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : trim($contents);
    }
}

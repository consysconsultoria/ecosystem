<?php

namespace Consys\Ecosystem\Monitor;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * What the admin keeps of an exception. The same failure groups under one fingerprint:
 * the exception class and the first frame in the app's own code, so an error raised
 * inside a vendor package still points at the line of the app that called it.
 */
final class ErrorReport
{
    public const int MAX_FRAMES = 30;

    public const int MAX_CONTEXT_KEYS = 20;

    public const int MAX_CONTEXT_LENGTH = 500;

    /**
     * @param  list<array{file: ?string, line: ?int, call: ?string}>  $trace
     * @param  array<string, scalar|null>  $context
     */
    private function __construct(
        public readonly string $fingerprint,
        public readonly string $exception,
        public readonly string $message,
        public readonly ?string $file,
        public readonly ?int $line,
        public readonly array $trace,
        public readonly ?string $method,
        public readonly ?string $url,
        public readonly ?string $command,
        public readonly int|string|null $userId,
        public readonly string $environment,
        public readonly ?string $version,
        public readonly string $occurredAt,
        public readonly array $context = [],
    ) {}

    public static function fromThrowable(Throwable $exception, ?Request $request = null): self
    {
        $trace = self::trace($exception);
        $origin = self::origin($exception, $trace);
        $request = self::httpRequest($request);

        return new self(
            fingerprint: sha1(implode('|', [$exception::class, $origin['file'], $origin['line']])),
            exception: $exception::class,
            message: self::scrub(mb_substr($exception->getMessage(), 0, 2000)),
            file: $origin['file'],
            line: $origin['line'],
            trace: $trace,
            method: $request?->method(),
            url: $request === null ? null : $request->url(),
            command: $request === null ? self::command() : null,
            userId: self::userId(),
            environment: app()->environment(),
            version: Release::version(),
            occurredAt: now()->toIso8601String(),
            context: self::context($exception),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'exception' => $this->exception,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'trace' => $this->trace,
            'method' => $this->method,
            'url' => $this->url,
            'command' => $this->command,
            'user_id' => $this->userId,
            'environment' => $this->environment,
            'version' => $this->version,
            'occurred_at' => $this->occurredAt,
            'context' => $this->context,
        ];
    }

    /**
     * @return list<array{file: ?string, line: ?int, call: ?string}>
     */
    private static function trace(Throwable $exception): array
    {
        $frames = [['file' => self::relativePath($exception->getFile()), 'line' => $exception->getLine(), 'call' => null]];

        foreach (array_slice($exception->getTrace(), 0, self::MAX_FRAMES - 1) as $frame) {
            $frames[] = [
                'file' => isset($frame['file']) ? self::relativePath($frame['file']) : null,
                'line' => $frame['line'] ?? null,
                'call' => isset($frame['function']) ? ($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'] : null,
            ];
        }

        return $frames;
    }

    /**
     * @param  list<array{file: ?string, line: ?int, call: ?string}>  $trace
     * @return array{file: ?string, line: ?int}
     */
    private static function origin(Throwable $exception, array $trace): array
    {
        foreach ($trace as $frame) {
            if ($frame['file'] !== null && ! self::isVendorPath($frame['file'])) {
                return ['file' => $frame['file'], 'line' => $frame['line']];
            }
        }

        return ['file' => self::relativePath($exception->getFile()), 'line' => $exception->getLine()];
    }

    private static function isVendorPath(string $path): bool
    {
        return str_starts_with($path, 'vendor/') || str_contains($path, '/vendor/');
    }

    private static function relativePath(string $path): string
    {
        $base = str_replace('\\', '/', rtrim(base_path(), '\\/')).'/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private static function httpRequest(?Request $request): ?Request
    {
        $request ??= app()->bound('request') ? app('request') : null;

        return $request !== null && (! app()->runningInConsole() || $request->route() !== null) ? $request : null;
    }

    /**
     * The artisan command without its arguments, which may carry secrets.
     */
    private static function command(): ?string
    {
        $arguments = $_SERVER['argv'] ?? [];

        return is_array($arguments) && isset($arguments[1]) ? 'artisan '.$arguments[1] : null;
    }

    private static function userId(): int|string|null
    {
        try {
            return Auth::hasResolvedGuards() ? Auth::id() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What the exception tells about itself through its context() method, as short scalars,
     * with anything that looks like a secret left out.
     *
     * @return array<string, scalar|null>
     */
    private static function context(Throwable $exception): array
    {
        if (! method_exists($exception, 'context')) {
            return [];
        }

        try {
            $context = $exception->context();
        } catch (Throwable) {
            return [];
        }

        if (! is_array($context)) {
            return [];
        }

        $clean = [];

        foreach (array_slice($context, 0, self::MAX_CONTEXT_KEYS, true) as $key => $value) {
            $key = (string) $key;

            $clean[$key] = match (true) {
                (bool) preg_match('/pass|secret|token|key|authorization|cookie/i', $key) => '[filtered]',
                is_string($value) => self::scrub(mb_substr($value, 0, self::MAX_CONTEXT_LENGTH)),
                is_scalar($value) || $value === null => $value,
                default => self::scrub(mb_substr((string) json_encode($value), 0, self::MAX_CONTEXT_LENGTH)),
            };
        }

        return $clean;
    }

    public static function scrub(string $message): string
    {
        return (string) preg_replace('/\b(password|passwd|secret|token|api_key|authorization)(["\'\s]*[:=]\s*["\']?)[^\s"\',)]+/i', '$1$2[filtered]', $message);
    }
}

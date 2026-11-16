<?php

namespace Consys\Ecosystem\Directory;

/**
 * Reads the raw arrays the admin sends, failing on missing required keys and defaulting
 * the optional ones.
 *
 * @internal
 */
final class PayloadReader
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private string $kind, private array $data) {}

    public function required(string $key): string
    {
        $value = $this->data[$key] ?? null;

        if (! is_scalar($value) || (string) $value === '') {
            throw InvalidPayload::missing($this->kind, $key);
        }

        return (string) $value;
    }

    public function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return array_key_exists($key, $this->data) && $this->data[$key] !== null
            ? (bool) $this->data[$key]
            : $default;
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->data[$key] ?? [];

        return is_array($value)
            ? array_values(array_map(strval(...), array_filter($value, is_scalar(...))))
            : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->data[$key] ?? [];

        return is_array($value) ? $value : [];
    }
}

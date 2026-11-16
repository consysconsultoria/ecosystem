<?php

namespace Consys\Ecosystem\Auth;

/**
 * The member the Consys login signed in, as its /api/user answers.
 */
final readonly class ConsysIdentity
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $uuid,
        public ?string $name,
        public ?string $email,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $uuid = $data['uuid'] ?? null;

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return new self(
            uuid: $uuid,
            name: is_string($data['name'] ?? null) ? $data['name'] : null,
            email: is_string($data['email'] ?? null) ? mb_strtolower($data['email']) : null,
            raw: $data,
        );
    }
}

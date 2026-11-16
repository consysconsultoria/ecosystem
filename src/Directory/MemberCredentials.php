<?php

namespace Consys\Ecosystem\Directory;

/**
 * A password the admin sets for a member, delivered only to subscribers that receive credentials.
 */
final readonly class MemberCredentials
{
    public function __construct(
        public ?string $passwordHash,
        public bool $mustChangePassword,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $reader = new PayloadReader('credentials', $data);

        return new self(
            passwordHash: $reader->string('password_hash'),
            mustChangePassword: $reader->bool('must_change_password'),
        );
    }
}

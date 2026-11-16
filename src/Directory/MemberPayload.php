<?php

namespace Consys\Ecosystem\Directory;

/**
 * A member as the admin sends it: the whole state, so applying it twice changes nothing.
 * The email is lowercased, as every app stores it.
 */
final readonly class MemberPayload
{
    /**
     * @param  list<string>  $tenants
     * @param  array<string, MemberAccess>  $access
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $uuid,
        public string $name,
        public string $email,
        public ?string $whatsapp,
        public bool $isActive,
        public bool $deleted,
        public bool $isAdmin,
        public array $tenants,
        public array $access,
        public ?MemberCredentials $credentials = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $reader = new PayloadReader('member', $data);

        $access = [];

        foreach ($reader->array('access') as $application => $grant) {
            if (is_array($grant)) {
                $access[(string) $application] = MemberAccess::fromArray($grant);
            }
        }

        $credentials = $reader->array('credentials');

        return new self(
            uuid: $reader->required('uuid'),
            name: $reader->required('name'),
            email: mb_strtolower($reader->required('email')),
            whatsapp: $reader->string('whatsapp'),
            isActive: $reader->bool('is_active'),
            deleted: $reader->bool('deleted'),
            isAdmin: $reader->bool('is_admin'),
            tenants: $reader->strings('tenants'),
            access: $access,
            credentials: $credentials === [] ? null : MemberCredentials::fromArray($credentials),
            raw: $data,
        );
    }

    public function accessFor(string $application): ?MemberAccess
    {
        return $this->access[$application] ?? null;
    }

    /**
     * The member may sign in at all: it is active and was not deleted.
     */
    public function isEnabled(): bool
    {
        return $this->isActive && ! $this->deleted;
    }

    /**
     * The member enters the application: it has access to it, is active and was not deleted.
     */
    public function reaches(string $application): bool
    {
        return $this->accessFor($application) !== null && $this->isEnabled();
    }

    /**
     * A platform administrator of the admin, who reaches the application only when it also has access.
     */
    public function isAdminOf(string $application): bool
    {
        return $this->isAdmin && $this->reaches($application);
    }
}

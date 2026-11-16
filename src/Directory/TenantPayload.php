<?php

namespace Consys\Ecosystem\Directory;

/**
 * An organization as the admin sends it: the whole state, so applying it twice changes nothing.
 */
final readonly class TenantPayload
{
    /**
     * @param  list<string>  $applications
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $uuid,
        public ?string $externalId,
        public string $name,
        public ?string $nickname,
        public bool $isActive,
        public ?string $subscriptionExpiresAt,
        public bool $deleted,
        public array $applications,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $reader = new PayloadReader('tenant', $data);

        return new self(
            uuid: $reader->required('uuid'),
            externalId: $reader->string('external_id'),
            name: $reader->required('name'),
            nickname: $reader->string('nickname'),
            isActive: $reader->bool('is_active'),
            subscriptionExpiresAt: $reader->string('subscription_expires_at'),
            deleted: $reader->bool('deleted'),
            applications: $reader->strings('applications'),
            raw: $data,
        );
    }

    /**
     * The nickname the admin gives the organization, or its registered name without one.
     */
    public function displayName(): string
    {
        return $this->nickname ?? $this->name;
    }

    public function hasApplication(string $application): bool
    {
        return in_array($application, $this->applications, true);
    }

    /**
     * The organization may use the application: it has it, is active and was not deleted.
     */
    public function usable(string $application): bool
    {
        return $this->hasApplication($application) && $this->isActive && ! $this->deleted;
    }
}

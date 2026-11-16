<?php

namespace Consys\Ecosystem\Directory;

/**
 * The access of a member to one application. The tenant list is empty when the scope is all.
 */
final readonly class MemberAccess
{
    /**
     * @param  list<string>  $tenants
     */
    public function __construct(
        public AccessScope $scope,
        public array $tenants,
    ) {}

    /**
     * An unknown scope falls back to own, which reaches only the listed organizations.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $reader = new PayloadReader('access', $data);

        return new self(
            scope: AccessScope::tryFrom((string) $reader->string('scope')) ?? AccessScope::Own,
            tenants: $reader->strings('tenants'),
        );
    }

    public function coversAllTenants(): bool
    {
        return $this->scope === AccessScope::All;
    }

    public function covers(string $tenantUuid): bool
    {
        return $this->coversAllTenants() || in_array($tenantUuid, $this->tenants, true);
    }
}

<?php

namespace Consys\Ecosystem\Tests\Fixtures;

use Consys\Ecosystem\Contracts\DirectoryMirror;
use Consys\Ecosystem\Directory\MemberPayload;
use Consys\Ecosystem\Directory\TenantPayload;

/**
 * A mirror without the optional catalog of applications.
 */
class TenantsAndMembersMirror implements DirectoryMirror
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    public function applyTenant(TenantPayload $tenant): ?object
    {
        $this->calls[] = "tenant:{$tenant->uuid}";

        return $tenant;
    }

    public function applyMember(MemberPayload $member): ?object
    {
        $this->calls[] = "member:{$member->uuid}";

        return $member;
    }
}

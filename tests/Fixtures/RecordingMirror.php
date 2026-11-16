<?php

namespace Consys\Ecosystem\Tests\Fixtures;

use Consys\Ecosystem\Consys;
use Consys\Ecosystem\Contracts\DirectoryMirror;
use Consys\Ecosystem\Contracts\MirrorsApplications;
use Consys\Ecosystem\Directory\ApplicationPayload;
use Consys\Ecosystem\Directory\MemberPayload;
use Consys\Ecosystem\Directory\TenantPayload;
use stdClass;

/**
 * Keeps what it was asked to apply, ignoring organizations and members outside this app.
 */
class RecordingMirror implements DirectoryMirror, MirrorsApplications
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    /**
     * @var list<TenantPayload>
     */
    public array $tenants = [];

    /**
     * @var list<MemberPayload>
     */
    public array $members = [];

    /**
     * @var list<ApplicationPayload>
     */
    public array $applications = [];

    /**
     * @var list<list<ApplicationPayload>>
     */
    public array $catalogs = [];

    public function applyTenant(TenantPayload $tenant): ?stdClass
    {
        $this->calls[] = "tenant:{$tenant->uuid}";
        $this->tenants[] = $tenant;

        return $tenant->hasApplication(Consys::application()) ? (object) ['sso_id' => $tenant->uuid] : null;
    }

    public function applyMember(MemberPayload $member): ?stdClass
    {
        $this->calls[] = "member:{$member->uuid}";
        $this->members[] = $member;

        return $member->reaches(Consys::application()) ? (object) ['sso_id' => $member->uuid] : null;
    }

    public function applyApplication(ApplicationPayload $application): void
    {
        $this->calls[] = "application:{$application->slug}";
        $this->applications[] = $application;
    }

    public function applyApplications(array $applications): void
    {
        $this->calls[] = 'catalog';
        $this->catalogs[] = $applications;
    }
}

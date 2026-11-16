<?php

namespace Consys\Ecosystem\Contracts;

use Consys\Ecosystem\Directory\MemberPayload;
use Consys\Ecosystem\Directory\TenantPayload;

/**
 * Applies the admin directory to the host app's own models. The admin decides who reaches
 * which organization; the host decides what that means locally. Every payload carries the
 * whole state of its subject, so an implementation must be idempotent.
 */
interface DirectoryMirror
{
    /**
     * Returns the local record the payload was applied to, or null when it was ignored.
     */
    public function applyTenant(TenantPayload $tenant): ?object;

    /**
     * Returns the local record the payload was applied to, or null when it was ignored.
     */
    public function applyMember(MemberPayload $member): ?object;
}

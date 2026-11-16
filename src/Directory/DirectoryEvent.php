<?php

namespace Consys\Ecosystem\Directory;

/**
 * The event types the admin delivers to its subscribers.
 */
enum DirectoryEvent: string
{
    case TenantUpdated = 'tenant.updated';
    case MemberUpdated = 'member.updated';
    case ApplicationUpdated = 'application.updated';
}

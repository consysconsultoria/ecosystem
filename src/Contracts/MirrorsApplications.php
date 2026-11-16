<?php

namespace Consys\Ecosystem\Contracts;

use Consys\Ecosystem\Directory\ApplicationPayload;

/**
 * Optional, for a mirror that also keeps the catalog of applications. Without it the
 * application.updated events and the catalog of the whole download are ignored.
 */
interface MirrorsApplications
{
    /**
     * One application changed in the admin, possibly deactivated or deleted.
     */
    public function applyApplication(ApplicationPayload $application): void;

    /**
     * The whole catalog of active applications from the download, so one missing from it can be removed.
     *
     * @param  list<ApplicationPayload>  $applications
     */
    public function applyApplications(array $applications): void;
}

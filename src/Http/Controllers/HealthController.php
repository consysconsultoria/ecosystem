<?php

namespace Consys\Ecosystem\Http\Controllers;

use Consys\Ecosystem\Monitor\HealthDetails;
use Consys\Ecosystem\Monitor\HealthReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Answers the admin's monitor with the state of the app and of its server, and with the
 * details of the system, the database and the logs when the admin asks for them.
 */
class HealthController
{
    public function __invoke(Request $request, HealthReport $report, HealthDetails $details): JsonResponse
    {
        $body = $report->collect();

        if ($request->boolean('details')) {
            $body['details'] = $details->collect();
        }

        return response()->json($body)->header('Cache-Control', 'no-store');
    }
}

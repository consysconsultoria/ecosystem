<?php

namespace Consys\Ecosystem\Http\Controllers;

use Consys\Ecosystem\Monitor\HealthReport;
use Illuminate\Http\JsonResponse;

/**
 * Answers the admin's monitor with the state of the app and of its server.
 */
class HealthController
{
    public function __invoke(HealthReport $report): JsonResponse
    {
        return response()->json($report->collect())->header('Cache-Control', 'no-store');
    }
}

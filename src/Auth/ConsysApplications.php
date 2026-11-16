<?php

namespace Consys\Ecosystem\Auth;

use Consys\Ecosystem\Consys;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The applications of the ecosystem a member can enter, asked to the Consys login so the app
 * switcher matches its launcher. Without an answer the switcher offers only the launcher.
 */
class ConsysApplications
{
    /**
     * @return list<array{slug: string, name: string, url: string}>
     */
    public function for(?string $memberUuid): array
    {
        if (blank($memberUuid) || blank(config('consys.auth.url'))) {
            return [];
        }

        try {
            $response = Http::withBasicAuth((string) config('consys.auth.client_id'), (string) config('consys.auth.client_secret'))
                ->acceptJson()
                ->timeout(5)
                ->get(Consys::authUrl('/api/members/'.rawurlencode($memberUuid).'/applications'));
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $applications = [];

        foreach ((array) $response->json('applications', []) as $application) {
            if (is_array($application) && is_string($application['slug'] ?? null) && is_string($application['name'] ?? null) && is_string($application['url'] ?? null)) {
                $applications[] = ['slug' => $application['slug'], 'name' => $application['name'], 'url' => $application['url']];
            }
        }

        return $applications;
    }
}

<?php

namespace Consys\Ecosystem\Http\Controllers;

use Consys\Ecosystem\Contracts\DirectoryMirror;
use Consys\Ecosystem\Contracts\MirrorsApplications;
use Consys\Ecosystem\Directory\ApplicationPayload;
use Consys\Ecosystem\Directory\DirectoryEvent;
use Consys\Ecosystem\Directory\InvalidPayload;
use Consys\Ecosystem\Directory\MemberPayload;
use Consys\Ecosystem\Directory\TenantPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives the changes the admin makes to organizations, members and applications. An event
 * this app does not handle is acknowledged, so the admin does not retry it forever.
 */
class DirectoryWebhookController
{
    public function __invoke(Request $request, DirectoryMirror $mirror): JsonResponse
    {
        $data = $request->input('data');
        $data = is_array($data) ? $data : [];

        try {
            match (DirectoryEvent::tryFrom((string) $request->input('type'))) {
                DirectoryEvent::TenantUpdated => $mirror->applyTenant(TenantPayload::fromArray($data)),
                DirectoryEvent::MemberUpdated => $mirror->applyMember(MemberPayload::fromArray($data)),
                DirectoryEvent::ApplicationUpdated => $mirror instanceof MirrorsApplications
                    ? $mirror->applyApplication(ApplicationPayload::fromArray($data))
                    : null,
                null => null,
            };
        } catch (InvalidPayload $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['received' => true]);
    }
}

<?php

namespace Consys\Ecosystem\Console;

use Consys\Ecosystem\Contracts\DirectoryMirror;
use Consys\Ecosystem\Contracts\MirrorsApplications;
use Consys\Ecosystem\Directory\ApplicationPayload;
use Consys\Ecosystem\Directory\InvalidPayload;
use Consys\Ecosystem\Directory\MemberPayload;
use Consys\Ecosystem\Directory\TenantPayload;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SyncDirectoryCommand extends Command
{
    protected $signature = 'directory:sync';

    protected $description = 'Download the whole admin directory to recover from any webhook missed';

    public function handle(DirectoryMirror $mirror): int
    {
        if (blank(config('consys.directory.admin_url')) || blank(config('consys.directory.secret'))) {
            $this->info('The admin directory is not configured.');

            return self::SUCCESS;
        }

        try {
            $response = Http::timeout(60)
                ->withToken((string) config('consys.directory.secret'))
                ->acceptJson()
                ->get(rtrim((string) config('consys.directory.admin_url'), '/').'/api/v1/directory');
        } catch (ConnectionException $exception) {
            $this->error("The admin could not be reached: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error("The admin answered HTTP {$response->status()}.");

            return self::FAILURE;
        }

        $tenants = 0;
        $members = 0;
        $invalid = 0;

        if ($mirror instanceof MirrorsApplications) {
            try {
                $mirror->applyApplications(array_map(ApplicationPayload::fromArray(...), $this->list($response->json('applications'))));
            } catch (InvalidPayload $exception) {
                $this->warn("The catalog was not applied: {$exception->getMessage()}");
                $invalid++;
            }
        }

        foreach ($this->list($response->json('tenants')) as $tenant) {
            try {
                $tenants += $mirror->applyTenant(TenantPayload::fromArray($tenant)) === null ? 0 : 1;
            } catch (InvalidPayload $exception) {
                $this->warn($exception->getMessage());
                $invalid++;
            }
        }

        foreach ($this->list($response->json('members')) as $member) {
            try {
                $members += $mirror->applyMember(MemberPayload::fromArray($member)) === null ? 0 : 1;
            } catch (InvalidPayload $exception) {
                $this->warn($exception->getMessage());
                $invalid++;
            }
        }

        $this->info("Synced {$tenants} tenants and {$members} members.");

        if ($invalid > 0) {
            $this->error("Skipped {$invalid} invalid entries.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(mixed $items): array
    {
        return is_array($items) ? array_values(array_filter($items, is_array(...))) : [];
    }
}

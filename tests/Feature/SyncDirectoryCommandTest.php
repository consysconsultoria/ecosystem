<?php

use Consys\Ecosystem\Contracts\DirectoryMirror;
use Consys\Ecosystem\Tests\Fixtures\RecordingMirror;
use Consys\Ecosystem\Tests\Fixtures\TenantsAndMembersMirror;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function directoryDownload(): array
{
    return [
        'applications' => [
            ['slug' => 'erp', 'name' => 'ERP', 'url' => 'https://erp.test', 'logout_url' => 'https://erp.test/auth/consys/logout'],
            ['slug' => 'tickets', 'name' => 'Tickets', 'url' => 'https://tickets.test', 'logout_url' => null],
        ],
        'tenants' => [
            tenantData(),
            tenantData(['uuid' => '0199a1d2-0000-7000-8000-00000000a002', 'applications' => ['tickets']]),
        ],
        'members' => [
            memberData(),
            memberData(['uuid' => '0199a1d2-0000-7000-8000-00000000b002', 'access' => []]),
            memberData(['uuid' => '0199a1d2-0000-7000-8000-00000000b003', 'deleted' => true]),
        ],
    ];
}

it('downloads the whole directory and applies the catalog, then the organizations, then the members', function (): void {
    Http::fake(['admin.test/api/v1/directory' => Http::response(directoryDownload())]);

    $this->artisan('directory:sync')
        ->expectsOutput('Synced 1 tenants and 1 members.')
        ->assertSuccessful();

    $mirror = app(RecordingMirror::class);

    expect($mirror->calls)->toBe([
        'catalog',
        'tenant:0199a1d2-0000-7000-8000-00000000a001',
        'tenant:0199a1d2-0000-7000-8000-00000000a002',
        'member:0199a1d2-0000-7000-8000-00000000b001',
        'member:0199a1d2-0000-7000-8000-00000000b002',
        'member:0199a1d2-0000-7000-8000-00000000b003',
    ])
        ->and(array_map(fn ($application) => $application->slug, $mirror->catalogs[0]))->toBe(['erp', 'tickets'])
        ->and($mirror->catalogs[0][0]->isAvailable())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://admin.test/api/v1/directory'
        && $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer directory-secret'));
});

it('skips the catalog for a mirror that does not keep it', function (): void {
    $mirror = new TenantsAndMembersMirror;
    app()->instance(DirectoryMirror::class, $mirror);
    Http::fake(['admin.test/*' => Http::response(directoryDownload())]);

    $this->artisan('directory:sync')
        ->expectsOutput('Synced 2 tenants and 3 members.')
        ->assertSuccessful();

    expect($mirror->calls)->toHaveCount(5);
});

it('fails when the admin does not answer the download', function (): void {
    Http::fake(['admin.test/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->artisan('directory:sync')
        ->expectsOutput('The admin answered HTTP 401.')
        ->assertFailed();

    expect(app(RecordingMirror::class)->calls)->toBe([]);
});

it('fails when the admin cannot be reached', function (): void {
    Http::fake(['admin.test/*' => unreachable()]);

    $this->artisan('directory:sync')->assertFailed();

    expect(app(RecordingMirror::class)->calls)->toBe([]);
});

it('does nothing while the admin directory is not configured', function (string $key): void {
    config([$key => null]);
    Http::fake();

    $this->artisan('directory:sync')
        ->expectsOutput('The admin directory is not configured.')
        ->assertSuccessful();

    Http::assertNothingSent();
})->with([
    'without the admin url' => ['consys.directory.admin_url'],
    'without the secret' => ['consys.directory.secret'],
]);

it('applies the valid entries and fails for the invalid ones', function (): void {
    Http::fake(['admin.test/*' => Http::response([
        'applications' => [],
        'tenants' => [tenantData(), ['name' => 'Without uuid']],
        'members' => [memberData()],
    ])]);

    $this->artisan('directory:sync')
        ->expectsOutput('Synced 1 tenants and 1 members.')
        ->expectsOutput('Skipped 1 invalid entries.')
        ->assertFailed();
});

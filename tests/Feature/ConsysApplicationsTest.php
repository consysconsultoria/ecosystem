<?php

use Consys\Ecosystem\Auth\ConsysApplications;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('asks the Consys login for the applications of the member', function (): void {
    $applications = [
        ['slug' => 'erp', 'name' => 'ERP', 'url' => 'https://erp.test/auth/consys/redirect'],
        ['slug' => 'tickets', 'name' => 'Tickets', 'url' => 'https://tickets.test/auth/consys/redirect'],
    ];
    Http::fake(['auth.test/api/members/*' => Http::response(['applications' => $applications])]);

    expect(app(ConsysApplications::class)->for('0199a1d2-0000-7000-8000-00000000b001'))->toBe($applications);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.test/api/members/0199a1d2-0000-7000-8000-00000000b001/applications'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('erp-client:erp-client-secret')));
});

it('keeps only the fields the switcher shows', function (): void {
    Http::fake(['auth.test/*' => Http::response(['applications' => [
        ['slug' => 'erp', 'name' => 'ERP', 'url' => 'https://erp.test', 'tenants' => ['Safety']],
        ['slug' => 'broken'],
        'garbage',
    ]])]);

    expect(app(ConsysApplications::class)->for('member-uuid'))->toBe([['slug' => 'erp', 'name' => 'ERP', 'url' => 'https://erp.test']]);
});

it('offers no application when the Consys login fails', function (Closure|array $answer): void {
    Http::fake(['auth.test/*' => $answer instanceof Closure ? $answer : Http::response(...$answer)]);

    expect(app(ConsysApplications::class)->for('member-uuid'))->toBe([]);
})->with([
    'server error' => [[['message' => 'Server Error'], 500]],
    'unauthorized client' => [[null, 401]],
    'unreachable' => [fn () => unreachable()],
]);

it('does not ask the Consys login for a user without a member uuid or without the login configured', function (): void {
    Http::fake();

    expect(app(ConsysApplications::class)->for(null))->toBe([])
        ->and(app(ConsysApplications::class)->for(''))->toBe([]);

    config(['consys.auth.url' => null]);

    expect(app(ConsysApplications::class)->for('member-uuid'))->toBe([]);

    Http::assertNothingSent();
});

<?php

use Consys\Ecosystem\Contracts\DirectoryMirror;
use Consys\Ecosystem\EcosystemServiceProvider;
use Consys\Ecosystem\Http\Middleware\VerifyDirectorySignature;
use Consys\Ecosystem\Tests\Fixtures\RecordingMirror;
use Consys\Ecosystem\Tests\Fixtures\TenantsAndMembersMirror;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

it('applies an organization to the mirror', function (): void {
    sendWebhook('tenant.updated', tenantData())->assertOk()->assertExactJson(['received' => true]);

    $tenant = app(RecordingMirror::class)->tenants[0];

    expect($tenant->uuid)->toBe('0199a1d2-0000-7000-8000-00000000a001')
        ->and($tenant->displayName())->toBe('Safety Home');
});

it('applies a member to the mirror', function (): void {
    sendWebhook('member.updated', memberData())->assertOk()->assertExactJson(['received' => true]);

    $member = app(RecordingMirror::class)->members[0];

    expect($member->email)->toBe('diego@safety.test')
        ->and($member->reaches('erp'))->toBeTrue();
});

it('applies an application to a mirror that keeps the catalog', function (): void {
    sendWebhook('application.updated', ['slug' => 'tickets', 'name' => 'Tickets', 'url' => 'https://tickets.test', 'logout_url' => 'https://tickets.test/auth/consys/logout', 'is_active' => false, 'deleted' => false])
        ->assertOk()
        ->assertExactJson(['received' => true]);

    $application = app(RecordingMirror::class)->applications[0];

    expect($application->slug)->toBe('tickets')
        ->and($application->isAvailable())->toBeFalse();
});

it('acknowledges an application to a mirror without the catalog', function (): void {
    $mirror = new TenantsAndMembersMirror;
    app()->instance(DirectoryMirror::class, $mirror);

    sendWebhook('application.updated', ['slug' => 'tickets', 'name' => 'Tickets', 'url' => 'https://tickets.test'])->assertOk()->assertExactJson(['received' => true]);

    expect($mirror->calls)->toBe([]);
});

it('acknowledges and ignores an event type it does not know', function (): void {
    sendWebhook('invoice.paid', ['uuid' => 'x'])->assertOk()->assertExactJson(['received' => true]);

    expect(app(RecordingMirror::class)->calls)->toBe([]);
});

it('answers 422 to a payload without its required keys', function (): void {
    sendWebhook('member.updated', ['uuid' => 'member-uuid'])->assertUnprocessable();

    expect(app(RecordingMirror::class)->calls)->toBe([]);
});

it('runs the webhook without session or CSRF even inside the web group', function (): void {
    $middleware = Route::getRoutes()->getByName('webhooks.directory')->gatherMiddleware();
    $resolved = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('webhooks.directory'));

    expect($middleware)->toContain('web')
        ->and($resolved)->toContain(VerifyDirectorySignature::class)
        ->and($resolved)->not->toContain(StartSession::class)
        ->and(collect($resolved)->filter(fn (string $class): bool => is_a($class, PreventRequestForgery::class, true) || is_a($class, VerifyCsrfToken::class, true)))->toBeEmpty();

    sendWebhook('tenant.updated', tenantData())->assertOk()->assertCookieMissing('laravel_session');
});

it('uses the mirror class set in the config when the host binds none', function (): void {
    app()->offsetUnset(DirectoryMirror::class);
    (new EcosystemServiceProvider(app()))->register();
    config(['consys.directory.mirror' => TenantsAndMembersMirror::class]);

    expect(app(DirectoryMirror::class))->toBeInstanceOf(TenantsAndMembersMirror::class);
});

it('explains how to bind the mirror when none is bound', function (): void {
    app()->offsetUnset(DirectoryMirror::class);
    (new EcosystemServiceProvider(app()))->register();

    expect(fn () => app(DirectoryMirror::class))->toThrow(LogicException::class, 'consys.directory.mirror');
});

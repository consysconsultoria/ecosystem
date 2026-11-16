<?php

use Consys\Ecosystem\Consys;
use Consys\Ecosystem\EcosystemServiceProvider;
use Illuminate\Http\Request;

it('merges the package config under consys', function (): void {
    expect(config('consys.directory.tolerance_seconds'))->toBe(300)
        ->and(config('consys.auth.guard'))->toBeNull();
});

it('keeps the nested defaults the host config leaves out', function (): void {
    config(['consys' => ['auth' => ['mode' => 'required'], 'directory' => ['application' => 'tickets']]]);

    (new EcosystemServiceProvider(app()))->register();

    expect(config('consys.auth.mode'))->toBe('required')
        ->and(config('consys.directory.application'))->toBe('tickets')
        ->and(config('consys.directory.tolerance_seconds'))->toBe(300)
        ->and(array_key_exists('mirror', config('consys.directory')))->toBeTrue();
});

it('reads the mode of the sign in through the Consys login', function (string $mode, bool $enabled, bool $required): void {
    config(['consys.auth.mode' => $mode]);

    expect(Consys::authEnabled())->toBe($enabled)
        ->and(Consys::authRequired())->toBe($required);
})->with([
    'disabled' => ['disabled', false, false],
    'optional' => ['optional', true, false],
    'required' => ['required', true, true],
]);

it('builds the addresses of the Consys login without a doubled slash', function (): void {
    expect(Consys::authUrl('/oauth/token'))->toBe('https://auth.test/oauth/token')
        ->and(Consys::signOutUrl())->toBe('https://auth.test/logout')
        ->and(Consys::launcherUrl())->toBe('https://auth.test')
        ->and(Consys::application())->toBe('erp');
});

it('offers no launcher while the sign in through the Consys login is off', function (): void {
    config(['consys.auth.mode' => 'disabled']);

    expect(Consys::launcherUrl())->toBeNull();

    config(['consys.auth.mode' => 'optional', 'consys.auth.url' => null]);

    expect(Consys::launcherUrl())->toBeNull();
});

it('leaves for another site with a plain redirect or, for Inertia, a 409 with the location', function (): void {
    $plain = Consys::location('https://auth.test/logout', Request::create('/logout', 'POST'));

    $inertia = Request::create('/logout', 'POST');
    $inertia->headers->set('X-Inertia', 'true');
    $external = Consys::location('https://auth.test/logout', $inertia);

    expect($plain->getStatusCode())->toBe(302)
        ->and($plain->headers->get('Location'))->toBe('https://auth.test/logout')
        ->and($external->getStatusCode())->toBe(409)
        ->and($external->headers->get('X-Inertia-Location'))->toBe('https://auth.test/logout');
});

it('lets the host choose the paths of its routes', function (): void {
    $routes = Consys::authRoutes('/central/sso/');

    expect($routes['redirect']->uri())->toBe('central/sso/redirect')
        ->and($routes['callback']->uri())->toBe('central/sso/callback')
        ->and(Consys::webhookRoute('/hooks/admin')->uri())->toBe('hooks/admin')
        ->and(Consys::logoutRoute('/central/sso/logout')->uri())->toBe('central/sso/logout');
});

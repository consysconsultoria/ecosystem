<?php

namespace Consys\Ecosystem;

use Consys\Ecosystem\Http\Controllers\ConsysAuthController;
use Consys\Ecosystem\Http\Controllers\DirectoryWebhookController;
use Consys\Ecosystem\Http\Middleware\VerifyDirectorySignature;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entry point for the host app: the routes it places where it wants and the few settings
 * every app of the ecosystem reads.
 */
final class Consys
{
    public const string SIGN_IN_ROUTE = 'auth.consys.redirect';

    public const string CALLBACK_ROUTE = 'auth.consys.callback';

    public const string LOGOUT_ROUTE = 'auth.consys.logout';

    public const string WEBHOOK_ROUTE = 'webhooks.directory';

    /**
     * The webhook the admin delivers the directory to. It runs without session or CSRF even
     * inside the web group, since the admin authenticates it by its signature.
     */
    public static function webhookRoute(string $uri = '/webhooks/directory'): RoutingRoute
    {
        return Route::post($uri, DirectoryWebhookController::class)
            ->middleware(VerifyDirectorySignature::class)
            ->withoutMiddleware([
                'web',
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                PreventRequestForgery::class,
            ])
            ->name(self::WEBHOOK_ROUTE);
    }

    /**
     * The start and the return of the sign in through the Consys login. They need the session,
     * so the host places them in its web group, usually under guest and a throttle.
     *
     * @return array{redirect: RoutingRoute, callback: RoutingRoute}
     */
    public static function authRoutes(string $prefix = '/auth/consys'): array
    {
        $prefix = rtrim($prefix, '/');

        return [
            'redirect' => Route::get($prefix.'/redirect', [ConsysAuthController::class, 'redirect'])->name(self::SIGN_IN_ROUTE),
            'callback' => Route::get($prefix.'/callback', [ConsysAuthController::class, 'callback'])->name(self::CALLBACK_ROUTE),
        ];
    }

    /**
     * The address the Consys login calls from the browser when the member signs out anywhere.
     * It must stay reachable by signed in users, so the host keeps it out of the guest group.
     */
    public static function logoutRoute(string $uri = '/auth/consys/logout'): RoutingRoute
    {
        return Route::get($uri, [ConsysAuthController::class, 'logout'])->name(self::LOGOUT_ROUTE);
    }

    public static function authMode(): string
    {
        return (string) config('consys.auth.mode', 'disabled');
    }

    public static function authEnabled(): bool
    {
        return self::authMode() !== 'disabled';
    }

    public static function authRequired(): bool
    {
        return self::authMode() === 'required';
    }

    public static function authUrl(string $path = ''): string
    {
        return rtrim((string) config('consys.auth.url'), '/').$path;
    }

    /**
     * The launcher of the Consys login for the app switcher, or null while the sign in through it is off.
     */
    public static function launcherUrl(): ?string
    {
        return self::authEnabled() && filled(config('consys.auth.url')) ? self::authUrl() : null;
    }

    /**
     * Where the browser ends the Consys session too, when the sign in through it is required.
     */
    public static function signOutUrl(): string
    {
        return self::authUrl('/logout');
    }

    /**
     * The slug of the host app in the admin catalog.
     */
    public static function application(): string
    {
        return (string) config('consys.directory.application');
    }

    /**
     * Leaves for another site through a full page visit. An Inertia request cannot follow a
     * redirect to another origin, so it gets the 409 with X-Inertia-Location that Inertia::location
     * answers; any other request gets a plain redirect.
     */
    public static function location(string $url, ?Request $request = null): Response
    {
        $request ??= request();

        if ($request->headers->has('X-Inertia')) {
            return new Response('', Response::HTTP_CONFLICT, ['X-Inertia-Location' => $url]);
        }

        return redirect()->away($url);
    }
}

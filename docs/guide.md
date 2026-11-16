# consys/ecosystem

What every Consys app needs to be part of the ecosystem, in one place:

- **Admin directory** (`admin`): the signed webhook (`tenant.updated`, `member.updated`, `application.updated`), the signature check, typed payloads and the nightly `directory:sync` download.
- **Consys login** (`auth`, Laravel Passport): sign in through OAuth authorization code + PKCE, the sign-out callback and the app switcher client.
- **Development rules**: the Consys rules for AI agents, shipped as a Laravel Boost guideline (see [AI guidelines](#ai-guidelines)).

The package never touches the host's models. The host implements two small contracts (`DirectoryMirror` and `ConsysSignIn`) and places the routes where it wants.

Requires PHP ^8.3 and Laravel 12 or 13. Inertia is optional.

## Installation

The package lives in the private repository `consysconsultoria/ecosystem`. In the host's `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/consysconsultoria/ecosystem", "no-api": true }
    ],
    "require": {
        "consys/ecosystem": "^1.0"
    }
}
```

```bash
composer update consys/ecosystem
```

The repository is private: every machine that installs it needs Composer credentials for GitHub. On the servers, each user that runs a site has a fine-grained token (read-only Contents on this repository) registered in Forge under "Composer package authentication" as `http-basic` for `github.com`. Locally, `composer config --global --auth github-oauth.github.com <token>` does the same. When the token expires, deploys stop installing the package until it is replaced on every user.

The service provider is auto-discovered. To change the defaults, publish the config:

```bash
php artisan vendor:publish --tag=consys-config
```

To work on the package and a host at the same time, point the host at a local checkout instead:

```json
{
    "repositories": [
        { "type": "path", "url": "../ecosystem" }
    ],
    "require": {
        "consys/ecosystem": "@dev"
    }
}
```

> A path repository only resolves on machines where `../ecosystem` exists, so never deploy a host that depends on it.

## Configuration

`config/consys.php` (merged recursively, so publishing is optional and a host file that sets only some keys keeps the defaults of the others):

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `consys.auth.mode` | `CONSYS_AUTH_MODE` | `disabled` | `disabled` keeps only the local login, `optional` offers the Consys login, `required` sends every sign in there |
| `consys.auth.url` | `CONSYS_AUTH_URL` | | Base URL of the Consys login |
| `consys.auth.client_id` | `CONSYS_AUTH_CLIENT_ID` | | Passport client of this app |
| `consys.auth.client_secret` | `CONSYS_AUTH_CLIENT_SECRET` | | Passport client secret |
| `consys.auth.guard` | | `null` (default guard) | Guard the callback signs in and the logout signs out |
| `consys.auth.handler` | | `null` | Class implementing `ConsysSignIn`, when not bound in a provider |
| `consys.directory.application` | `CONSYS_DIRECTORY_APPLICATION` | | Slug of this app in the admin catalog (`erp`, `tickets`...) |
| `consys.directory.admin_url` | `CONSYS_DIRECTORY_ADMIN_URL` | | Base URL of the admin, for `directory:sync` |
| `consys.directory.secret` | `CONSYS_DIRECTORY_SECRET` | | Subscriber secret: signs the webhooks and is the Bearer token of the download |
| `consys.directory.tolerance_seconds` | | `300` | Maximum age (and clock skew) of a webhook timestamp |
| `consys.directory.mirror` | | `null` | Class implementing `DirectoryMirror`, when not bound in a provider |

```dotenv
CONSYS_AUTH_MODE=optional
CONSYS_AUTH_URL=https://auth.example.test
CONSYS_AUTH_CLIENT_ID=
CONSYS_AUTH_CLIENT_SECRET=
CONSYS_DIRECTORY_APPLICATION=erp
CONSYS_DIRECTORY_ADMIN_URL=https://admin.example.test
CONSYS_DIRECTORY_SECRET=
```

## Contracts

### `Consys\Ecosystem\Contracts\DirectoryMirror` (required for the webhook and the sync)

```php
public function applyTenant(TenantPayload $tenant): ?object;
public function applyMember(MemberPayload $member): ?object;
```

Return the local record the payload was applied to, or `null` when it was ignored. `directory:sync` counts the non-null results. The return type is `?object`, so an implementation may narrow it (`?Tenant`, `?User`). Every payload carries the whole state of its subject: implementations must be idempotent.

### `Consys\Ecosystem\Contracts\MirrorsApplications` (optional)

```php
public function applyApplication(ApplicationPayload $application): void;
/** @param list<ApplicationPayload> $applications */
public function applyApplications(array $applications): void;
```

Implement it on the mirror only when the app keeps the application catalog (today only `auth`). Without it, `application.updated` is acknowledged and ignored, and the sync skips the catalog. `applyApplications` receives the whole catalog of active applications from the download, before the tenants.

### `Consys\Ecosystem\Contracts\ConsysSignIn` (required for the sign in)

```php
public function user(ConsysIdentity $identity): ?Authenticatable;
public function signedIn(Request $request, Authenticatable $user): Response;
public function failed(Request $request, SignInFailure $failure): Response;
```

- `user()` resolves the local user of the member the login identified (`$identity->uuid` is the member uuid, the same as `MemberPayload::$uuid`). `null` refuses the sign in with `SignInFailure::UnknownUser`.
- `signedIn()` runs after `Auth::login()` and the session regeneration. Usually `redirect()->intended(route('home'))`.
- `failed()` receives `InvalidState`, `TokenRejected`, `IdentityUnavailable` or `UnknownUser`. `SignInFailure::isAccessDenied()` is true for the last two, which the existing copies answer with "access not released yet".

Bind both contracts in a service provider (or set `consys.directory.mirror` / `consys.auth.handler`):

```php
$this->app->bind(DirectoryMirror::class, App\Services\Directory\DirectoryMirror::class);
$this->app->bind(ConsysSignIn::class, App\Services\Directory\ConsysSignIn::class);
```

## Payloads

All immutable (`readonly`) and built with `::fromArray()` from the raw arrays the admin sends. Missing optional keys get defaults; a missing required key throws `InvalidPayload` (the webhook answers 422, the sync skips the entry and exits non-zero). The original array stays in `->raw`.

- `TenantPayload`: `uuid`, `externalId`, `name`, `nickname`, `isActive`, `subscriptionExpiresAt` (`Y-m-d` or null), `deleted`, `applications` (slugs). Helpers: `displayName()` (nickname or name), `hasApplication($slug)`, `usable($slug)` (has it, active, not deleted).
- `MemberPayload`: `uuid`, `name`, `email` (lowercased), `whatsapp`, `isActive`, `deleted`, `isAdmin`, `tenants` (tenant uuids the member is linked to), `access` (`array<slug, MemberAccess>`), `credentials` (`?MemberCredentials`, only for subscribers that receive them). Helpers: `accessFor($slug)`, `isEnabled()` (active and not deleted), `reaches($slug)` (has access and enabled), `isAdminOf($slug)` (platform admin that reaches the app).
- `MemberAccess`: `scope` (`AccessScope::Own|Selected|All`; unknown values fall back to `Own`), `tenants` (tenant uuids, empty for `All`). Helpers: `coversAllTenants()`, `covers($tenantUuid)`.
- `ApplicationPayload`: `slug`, `name`, `url`, `logoutUrl`, `isActive` (default true), `deleted` (default false). Helper: `isAvailable()`.

## Routes

Nothing is registered automatically. Call the helpers where the app wants them:

```php
use Consys\Ecosystem\Consys;

Route::middleware(['guest', 'throttle:20,1'])->group(function (): void {
    Consys::authRoutes();
});

Consys::logoutRoute()->middleware('throttle:30,1');

Consys::webhookRoute();
```

| Helper | Default URI | Name |
| --- | --- | --- |
| `Consys::authRoutes(string $prefix = '/auth/consys')` | `GET {prefix}/redirect`, `GET {prefix}/callback` | `auth.consys.redirect`, `auth.consys.callback` |
| `Consys::logoutRoute(string $uri = '/auth/consys/logout')` | `GET` | `auth.consys.logout` |
| `Consys::webhookRoute(string $uri = '/webhooks/directory')` | `POST` | `webhooks.directory` |

- The webhook removes the `web` group, the session and the CSRF middleware from itself, so it works from `routes/web.php` without a `validateCsrfTokens(except:)` entry and without starting a session. It is protected by `VerifyDirectorySignature`.
- The auth routes need the session: keep them in the `web` group. The callback URL sent to the login is `route('auth.consys.callback')`, so do not add a name prefix to these routes, and register that absolute URL as the redirect URI of the Passport client.
- The logout route is called by the Consys login from the browser (a hidden frame on its signed-out page). Keep it out of `guest`.
- `authRoutes()` returns `['redirect' => Route, 'callback' => Route]` and the other two helpers return the `Route`, so more middleware or constraints can be chained.

## Other helpers

```php
Consys::authEnabled();         // mode is not disabled
Consys::authRequired();        // mode is required
Consys::authUrl('/oauth/token');
Consys::signOutUrl();          // {auth}/logout
Consys::launcherUrl();         // {auth} or null while disabled / not configured
Consys::application();         // consys.directory.application
Consys::location($url);        // external redirect: 409 + X-Inertia-Location for Inertia requests, 302 otherwise

app(ConsysApplications::class)->for($user->sso_id); // list<array{slug, name, url}>, [] on any failure
```

`Consys::location()` replaces `Inertia::location()` without depending on Inertia. The host's own logout keeps its logic:

```php
if (Consys::authRequired()) {
    return Consys::location(Consys::signOutUrl());
}
```

The app switcher shared prop of the existing apps becomes:

```php
'consys_apps' => fn (): ?array => $request->user() && Consys::launcherUrl()
    ? ['launcher_url' => Consys::launcherUrl(), 'current' => Consys::application()]
    : null,
'consys_apps_list' => Inertia::optional(fn (): array => app(ConsysApplications::class)->for($request->user()?->sso_id)),
```

## Command

`php artisan directory:sync` downloads `GET {admin_url}/api/v1/directory` with the secret as Bearer token and applies the catalog (only for `MirrorsApplications`), then every tenant, then every member. It prints `Synced N tenants and M members.`, exits 0 with an info message when the admin URL or the secret is missing, and exits 1 when the admin cannot be reached, answers an HTTP error, or an entry is invalid. Schedule it in the host:

```php
Schedule::command('directory:sync')->dailyAt('04:30')->onOneServer()->withoutOverlapping();
```

## Example

A mirror and a sign in, condensed from the `alojados` copy:

```php
namespace App\Services\Directory;

use App\Models\Tenant;
use App\Models\User;
use Consys\Ecosystem\Consys;
use Consys\Ecosystem\Contracts\DirectoryMirror as Mirror;
use Consys\Ecosystem\Directory\MemberPayload;
use Consys\Ecosystem\Directory\TenantPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DirectoryMirror implements Mirror
{
    public function applyTenant(TenantPayload $payload): ?Tenant
    {
        $tenant = Tenant::withTrashed()->firstWhere('sso_id', $payload->uuid)
            ?? ($payload->externalId ? Tenant::withTrashed()->whereNull('sso_id')->firstWhere('external_id', $payload->externalId) : null);

        if ($tenant === null && (! $payload->hasApplication(Consys::application()) || $payload->deleted)) {
            return null;
        }

        $tenant ??= Tenant::create(['name' => $payload->displayName(), 'external_id' => $payload->externalId]);

        $tenant->forceFill([
            'sso_id' => $payload->uuid,
            'name' => $payload->displayName(),
            'external_id' => $tenant->external_id ?? $payload->externalId,
            'is_active' => $payload->usable(Consys::application()),
            'subscription_expires_at' => $payload->subscriptionExpiresAt,
        ])->save();

        return $tenant;
    }

    public function applyMember(MemberPayload $payload): ?User
    {
        $application = Consys::application();
        $reaches = $payload->reaches($application);
        $access = $payload->accessFor($application);

        $user = User::firstWhere('sso_id', $payload->uuid)
            ?? User::whereNull('sso_id')->whereRaw('lower(email) = ?', [$payload->email])->first();

        if ($user === null && ! $reaches) {
            return null;
        }

        $user ??= new User(['password' => Str::random(40)]);
        $user->forceFill([
            'sso_id' => $payload->uuid,
            'name' => $payload->name,
            'email' => $payload->email,
            'is_admin' => $payload->isAdminOf($application),
        ])->save();

        $tenantIds = match (true) {
            ! $reaches => [],
            $user->is_admin => Tenant::whereIn('sso_id', $payload->tenants)->pluck('id')->all(),
            $access->coversAllTenants() => Tenant::pluck('id')->all(),
            default => Tenant::whereIn('sso_id', $access->tenants)->pluck('id')->all(),
        };

        $user->tenants()->sync($tenantIds);

        if (! $reaches) {
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
        }

        return $user;
    }
}
```

```php
namespace App\Services\Directory;

use App\Models\User;
use Consys\Ecosystem\Auth\ConsysIdentity;
use Consys\Ecosystem\Auth\SignInFailure;
use Consys\Ecosystem\Contracts\ConsysSignIn as SignIn;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ConsysSignIn implements SignIn
{
    public function user(ConsysIdentity $identity): ?Authenticatable
    {
        return User::firstWhere('sso_id', $identity->uuid);
    }

    public function signedIn(Request $request, Authenticatable $user): Response
    {
        return redirect()->intended(route('home'));
    }

    public function failed(Request $request, SignInFailure $failure): Response
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $failure->isAccessDenied()
            ? 'Seu acesso ao Alojados ainda não foi liberado. Fale com a Consys.'
            : 'Não foi possível entrar pela Consys. Tente novamente.']);

        return to_route('login', ['local' => 1]);
    }
}
```

## Migrating an existing copy

For `alojados`, `tickets`, `boletins` and `painel`:

1. Require the package (path repository above) and set `CONSYS_DIRECTORY_APPLICATION` (or keep `config/consys.php`: the host's published file wins over the merged defaults, so the hardcoded `application` slug keeps working).
2. Make `App\Services\Directory\DirectoryMirror` implement `Consys\Ecosystem\Contracts\DirectoryMirror`: change the parameters from `array $payload` to `TenantPayload` / `MemberPayload` and read properties instead of keys (`$payload['access'][$app] ?? null` → `$payload->accessFor($app)`, `($payload['nickname'] ?? null) ?: $payload['name']` → `$payload->displayName()`, `mb_strtolower($payload['email'])` → `$payload->email`, `$payload['tenants'] ?? []` → `$payload->tenants`, `$access['scope'] === 'all'` → `$access->coversAllTenants()`). The return types can stay `?Tenant` / `?User`. Bind it to the contract.
3. Move the app-specific parts of `ConsysAuthController` into a `ConsysSignIn` implementation (lookup by `sso_id`, landing route, failure flash). Bind it.
4. In `routes/web.php`, replace the four route lines with `Consys::authRoutes()` (inside the same `guest` + `throttle:20,1` group), `Consys::logoutRoute()->middleware('throttle:30,1')` and `Consys::webhookRoute()`. In `painel`, replace the route in `routes/api.php` with `Consys::webhookRoute()` (it keeps the `/api` prefix of that file).
5. Replace `Inertia::location(rtrim(config('consys.auth.url'), '/').'/logout')` with `Consys::location(Consys::signOutUrl())` and `app(App\Services\Directory\ConsysApplications::class)->for($user)` with `app(Consys\Ecosystem\Auth\ConsysApplications::class)->for($user->sso_id)`.
6. Delete `app/Http/Middleware/VerifyDirectorySignature.php`, `app/Http/Controllers/Webhooks/DirectoryWebhookController.php` (`Api/` in painel), `app/Http/Controllers/Auth/ConsysAuthController.php`, `app/Services/Directory/ConsysApplications.php` and `app/Console/Commands/SyncDirectory.php`. The `webhooks/*` CSRF exception in `bootstrap/app.php` is no longer needed. Keep the `directory:sync` schedule.
7. Run the app's existing directory and auth tests. The URIs, route names, headers, session key and responses are the same, so they are expected to need only import changes and direct mirror calls rewritten to pass payload objects (`TenantPayload::fromArray([...])`).

The `auth` app is the fifth copy but also the server side of the login: it uses `config/directory.php` with `DIRECTORY_*` env names, mirrors the catalog and runs the sync in a transaction. Moving it needs `consys.directory.*` config, a mirror implementing `MirrorsApplications`, and only the directory half of this package.

## AI guidelines

`resources/boost/guidelines/core.md` holds the Consys development rules. Laravel Boost reads the guidelines of direct dependencies, so a host that uses Boost gets them in its `CLAUDE.md` / `AGENTS.md` without keeping a copy:

1. Add the package to the `packages` list of the host's `boost.json`: `"packages": ["consys/ecosystem"]` (or pick it when `php artisan boost:install` asks about third-party guidelines).
2. Run `php artisan boost:update`. Hosts created from the Laravel skeleton already run it after every `composer update`.
3. Delete the host's own copy (`.ai/guidelines/consys.md`), so the rules are not rendered twice.

Rules that belong to a single app stay in that app (`.ai/rules`, `.ai/guidelines`). To change a rule for every app, edit `core.md` here, tag a release and update the hosts.

## Development

```bash
composer install
vendor/bin/pest
vendor/bin/pint
```

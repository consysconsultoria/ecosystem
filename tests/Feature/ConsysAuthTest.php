<?php

use Consys\Ecosystem\Http\Controllers\ConsysAuthController;
use Consys\Ecosystem\Tests\Fixtures\FakeSignIn;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const MEMBER_UUID = '0199a1d2-0000-7000-8000-00000000b001';

/**
 * Start the sign in and return the query sent to the Consys login.
 *
 * @return array<string, string>
 */
function startSignIn(): array
{
    $location = test()->get('/auth/consys/redirect')->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://auth.test/oauth/authorize?');

    return $query;
}

function fakeConsysLogin(?array $user = ['uuid' => MEMBER_UUID, 'name' => 'Diego Alves', 'email' => 'Diego@Safety.test'], int $tokenStatus = 200): void
{
    Http::fake([
        'auth.test/oauth/token' => Http::response($tokenStatus === 200 ? ['access_token' => 'access-token'] : ['error' => 'invalid_grant'], $tokenStatus),
        'auth.test/api/user' => $user === null ? Http::response(['message' => 'Unauthenticated.'], 401) : Http::response($user),
    ]);
}

it('sends the browser to the Consys login with the state and the PKCE challenge', function (): void {
    $query = startSignIn();
    $pending = session(ConsysAuthController::SESSION_KEY);

    expect($query)->toMatchArray([
        'client_id' => 'erp-client',
        'redirect_uri' => route('auth.consys.callback'),
        'response_type' => 'code',
        'scope' => '',
        'state' => $pending['state'],
        'code_challenge_method' => 'S256',
    ])
        ->and($query['state'])->toHaveLength(40)
        ->and($pending['verifier'])->toHaveLength(64)
        ->and($query['code_challenge'])->toBe(strtr(rtrim(base64_encode(hash('sha256', $pending['verifier'], true)), '='), '+/', '-_'));
});

it('leaves an Inertia visit for the Consys login through a full page visit', function (): void {
    $response = $this->get('/auth/consys/redirect', ['X-Inertia' => 'true']);

    $response->assertStatus(409);

    expect($response->headers->get('X-Inertia-Location'))->toStartWith('https://auth.test/oauth/authorize?');
});

it('signs in the user the Consys login identifies', function (): void {
    $user = app(FakeSignIn::class)->register(MEMBER_UUID, id: 7);
    fakeConsysLogin();

    $state = startSignIn()['state'];
    $verifier = session(ConsysAuthController::SESSION_KEY)['verifier'];

    $this->get("/auth/consys/callback?code=auth-code&state={$state}")->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);

    expect(session()->has(ConsysAuthController::SESSION_KEY))->toBeFalse()
        ->and(app(FakeSignIn::class)->identity)->uuid->toBe(MEMBER_UUID)->email->toBe('diego@safety.test');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.test/oauth/token'
        && $request->method() === 'POST'
        && $request['grant_type'] === 'authorization_code'
        && $request['client_id'] === 'erp-client'
        && $request['client_secret'] === 'erp-client-secret'
        && $request['redirect_uri'] === route('auth.consys.callback')
        && $request['code'] === 'auth-code'
        && $request['code_verifier'] === $verifier);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.test/api/user'
        && $request->hasHeader('Authorization', 'Bearer access-token'));
});

it('returns the user to the page it asked for before signing in', function (): void {
    app(FakeSignIn::class)->register(MEMBER_UUID);
    fakeConsysLogin();
    session()->put('url.intended', 'http://localhost/reports');

    $state = startSignIn()['state'];

    $this->get("/auth/consys/callback?code=auth-code&state={$state}")->assertRedirect('http://localhost/reports');
});

it('refuses a callback with another state', function (): void {
    app(FakeSignIn::class)->register(MEMBER_UUID);
    fakeConsysLogin();
    startSignIn();

    $this->get('/auth/consys/callback?code=auth-code&state=forged')
        ->assertRedirect('/login?local=1')
        ->assertSessionHas('consys_failure', 'invalid_state');

    $this->assertGuest();
    Http::assertNothingSent();
});

it('refuses a callback without a sign in started here', function (): void {
    fakeConsysLogin();

    $this->get('/auth/consys/callback?code=auth-code&state=anything')->assertSessionHas('consys_failure', 'invalid_state');

    $this->assertGuest();
});

it('refuses a callback without the code', function (): void {
    $state = startSignIn()['state'];

    $this->get("/auth/consys/callback?state={$state}")->assertSessionHas('consys_failure', 'invalid_state');
});

it('does not accept the same state twice', function (): void {
    app(FakeSignIn::class)->register(MEMBER_UUID);
    fakeConsysLogin(tokenStatus: 400);

    $state = startSignIn()['state'];

    $this->get("/auth/consys/callback?code=auth-code&state={$state}")->assertSessionHas('consys_failure', 'token_rejected');
    $this->get("/auth/consys/callback?code=auth-code&state={$state}")->assertSessionHas('consys_failure', 'invalid_state');
});

it('refuses the sign in when the Consys login rejects the code', function (): void {
    app(FakeSignIn::class)->register(MEMBER_UUID);
    fakeConsysLogin(tokenStatus: 400);

    $state = startSignIn()['state'];

    $this->get("/auth/consys/callback?code=auth-code&state={$state}")
        ->assertRedirect('/login?local=1')
        ->assertSessionHas('consys_failure', 'token_rejected');

    $this->assertGuest();
});

it('refuses the sign in when the Consys login cannot be reached', function (): void {
    Http::fake(['auth.test/*' => unreachable()]);

    $state = startSignIn()['state'];

    $this->get("/auth/consys/callback?code=auth-code&state={$state}")->assertSessionHas('consys_failure', 'token_rejected');

    $this->assertGuest();
});

it('refuses the sign in when the Consys login does not say who signed in', function (): void {
    app(FakeSignIn::class)->register(MEMBER_UUID);
    fakeConsysLogin(user: null);

    $state = startSignIn()['state'];

    $this->get("/auth/consys/callback?code=auth-code&state={$state}")->assertSessionHas('consys_failure', 'identity_unavailable');

    $this->assertGuest();
});

it('refuses someone the directory has not brought to the app yet', function (): void {
    fakeConsysLogin();

    $state = startSignIn()['state'];

    $this->get("/auth/consys/callback?code=auth-code&state={$state}")
        ->assertRedirect('/login?local=1')
        ->assertSessionHas('consys_failure', 'unknown_user');

    $this->assertGuest();
});

it('does not expose the Consys login while it is disabled', function (): void {
    config(['consys.auth.mode' => 'disabled']);

    $this->get('/auth/consys/redirect')->assertNotFound();
    $this->get('/auth/consys/callback?code=auth-code&state=anything')->assertNotFound();
});

it('ends the app session when the Consys login signs the user out', function (): void {
    $this->actingAs(new GenericUser(['id' => 7, 'password' => 'unused', 'remember_token' => null]))
        ->get('/auth/consys/logout')
        ->assertNoContent();

    $this->assertGuest();
});

it('answers the Consys logout for a guest too', function (): void {
    $this->get('/auth/consys/logout')->assertNoContent();
});

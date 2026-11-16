<?php

namespace Consys\Ecosystem\Http\Controllers;

use Consys\Ecosystem\Auth\ConsysIdentity;
use Consys\Ecosystem\Auth\SignInFailure;
use Consys\Ecosystem\Consys;
use Consys\Ecosystem\Contracts\ConsysSignIn;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs users in through the Consys login with the OAuth authorization code flow and PKCE.
 * The login only says who the user is; the host decides what it may do.
 */
class ConsysAuthController
{
    public const string SESSION_KEY = 'consys_auth';

    /**
     * Leaves through a full page visit, since an Inertia request cannot follow a redirect to
     * the Consys login on another site and would leave the user stuck once its session ends.
     */
    public function redirect(Request $request): Response
    {
        abort_unless(Consys::authEnabled(), 404);

        $state = Str::random(40);
        $verifier = Str::random(64);

        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'verifier' => $verifier]);

        return Consys::location(Consys::authUrl('/oauth/authorize').'?'.http_build_query([
            'client_id' => config('consys.auth.client_id'),
            'redirect_uri' => route(Consys::CALLBACK_ROUTE),
            'response_type' => 'code',
            'scope' => '',
            'state' => $state,
            'code_challenge' => $this->codeChallenge($verifier),
            'code_challenge_method' => 'S256',
        ]), $request);
    }

    public function callback(Request $request, ConsysSignIn $signIn): Response
    {
        abort_unless(Consys::authEnabled(), 404);

        $pending = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($pending) || ! is_string($pending['state'] ?? null) || ! is_string($pending['verifier'] ?? null)
            || ! hash_equals($pending['state'], (string) $request->query('state')) || ! $request->filled('code')) {
            return $signIn->failed($request, SignInFailure::InvalidState);
        }

        $accessToken = $this->exchangeCode((string) $request->query('code'), $pending['verifier']);

        if ($accessToken === null) {
            return $signIn->failed($request, SignInFailure::TokenRejected);
        }

        $identity = $this->identity($accessToken);

        if ($identity === null) {
            return $signIn->failed($request, SignInFailure::IdentityUnavailable);
        }

        $user = $signIn->user($identity);

        if ($user === null) {
            return $signIn->failed($request, SignInFailure::UnknownUser);
        }

        Auth::guard(config('consys.auth.guard'))->login($user);
        $request->session()->regenerate();

        return $signIn->signedIn($request, $user);
    }

    /**
     * Called by the Consys login from the browser when the user signs out anywhere, so the
     * session in the app ends too.
     */
    public function logout(Request $request): Response
    {
        $guard = Auth::guard(config('consys.auth.guard'));

        if ($guard->check()) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    private function exchangeCode(string $code, string $verifier): ?string
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(10)->post(Consys::authUrl('/oauth/token'), [
                'grant_type' => 'authorization_code',
                'client_id' => config('consys.auth.client_id'),
                'client_secret' => config('consys.auth.client_secret'),
                'redirect_uri' => route(Consys::CALLBACK_ROUTE),
                'code' => $code,
                'code_verifier' => $verifier,
            ]);
        } catch (ConnectionException) {
            return null;
        }

        $accessToken = $response->successful() ? $response->json('access_token') : null;

        return is_string($accessToken) && $accessToken !== '' ? $accessToken : null;
    }

    private function identity(string $accessToken): ?ConsysIdentity
    {
        try {
            $response = Http::withToken($accessToken)->acceptJson()->timeout(10)->get(Consys::authUrl('/api/user'));
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? ConsysIdentity::fromArray((array) $response->json()) : null;
    }

    private function codeChallenge(string $verifier): string
    {
        return strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_');
    }
}

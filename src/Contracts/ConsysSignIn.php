<?php

namespace Consys\Ecosystem\Contracts;

use Consys\Ecosystem\Auth\ConsysIdentity;
use Consys\Ecosystem\Auth\SignInFailure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The host's side of the sign in through the Consys login. The login only says who the
 * member is; the host decides whether it may enter and where it lands.
 */
interface ConsysSignIn
{
    /**
     * The local user of the member the Consys login identified, or null when it may not enter.
     */
    public function user(ConsysIdentity $identity): ?Authenticatable;

    /**
     * Called after the user was signed in and the session regenerated.
     */
    public function signedIn(Request $request, Authenticatable $user): Response;

    public function failed(Request $request, SignInFailure $failure): Response;
}

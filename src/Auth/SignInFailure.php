<?php

namespace Consys\Ecosystem\Auth;

/**
 * Why a sign in through the Consys login did not finish.
 */
enum SignInFailure: string
{
    case InvalidState = 'invalid_state';
    case TokenRejected = 'token_rejected';
    case IdentityUnavailable = 'identity_unavailable';
    case UnknownUser = 'unknown_user';

    /**
     * The member signed in at the Consys login but may not enter this app, as opposed to a
     * sign in that broke along the way and is worth trying again.
     */
    public function isAccessDenied(): bool
    {
        return $this === self::IdentityUnavailable || $this === self::UnknownUser;
    }
}

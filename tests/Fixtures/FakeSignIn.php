<?php

namespace Consys\Ecosystem\Tests\Fixtures;

use Consys\Ecosystem\Auth\ConsysIdentity;
use Consys\Ecosystem\Auth\SignInFailure;
use Consys\Ecosystem\Contracts\ConsysSignIn;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Knows the members registered in it by uuid and lands them on /home.
 */
class FakeSignIn implements ConsysSignIn
{
    /**
     * @var array<string, GenericUser>
     */
    public array $users = [];

    public ?ConsysIdentity $identity = null;

    public function register(string $uuid, int $id = 1): GenericUser
    {
        return $this->users[$uuid] = new GenericUser(['id' => $id, 'sso_id' => $uuid, 'password' => 'unused', 'remember_token' => null]);
    }

    public function user(ConsysIdentity $identity): ?Authenticatable
    {
        $this->identity = $identity;

        return $this->users[$identity->uuid] ?? null;
    }

    public function signedIn(Request $request, Authenticatable $user): Response
    {
        return redirect()->intended(route('home'));
    }

    public function failed(Request $request, SignInFailure $failure): Response
    {
        return redirect()->route('login', ['local' => 1])->with('consys_failure', $failure->value);
    }
}

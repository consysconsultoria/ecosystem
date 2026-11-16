<?php

namespace Consys\Ecosystem\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts only webhooks the admin signed with the shared secret in the last few minutes.
 * The signature is "sha256=" plus the HMAC-SHA256 of "timestamp.body".
 */
class VerifyDirectorySignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('consys.directory.secret');
        $timestamp = (string) $request->header('X-Consys-Timestamp');
        $signature = (string) $request->header('X-Consys-Signature');

        if ($secret === '' || ! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > (int) config('consys.directory.tolerance_seconds', 300)) {
            return $this->reject();
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return $this->reject();
        }

        return $next($request);
    }

    private function reject(): Response
    {
        return response()->json(['error' => 'Invalid signature'], 401);
    }
}

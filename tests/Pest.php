<?php

use Consys\Ecosystem\Tests\TestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Testing\TestResponse;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * A stub for Http::fake that fails as an unreachable host would.
 */
function unreachable(): Closure
{
    return fn (Request $request): PromiseInterface => Create::rejectionFor(new ConnectException('Connection timed out', $request->toPsrRequest()));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tenantData(array $overrides = []): array
{
    return [
        'uuid' => '0199a1d2-0000-7000-8000-00000000a001',
        'external_id' => '972',
        'name' => 'SAFETY HOME COMERCIO',
        'nickname' => 'Safety Home',
        'is_active' => true,
        'subscription_expires_at' => '2027-01-31',
        'deleted' => false,
        'applications' => ['erp', 'tickets'],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function memberData(array $overrides = []): array
{
    return [
        'uuid' => '0199a1d2-0000-7000-8000-00000000b001',
        'name' => 'Diego Alves',
        'email' => 'Diego@Safety.test',
        'whatsapp' => '5511999990000',
        'is_active' => true,
        'deleted' => false,
        'tenants' => ['0199a1d2-0000-7000-8000-00000000a001'],
        'is_admin' => false,
        'access' => [
            'erp' => ['scope' => 'selected', 'tenants' => ['0199a1d2-0000-7000-8000-00000000a001']],
            'tickets' => ['scope' => 'all', 'tenants' => []],
        ],
        ...$overrides,
    ];
}

/**
 * Posts a webhook signed as the admin signs it.
 *
 * @param  array<string, mixed>  $data
 */
function sendWebhook(string $type, array $data, string $secret = 'directory-secret', ?int $timestamp = null, ?string $deliveredBody = null): TestResponse
{
    $body = json_encode(['id' => '0199a1d2-0000-7000-8000-00000000e001', 'type' => $type, 'occurred_at' => now()->toIso8601String(), 'data' => $data]);
    $timestamp = (string) ($timestamp ?? now()->timestamp);

    return test()->call('POST', '/webhooks/directory', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CONSYS_EVENT' => $type,
        'HTTP_X_CONSYS_TIMESTAMP' => $timestamp,
        'HTTP_X_CONSYS_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ], content: $deliveredBody ?? $body);
}

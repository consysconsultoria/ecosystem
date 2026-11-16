<?php

use Consys\Ecosystem\Tests\Fixtures\RecordingMirror;

it('accepts a webhook the admin signed with the shared secret', function (): void {
    sendWebhook('tenant.updated', tenantData())->assertOk()->assertExactJson(['received' => true]);

    expect(app(RecordingMirror::class)->calls)->toBe(['tenant:0199a1d2-0000-7000-8000-00000000a001']);
});

it('accepts a webhook signed within the tolerance', function (): void {
    sendWebhook('tenant.updated', tenantData(), timestamp: now()->subSeconds(299)->timestamp)->assertOk();
});

it('refuses a webhook signed with another secret', function (): void {
    sendWebhook('tenant.updated', tenantData(), secret: 'forged-secret')
        ->assertUnauthorized()
        ->assertExactJson(['error' => 'Invalid signature']);

    expect(app(RecordingMirror::class)->calls)->toBe([]);
});

it('refuses a webhook signed too long ago or too far ahead', function (int $offset): void {
    sendWebhook('tenant.updated', tenantData(), timestamp: now()->timestamp + $offset)->assertUnauthorized();

    expect(app(RecordingMirror::class)->calls)->toBe([]);
})->with([
    'stale' => [-301],
    'ahead' => [301],
]);

it('refuses a webhook whose body changed after it was signed', function (): void {
    $tampered = json_encode(['type' => 'tenant.updated', 'data' => tenantData(['is_active' => false])]);

    sendWebhook('tenant.updated', tenantData(), deliveredBody: $tampered)->assertUnauthorized();

    expect(app(RecordingMirror::class)->calls)->toBe([]);
});

it('refuses every webhook while the secret is not configured', function (?string $secret): void {
    config(['consys.directory.secret' => $secret]);

    sendWebhook('tenant.updated', tenantData(), secret: '')->assertUnauthorized();

    expect(app(RecordingMirror::class)->calls)->toBe([]);
})->with([
    'null' => [null],
    'empty' => [''],
]);

it('refuses a webhook without the signature headers', function (): void {
    $this->postJson('/webhooks/directory', ['type' => 'tenant.updated', 'data' => tenantData()])->assertUnauthorized();
});

it('refuses a webhook with a timestamp that is not a number', function (): void {
    $body = json_encode(['type' => 'tenant.updated', 'data' => tenantData()]);

    $this->call('POST', '/webhooks/directory', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CONSYS_TIMESTAMP' => 'now',
        'HTTP_X_CONSYS_SIGNATURE' => 'sha256='.hash_hmac('sha256', 'now.'.$body, 'directory-secret'),
    ], content: $body)->assertUnauthorized();
});

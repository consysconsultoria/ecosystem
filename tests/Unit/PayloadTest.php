<?php

use Consys\Ecosystem\Directory\AccessScope;
use Consys\Ecosystem\Directory\ApplicationPayload;
use Consys\Ecosystem\Directory\InvalidPayload;
use Consys\Ecosystem\Directory\MemberPayload;
use Consys\Ecosystem\Directory\TenantPayload;

it('reads an organization as the admin sends it', function (): void {
    $tenant = TenantPayload::fromArray(tenantData());

    expect($tenant)
        ->uuid->toBe('0199a1d2-0000-7000-8000-00000000a001')
        ->externalId->toBe('972')
        ->name->toBe('SAFETY HOME COMERCIO')
        ->nickname->toBe('Safety Home')
        ->isActive->toBeTrue()
        ->subscriptionExpiresAt->toBe('2027-01-31')
        ->deleted->toBeFalse()
        ->applications->toBe(['erp', 'tickets'])
        ->and($tenant->displayName())->toBe('Safety Home')
        ->and($tenant->hasApplication('erp'))->toBeTrue()
        ->and($tenant->hasApplication('painel'))->toBeFalse()
        ->and($tenant->usable('erp'))->toBeTrue()
        ->and($tenant->raw)->toBe(tenantData());
});

it('defaults the optional keys of an organization', function (): void {
    $tenant = TenantPayload::fromArray(['uuid' => 'tenant-uuid', 'name' => 'Safety']);

    expect($tenant)
        ->externalId->toBeNull()
        ->nickname->toBeNull()
        ->isActive->toBeFalse()
        ->subscriptionExpiresAt->toBeNull()
        ->deleted->toBeFalse()
        ->applications->toBe([])
        ->and($tenant->displayName())->toBe('Safety');
});

it('shows the registered name of an organization with a blank nickname', function (): void {
    expect(TenantPayload::fromArray(tenantData(['nickname' => '']))->displayName())->toBe('SAFETY HOME COMERCIO');
});

it('does not let a deleted or inactive organization use the application', function (array $overrides): void {
    expect(TenantPayload::fromArray(tenantData($overrides))->usable('erp'))->toBeFalse();
})->with([
    'deleted' => [['deleted' => true]],
    'inactive' => [['is_active' => false]],
    'without the application' => [['applications' => ['tickets']]],
]);

it('reads a member with its access per application', function (): void {
    $member = MemberPayload::fromArray(memberData(['credentials' => ['password_hash' => '$2y$hash', 'must_change_password' => true]]));

    expect($member)
        ->email->toBe('diego@safety.test')
        ->whatsapp->toBe('5511999990000')
        ->isAdmin->toBeFalse()
        ->tenants->toBe(['0199a1d2-0000-7000-8000-00000000a001'])
        ->and($member->accessFor('erp')->scope)->toBe(AccessScope::Selected)
        ->and($member->accessFor('erp')->tenants)->toBe(['0199a1d2-0000-7000-8000-00000000a001'])
        ->and($member->accessFor('erp')->covers('0199a1d2-0000-7000-8000-00000000a001'))->toBeTrue()
        ->and($member->accessFor('erp')->covers('another'))->toBeFalse()
        ->and($member->accessFor('tickets')->coversAllTenants())->toBeTrue()
        ->and($member->accessFor('tickets')->covers('another'))->toBeTrue()
        ->and($member->accessFor('painel'))->toBeNull()
        ->and($member->credentials->passwordHash)->toBe('$2y$hash')
        ->and($member->credentials->mustChangePassword)->toBeTrue();
});

it('defaults the optional keys of a member', function (): void {
    $member = MemberPayload::fromArray(['uuid' => 'member-uuid', 'name' => 'Diego', 'email' => 'diego@safety.test', 'is_active' => true, 'deleted' => false]);

    expect($member)
        ->whatsapp->toBeNull()
        ->isAdmin->toBeFalse()
        ->tenants->toBe([])
        ->access->toBe([])
        ->credentials->toBeNull()
        ->and($member->reaches('erp'))->toBeFalse();
});

it('falls back to the own scope for a scope it does not know', function (): void {
    $member = MemberPayload::fromArray(memberData(['access' => ['erp' => ['scope' => 'everything', 'tenants' => ['a']]]]));

    expect($member->accessFor('erp'))->scope->toBe(AccessScope::Own)->tenants->toBe(['a']);
});

it('lets a member reach an application only with access while active and not deleted', function (array $overrides, bool $reaches): void {
    $member = MemberPayload::fromArray(memberData($overrides));

    expect($member->reaches('erp'))->toBe($reaches);
})->with([
    'with access' => [[], true],
    'inactive' => [['is_active' => false], false],
    'deleted' => [['deleted' => true], false],
    'without access to the application' => [['access' => ['tickets' => ['scope' => 'all', 'tenants' => []]]], false],
]);

it('makes a platform administrator an administrator only of the applications it reaches', function (): void {
    $member = MemberPayload::fromArray(memberData(['is_admin' => true]));

    expect($member->isAdminOf('erp'))->toBeTrue()
        ->and($member->isAdminOf('painel'))->toBeFalse()
        ->and(MemberPayload::fromArray(memberData(['is_admin' => true, 'is_active' => false]))->isAdminOf('erp'))->toBeFalse();
});

it('reads an application of the whole download as available', function (): void {
    $application = ApplicationPayload::fromArray(['slug' => 'erp', 'name' => 'ERP', 'url' => 'https://erp.test', 'logout_url' => null]);

    expect($application)
        ->slug->toBe('erp')
        ->url->toBe('https://erp.test')
        ->logoutUrl->toBeNull()
        ->and($application->isAvailable())->toBeTrue()
        ->and(ApplicationPayload::fromArray(['slug' => 'erp', 'name' => 'ERP', 'is_active' => false, 'deleted' => false])->isAvailable())->toBeFalse()
        ->and(ApplicationPayload::fromArray(['slug' => 'erp', 'name' => 'ERP', 'is_active' => true, 'deleted' => true])->isAvailable())->toBeFalse();
});

it('refuses a payload without its required keys', function (Closure $parse): void {
    expect($parse)->toThrow(InvalidPayload::class);
})->with([
    'tenant without uuid' => [fn () => TenantPayload::fromArray(['name' => 'Safety'])],
    'member without email' => [fn () => MemberPayload::fromArray(['uuid' => 'member-uuid', 'name' => 'Diego'])],
    'application without slug' => [fn () => ApplicationPayload::fromArray(['name' => 'ERP'])],
]);

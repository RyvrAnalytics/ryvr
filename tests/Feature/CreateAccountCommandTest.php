<?php

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;

it('creates an owner, an organization and a password link without setting a known password', function () {
    $this->artisan('ryvr:account:create', ['email' => 'Owner@Example.com', 'name' => 'Owner', 'organization' => 'Example Co', '--admin' => true])
        ->expectsOutputToContain('/reset-password/')
        ->assertSuccessful();

    $user = User::where('email', 'owner@example.com')->firstOrFail();
    $organization = Organization::where('name', 'Example Co')->firstOrFail();

    expect($user->is_instance_admin)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($organization->memberships()->where('user_id', $user->id)->value('role'))->toBe(Role::Owner);
});

it('is idempotent for the same owner and organization', function () {
    $args = ['email' => 'owner@example.com', 'name' => 'Owner', 'organization' => 'Example Co'];
    $this->artisan('ryvr:account:create', $args)->assertSuccessful();
    $this->artisan('ryvr:account:create', $args)->assertSuccessful();

    expect(User::where('email', 'owner@example.com')->count())->toBe(1)
        ->and(Organization::where('name', 'Example Co')->count())->toBe(1);
});

it('rejects an invalid email', function () {
    $this->artisan('ryvr:account:create', ['email' => 'nope', 'name' => 'X', 'organization' => 'Y'])->assertFailed();
});

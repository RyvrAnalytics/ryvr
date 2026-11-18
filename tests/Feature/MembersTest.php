<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Tenancy\Registration;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakeClickHouse;

beforeEach(function () {
    Mail::fake();
    $this->app->instance(ClickHouse::class, new FakeClickHouse);
    $this->app->instance('clickhouse.read', $this->app->make(ClickHouse::class));
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->site = Project::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'domain' => 'acme.com']);
    $this->owner = User::factory()->create();
    $this->org->addMember($this->owner, Role::Owner);
});

it('invites a member who then joins with the invited role', function () {
    $this->actingAs($this->owner)->post('/organization/members/invite', [
        'email' => 'client@example.com', 'role' => 'viewer', 'project_access' => 'granted_only', 'project_ids' => [$this->site->id],
    ])->assertSessionHas('invitationLink');

    $link = session('invitationLink');
    $token = basename(parse_url($link, PHP_URL_PATH));
    auth()->logout();

    $this->get("/invitations/{$token}")->assertOk();
    $this->post('/register', ['name' => 'Client', 'email' => 'client@example.com', 'password' => 'a-Long-passw0rd!', 'password_confirmation' => 'a-Long-passw0rd!'])
        ->assertRedirect(route('invitations.show', $token));

    $client = User::where('email', 'client@example.com')->first();
    expect(Membership::where('user_id', $client->id)->exists())->toBeFalse()
        ->and(Invitation::first()->accepted_at)->toBeNull();

    $this->get("/invitations/{$token}")->assertOk();
    $this->post(route('invitations.accept', $token))->assertRedirect(route('sites.index'));
    $membership = Membership::where('user_id', $client->id)->first();

    expect($membership->role)->toBe(Role::Viewer)
        ->and($membership->project_access)->toBe('granted_only')
        ->and($client->email_verified_at)->not->toBeNull()
        ->and(Invitation::first()->accepted_at)->not->toBeNull();
});

it('closes registration once the instance has users', function () {
    $this->post('/register', ['name' => 'Stranger', 'email' => 'stranger@example.com', 'password' => 'a-Long-passw0rd!', 'password_confirmation' => 'a-Long-passw0rd!'])
        ->assertSessionHasErrors('email');

    expect(User::where('email', 'stranger@example.com')->exists())->toBeFalse();
});

it('makes the first account an instance admin', function () {
    User::query()->delete();
    expect(Registration::open())->toBeTrue();

    $this->post('/register', ['name' => 'First', 'email' => 'first@example.com', 'password' => 'a-Long-passw0rd!', 'password_confirmation' => 'a-Long-passw0rd!']);

    expect(User::where('email', 'first@example.com')->first()->is_instance_admin)->toBeTrue();
});

it('keeps at least one owner', function () {
    $membership = Membership::where('user_id', $this->owner->id)->first();

    // Nobody edits their own membership, so the last owner cannot demote themselves either.
    $this->actingAs($this->owner)->put("/organization/members/{$membership->id}", ['role' => 'viewer', 'project_access' => 'all'])
        ->assertForbidden();
    $this->actingAs($this->owner)->delete("/organization/members/{$membership->id}")->assertSessionHasErrors('role');
});

it('does not let admins create or demote owners', function () {
    $admin = User::factory()->create();
    $this->org->addMember($admin, Role::Admin);
    $ownerMembership = Membership::where('user_id', $this->owner->id)->first();

    $this->actingAs($admin)->post('/organization/members/invite', ['email' => 'x@example.com', 'role' => 'owner', 'project_access' => 'all'])->assertForbidden();
    $this->actingAs($admin)->put("/organization/members/{$ownerMembership->id}", ['role' => 'viewer', 'project_access' => 'all'])->assertForbidden();
});

it('issues API keys that are stored only as digests', function () {
    $this->actingAs($this->owner)->post('/organization/api-keys', ['name' => 'Script', 'type' => 'pat', 'scopes' => ['analytics:read']])
        ->assertSessionHas('newToken');

    $plain = session('newToken');
    $key = ApiKey::first();

    expect($plain)->toStartWith('ryvr_pat_')
        ->and($key->token_hash)->not->toBe($plain)
        ->and(ApiKey::findActive($plain)?->id)->toBe($key->id);

    $this->actingAs($this->owner)->delete("/organization/api-keys/{$key->id}");
    expect(ApiKey::findActive($plain))->toBeNull();
});

it('writes an audit trail for access changes', function () {
    $this->actingAs($this->owner)->post('/organization/exclusions', ['field' => 'ip', 'operator' => 'cidr', 'value' => '10.0.0.0/8', 'note' => 'Office']);
    $this->actingAs($this->owner)->post('/organization/api-keys', ['name' => 'Script', 'type' => 'org', 'scopes' => ['analytics:read']]);

    expect(AuditLog::pluck('action')->all())->toContain('exclusion.created', 'api_key.created');
});

it('validates exclusion rules', function (array $input, string $field) {
    $this->actingAs($this->owner)->post('/organization/exclusions', $input)->assertSessionHasErrors($field);
})->with([
    'bad cidr' => [['field' => 'ip', 'operator' => 'cidr', 'value' => '10.0.0.0/99'], 'value'],
    'bad ip' => [['field' => 'ip', 'operator' => 'equals', 'value' => 'not-an-ip'], 'value'],
    'bad regex' => [['field' => 'path', 'operator' => 'regex', 'value' => '(unclosed'], 'value'],
    'operator not for field' => [['field' => 'ip', 'operator' => 'regex', 'value' => '.*'], 'operator'],
    'bad country' => [['field' => 'country', 'operator' => 'equals', 'value' => 'Canada'], 'value'],
]);

it('will not delete the only owner of an organization others still use', function () {
    $other = User::factory()->create();
    $this->org->addMember($other, Role::Viewer);

    $this->actingAs($this->owner)->delete('/settings/profile', ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect(User::find($this->owner->id))->not->toBeNull()
        ->and(Organization::find($this->org->id))->not->toBeNull();
});

it('deletes organizations the user was the only member of along with the account', function () {
    $this->actingAs($this->owner)->delete('/settings/profile', ['password' => 'password'])
        ->assertRedirect('/');

    expect(User::find($this->owner->id))->toBeNull()
        ->and(Organization::find($this->org->id))->toBeNull()
        ->and(Project::find($this->site->id))->toBeNull();
});

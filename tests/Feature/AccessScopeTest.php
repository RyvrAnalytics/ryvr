<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\EmbedKey;
use App\Models\ExclusionRule;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectGrant;
use App\Models\ProjectGroup;
use App\Models\User;
use App\Tenancy\Access;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeClickHouse;

/*
 * A site-limited Admin manages members, API keys and embed keys, but never hands out (or
 * takes for themselves) more than the sites they hold.
 */

beforeEach(function () {
    Mail::fake();
    $this->app->instance(ClickHouse::class, new FakeClickHouse);

    $this->org = Organization::create(['name' => 'Agency', 'slug' => 'agency']);
    $this->mine = Project::create(['organization_id' => $this->org->id, 'name' => 'Client A', 'domain' => 'a.example']);
    $this->other = Project::create(['organization_id' => $this->org->id, 'name' => 'Client B', 'domain' => 'b.example']);

    $this->owner = User::factory()->create();
    $this->org->addMember($this->owner, Role::Owner);

    // An Admin limited to Client A.
    $this->admin = User::factory()->create();
    $this->adminMembership = $this->org->addMember($this->admin, Role::Admin, 'granted_only');
    ProjectGrant::create(['organization_id' => $this->org->id, 'user_id' => $this->admin->id, 'project_id' => $this->mine->id, 'role' => Role::Admin]);

    // A Viewer limited to Client A, whom the admin may manage.
    $this->client = User::factory()->create();
    $this->clientMembership = $this->org->addMember($this->client, Role::Viewer, 'granted_only');
    ProjectGrant::create(['organization_id' => $this->org->id, 'user_id' => $this->client->id, 'project_id' => $this->mine->id, 'role' => Role::Viewer]);
});

function adminAccess(): Access
{
    return Access::forUser(test()->admin, test()->org);
}

// Members

it('refuses a site-limited admin widening their own membership to all sites', function () {
    $this->actingAs($this->admin)->put("/organization/members/{$this->adminMembership->id}", [
        'role' => 'admin', 'project_access' => 'all',
    ])->assertForbidden();

    expect($this->adminMembership->fresh()->project_access)->toBe('granted_only')
        ->and(adminAccess()->projectIds())->toBe([$this->mine->id]);
});

it('refuses a site-limited admin granting themselves another site', function () {
    $this->actingAs($this->admin)->put("/organization/members/{$this->adminMembership->id}", [
        'role' => 'admin', 'project_access' => 'granted_only',
        'grants' => [['project_id' => $this->mine->id, 'role' => 'admin'], ['project_id' => $this->other->id, 'role' => 'admin']],
    ])->assertForbidden();

    expect(adminAccess()->projectIds())->toBe([$this->mine->id]);
});

it('refuses any member changing their own role or sites, owners included', function () {
    $ownerMembership = Membership::where('user_id', $this->owner->id)->first();

    $this->actingAs($this->owner)->put("/organization/members/{$ownerMembership->id}", [
        'role' => 'admin', 'project_access' => 'all',
    ])->assertForbidden();

    expect($ownerMembership->fresh()->role)->toBe(Role::Owner);
});

it('refuses a site-limited admin giving someone else all sites', function () {
    $this->actingAs($this->admin)->put("/organization/members/{$this->clientMembership->id}", [
        'role' => 'viewer', 'project_access' => 'all',
    ])->assertForbidden();

    expect($this->clientMembership->fresh()->project_access)->toBe('granted_only');
});

it('refuses a site-limited admin granting a site outside their own', function () {
    $this->actingAs($this->admin)->put("/organization/members/{$this->clientMembership->id}", [
        'role' => 'viewer', 'project_access' => 'granted_only',
        'grants' => [['project_id' => $this->other->id, 'role' => 'viewer']],
    ])->assertForbidden();

    expect(ProjectGrant::where('user_id', $this->client->id)->pluck('project_id')->all())->toBe([$this->mine->id]);
});

it('refuses a site-limited admin granting a stronger role than they hold', function () {
    $analyst = User::factory()->create();
    $membership = $this->org->addMember($analyst, Role::Analyst, 'granted_only');
    ProjectGrant::where('user_id', $this->admin->id)->update(['role' => Role::Analyst->value]);
    ProjectGrant::create(['organization_id' => $this->org->id, 'user_id' => $analyst->id, 'project_id' => $this->mine->id, 'role' => Role::Viewer]);

    $this->actingAs($this->admin)->put("/organization/members/{$membership->id}", [
        'role' => 'analyst', 'project_access' => 'granted_only',
        'grants' => [['project_id' => $this->mine->id, 'role' => 'admin']],
    ])->assertForbidden();
});

it('refuses a site-limited admin granting a group they do not hold', function () {
    $group = ProjectGroup::create(['organization_id' => $this->org->id, 'name' => 'Everything']);
    $group->projects()->attach([$this->mine->id, $this->other->id]);

    $this->actingAs($this->admin)->put("/organization/members/{$this->clientMembership->id}", [
        'role' => 'viewer', 'project_access' => 'granted_only',
        'grants' => [['group_id' => $group->id, 'role' => 'viewer']],
    ])->assertForbidden();
});

it('lets a site-limited admin grant a group they hold', function () {
    $group = ProjectGroup::create(['organization_id' => $this->org->id, 'name' => 'Mine']);
    $group->projects()->attach([$this->mine->id]);
    ProjectGrant::create(['organization_id' => $this->org->id, 'user_id' => $this->admin->id, 'project_group_id' => $group->id, 'role' => Role::Analyst]);

    $this->actingAs($this->admin)->put("/organization/members/{$this->clientMembership->id}", [
        'role' => 'viewer', 'project_access' => 'granted_only',
        'grants' => [['group_id' => $group->id, 'role' => 'viewer']],
    ])->assertRedirect();

    expect(ProjectGrant::where('user_id', $this->client->id)->value('project_group_id'))->toBe($group->id);
});

it('refuses a site-limited admin managing members with broader access', function () {
    $wide = User::factory()->create();
    $wideMembership = $this->org->addMember($wide, Role::Analyst, 'all');

    $this->actingAs($this->admin)->put("/organization/members/{$wideMembership->id}", [
        'role' => 'viewer', 'project_access' => 'granted_only', 'grants' => [['project_id' => $this->mine->id, 'role' => 'viewer']],
    ])->assertForbidden();
    $this->actingAs($this->admin)->delete("/organization/members/{$wideMembership->id}")->assertForbidden();

    expect($wideMembership->fresh()->project_access)->toBe('all');
});

it('lets a site-limited admin manage members inside their own sites', function () {
    $this->actingAs($this->admin)->put("/organization/members/{$this->clientMembership->id}", [
        'role' => 'analyst', 'project_access' => 'granted_only',
        'grants' => [['project_id' => $this->mine->id, 'role' => 'analyst']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->clientMembership->fresh()->role)->toBe(Role::Analyst);

    $this->actingAs($this->admin)->delete("/organization/members/{$this->clientMembership->id}")->assertRedirect();
    expect(Membership::find($this->clientMembership->id))->toBeNull();
});

it('lets owners and organization-wide admins give access to all sites', function () {
    $wideAdmin = User::factory()->create();
    $this->org->addMember($wideAdmin, Role::Admin, 'all');

    $this->actingAs($wideAdmin)->put("/organization/members/{$this->clientMembership->id}", [
        'role' => 'analyst', 'project_access' => 'all',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->clientMembership->fresh()->project_access)->toBe('all');
});

it('refuses site-limited roles that are not project roles', function () {
    $this->actingAs($this->owner)->put("/organization/members/{$this->clientMembership->id}", [
        'role' => 'owner', 'project_access' => 'granted_only',
    ])->assertSessionHasErrors('role');
});

// Invitations

it('refuses a site-limited admin inviting someone to all sites', function () {
    $this->actingAs($this->admin)->post('/organization/members/invite', [
        'email' => 'friend@example.com', 'role' => 'admin', 'project_access' => 'all',
    ])->assertForbidden();

    expect(Invitation::count())->toBe(0);
});

it('refuses a site-limited admin inviting someone to a site outside their own', function () {
    $this->actingAs($this->admin)->post('/organization/members/invite', [
        'email' => 'friend@example.com', 'role' => 'viewer', 'project_access' => 'granted_only', 'project_ids' => [$this->other->id],
    ])->assertForbidden();

    expect(Invitation::count())->toBe(0);
});

it('lets a site-limited admin invite someone to their own sites', function () {
    $this->actingAs($this->admin)->post('/organization/members/invite', [
        'email' => 'friend@example.com', 'role' => 'viewer', 'project_access' => 'granted_only', 'project_ids' => [$this->mine->id],
    ])->assertSessionHas('invitationLink');

    expect(Invitation::first()->project_ids)->toBe([$this->mine->id]);
});

it('refuses a site-limited admin revoking an invitation broader than their access', function () {
    [$invitation] = Invitation::issue([
        'organization_id' => $this->org->id, 'email' => 'wide@example.com', 'role' => Role::Analyst,
        'project_access' => 'all', 'project_ids' => null, 'invited_by' => $this->owner->id,
    ]);

    $this->actingAs($this->admin)->delete("/organization/invitations/{$invitation->id}")->assertForbidden();
    expect(Invitation::find($invitation->id))->not->toBeNull();
});

it('tells the members page what a site-limited admin may grant', function () {
    $this->actingAs($this->admin)->get('/organization/members')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canGrantAllSites', false)
            ->where('grantableProjectIds', [$this->mine->id])
            ->where('members', fn ($members) => collect($members)->firstWhere('is_you', true)['manageable'] === false
                && collect($members)->firstWhere('user_id', $this->client->id)['manageable'] === true
                && collect($members)->firstWhere('user_id', $this->owner->id)['manageable'] === false)
        );
});

// API keys

it('refuses a site-limited admin creating an organization key for every site', function () {
    $this->actingAs($this->admin)->post('/organization/api-keys', [
        'name' => 'Backdoor', 'type' => 'org', 'scopes' => ['analytics:read'],
    ])->assertForbidden();

    expect(ApiKey::count())->toBe(0);
});

it('refuses keys limited to sites the creator cannot read', function (string $type) {
    $this->actingAs($this->admin)->post('/organization/api-keys', [
        'name' => 'Sneaky', 'type' => $type, 'scopes' => ['analytics:read'], 'project_ids' => [$this->mine->id, $this->other->id],
    ])->assertForbidden();

    expect(ApiKey::count())->toBe(0);
})->with(['org', 'pat']);

it('lets a site-limited admin create keys for their own sites', function () {
    $this->actingAs($this->admin)->post('/organization/api-keys', [
        'name' => 'Client A server', 'type' => 'org', 'scopes' => ['analytics:read'], 'project_ids' => [$this->mine->id],
    ])->assertSessionHas('newToken');

    $key = ApiKey::first();
    expect($key->project_ids)->toBe([$this->mine->id])
        ->and(Access::forApiKey($key)->projectIds())->toBe([$this->mine->id]);

    // A personal token without a list follows its owner's access.
    $this->actingAs($this->admin)->post('/organization/api-keys', [
        'name' => 'Mine', 'type' => 'pat', 'scopes' => ['analytics:read'],
    ])->assertSessionHas('newToken');

    expect(Access::forApiKey(ApiKey::where('type', 'pat')->first())->projectIds())->toBe([$this->mine->id]);
});

it('lets owners create organization keys for every site', function () {
    $this->actingAs($this->owner)->post('/organization/api-keys', [
        'name' => 'Warehouse', 'type' => 'org', 'scopes' => ['analytics:read'],
    ])->assertSessionHas('newToken');

    expect(ApiKey::first()->project_ids)->toBeNull();
});

it('refuses a site-limited admin revoking keys that reach other sites', function () {
    [$wide] = ApiKey::issue(['organization_id' => $this->org->id, 'name' => 'Warehouse', 'type' => 'org', 'scopes' => ['analytics:read']]);

    $this->actingAs($this->admin)->delete("/organization/api-keys/{$wide->id}")->assertForbidden();
    expect($wide->fresh()->revoked_at)->toBeNull();
});

// Embed keys

function embedKeyPayload(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Portal',
        'allowed_origins' => ['https://portal.example.com'],
        'widgets' => ['kpi'],
        'max_ttl_seconds' => 600,
    ];
}

it('refuses a site-limited admin creating an embed key without a site limit', function () {
    $this->actingAs($this->admin)->post('/organization/embedding', embedKeyPayload())->assertForbidden();

    expect(EmbedKey::count())->toBe(0);
});

it('refuses an embed key limited to sites the creator cannot read', function () {
    $this->actingAs($this->admin)->post('/organization/embedding', embedKeyPayload(['project_ids' => [$this->other->id]]))->assertForbidden();

    expect(EmbedKey::count())->toBe(0);
});

it('lets a site-limited admin create an embed key for their own sites', function () {
    $this->actingAs($this->admin)->post('/organization/embedding', embedKeyPayload(['project_ids' => [$this->mine->id]]))
        ->assertSessionHas('newEmbedKey');

    expect(EmbedKey::first()->project_ids)->toBe([$this->mine->id]);
});

it('lets owners create embed keys without a site limit', function () {
    $this->actingAs($this->owner)->post('/organization/embedding', embedKeyPayload())->assertSessionHas('newEmbedKey');

    expect(EmbedKey::first()->project_ids)->toBeNull();
});

it('refuses a site-limited admin rotating or revoking an embed key that reaches other sites', function () {
    $this->actingAs($this->owner)->post('/organization/embedding', embedKeyPayload());
    $key = EmbedKey::first();

    $this->actingAs($this->admin)->post("/organization/embedding/{$key->id}/rotate")->assertForbidden();
    $this->actingAs($this->admin)->delete("/organization/embedding/{$key->id}")->assertForbidden();

    expect(EmbedKey::count())->toBe(1)
        ->and($key->fresh()->revoked_at)->toBeNull();
});

// Organization-wide settings

it('keeps organization-wide settings to members with access to all sites', function () {
    $this->actingAs($this->admin)->get('/organization/audit')->assertForbidden();
    $this->actingAs($this->admin)->post('/organization/exclusions', ['field' => 'ip', 'operator' => 'equals', 'value' => '203.0.113.9'])->assertForbidden();
    $this->actingAs($this->admin)->post('/sites', ['name' => 'New', 'domain' => 'new.example', 'timezone' => 'UTC'])->assertForbidden();

    $this->other->forceFill(['archived_at' => now()])->save();
    $this->actingAs($this->admin)->post("/organization/projects/{$this->other->id}/restore")->assertForbidden();

    expect(ExclusionRule::count())->toBe(0)
        ->and($this->other->fresh()->archived_at)->not->toBeNull();

    // Their own site's settings still work.
    $this->actingAs($this->admin)->get("/sites/{$this->mine->id}/settings")->assertOk();
});

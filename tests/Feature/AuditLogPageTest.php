<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeClickHouse;

beforeEach(function () {
    $this->app->instance(ClickHouse::class, new FakeClickHouse);
    $this->org = Organization::create(['name' => 'Team', 'slug' => 'team', 'timezone' => 'America/Toronto']);
    $this->other = Organization::create(['name' => 'Other', 'slug' => 'other']);
    $this->user = User::factory()->create(['name' => 'Team member', 'email' => 'member@example.test']);
    $this->org->addMember($this->user, Role::Owner);
    $this->user->switchOrganization($this->org);
    AuditLog::query()->delete();
});

it('lists actor names email subjects compact metadata and organization time', function () {
    $site = Project::create(['organization_id' => $this->org->id, 'name' => 'Main site', 'domain' => 'example.test']);
    $this->actingAs($this->user);
    $this->travelTo(now()->setDate(2026, 7, 15)->setTime(15, 30));
    $log = AuditLog::record('project.updated', $this->org->id, $site, ['changes' => ['name'], 'nested' => ['before' => 'Old']]);

    $this->get('/organization/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('organization/Audit')->where('timezone', 'America/Toronto')
        ->has('logs.data', 1)->where('logs.data.0.id', $log->id)
        ->where('logs.data.0.actor', ['name' => 'Team member', 'email' => 'member@example.test', 'type' => 'user'])
        ->where('logs.data.0.label', 'Site settings updated')->where('logs.data.0.subject', 'Main site')
        ->where('logs.data.0.metadata', ['changes' => ['name'], 'nested' => ['before' => 'Old']])
        ->where('logs.data.0.at', '2026-07-15T11:30:00-04:00')
        ->where('actors.0.value', 'user:'.$this->user->id));
});

it('keeps names after subjects and API keys are deleted and labels Console and System', function () {
    $site = Project::create(['organization_id' => $this->org->id, 'name' => 'Removed site', 'domain' => 'example.test']);
    [$key] = ApiKey::issue(['organization_id' => $this->org->id, 'name' => 'Removed integration', 'type' => 'org', 'scopes' => ['analytics:read']]);
    AuditLog::record('project.deleted', $this->org->id, $site, actorType: 'api_key', actorId: $key->id);
    $site->delete();
    $key->delete();
    AuditLog::record('retention.applied', $this->org->id, actorType: 'console');
    AuditLog::record('test.system', $this->org->id, actorType: 'system');

    $this->actingAs($this->user)->get('/organization/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('logs.data', 3)->where('logs.data.0.actor.name', 'System')
        ->where('logs.data.1.actor.name', 'Console')
        ->where('logs.data.2.actor.name', 'Removed integration')
        ->where('logs.data.2.actor.type', 'api_key')
        ->where('logs.data.2.subject', 'Removed site'));
});

it('resolves legacy subjects and actor identities within the current organization', function () {
    $member = User::factory()->create(['name' => 'Another member']);
    $membership = $this->org->addMember($member, Role::Viewer);
    AuditLog::query()->delete();
    AuditLog::create([
        'organization_id' => $this->org->id, 'action' => 'member.updated',
        'actor_type' => 'user', 'actor_id' => $this->user->id,
        'subject_type' => 'Membership', 'subject_id' => $membership->id,
    ]);
    [$key] = ApiKey::issue(['organization_id' => $this->org->id, 'name' => 'Legacy client', 'type' => 'org', 'scopes' => ['analytics:read']]);
    AuditLog::create([
        'organization_id' => $this->org->id, 'action' => 'mcp.used',
        'actor_type' => 'mcp_client', 'actor_id' => $key->id,
        'subject_type' => 'ApiKey', 'subject_id' => $key->id,
    ]);
    $this->actingAs($this->user)->get('/organization/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('logs.data.0.actor.name', 'Legacy client')->where('logs.data.0.actor.type', 'api_key')
        ->where('logs.data.0.subject', 'Legacy client')
        ->where('logs.data.1.actor.email', $this->user->email)
        ->where('logs.data.1.subject', $member->name.' ('.$member->email.')'));
    $this->get('/organization/audit?actor=api_key:'.$key->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.action', 'mcp.used'));
});

it('filters actions actors and inclusive dates in the organization timezone', function () {
    $this->actingAs($this->user);
    foreach (['2026-07-15 03:59:59', '2026-07-15 04:00:00', '2026-07-16 03:59:59', '2026-07-16 04:00:00'] as $date) {
        $this->travelTo(new DateTimeImmutable($date, new DateTimeZone('UTC')));
        AuditLog::record('project.updated', $this->org->id);
    }
    AuditLog::record('project.created', $this->org->id);
    AuditLog::record('project.updated', $this->org->id, actorType: 'system');
    $url = '/organization/audit?'.http_build_query([
        'action' => 'project.updated', 'actor' => 'user:'.$this->user->id, 'from' => '2026-07-15', 'to' => '2026-07-15',
    ]);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('logs.data', 2)->where('logs.total', 2)
        ->where('logs.data.0.at', '2026-07-15T23:59:59-04:00')
        ->where('logs.data.1.at', '2026-07-15T00:00:00-04:00')
        ->where('filters.from', '2026-07-15')->where('filters.actor', 'user:'.$this->user->id));
});

it('supports either date bound independently and daylight saving time days', function () {
    foreach (['2026-11-01 03:59:59', '2026-11-01 04:00:00', '2026-11-02 04:59:59', '2026-11-02 05:00:00'] as $date) {
        $this->travelTo(new DateTimeImmutable($date, new DateTimeZone('UTC')));
        AuditLog::record('test.time', $this->org->id);
    }
    $this->actingAs($this->user)->get('/organization/audit?from=2026-11-01&to=2026-11-01')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('logs.total', 2));
    $this->get('/organization/audit?to=2026-11-01')->assertOk()->assertInertia(fn (Assert $page) => $page->where('logs.total', 3));
    $this->get('/organization/audit?from=2026-11-01')->assertOk()->assertInertia(fn (Assert $page) => $page->where('logs.total', 3));
});

it('paginates newest first and preserves filters without repeating entries', function () {
    $ids = [];
    foreach (range(1, 55) as $index) {
        $ids[] = AuditLog::record('test.page', $this->org->id, actorType: 'system')->id;
    }
    AuditLog::record('test.other', $this->org->id);
    $this->actingAs($this->user)->get('/organization/audit?action=test.page&actor=system')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 50)->where('logs.total', 55)
            ->where('logs.data.0.id', $ids[54])->where('logs.data.49.id', $ids[5])
            ->where('logs.next_page_url', fn ($url) => str_contains($url, 'action=test.page') && str_contains($url, 'actor=system') && str_contains($url, 'page=2')));
    $this->get('/organization/audit?action=test.page&actor=system&page=2')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 5)->where('logs.total', 55)
            ->where('logs.from', 51)->where('logs.to', 55)->where('logs.data.0.id', $ids[4])->where('logs.data.4.id', $ids[0]));
});

it('returns an empty listing for an unused log or unmatched filters', function () {
    $this->actingAs($this->user)->get('/organization/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('logs.data', 0)->where('logs.total', 0)->where('actors', [])->where('actions', []));
    AuditLog::record('project.created', $this->org->id);
    $this->get('/organization/audit?action=project.deleted')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('logs.data', 0)->where('filters.action', 'project.deleted'));
});

it('validates filters without executing unscoped queries', function (array $filters, string $field) {
    $this->actingAs($this->user)->getJson('/organization/audit?'.http_build_query($filters))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['from' => 'invalid'], 'from'],
    [['to' => '2026-99-99'], 'to'],
    [['from' => '2026-07-16', 'to' => '2026-07-15'], 'to'],
    [['actor' => 'user:1 OR 1=1'], 'actor'],
    [['action' => ['project.created']], 'action'],
]);

it('restricts the log to organization wide roles with ViewAuditLog', function (Role $role, string $scope, bool $allowed) {
    $member = User::factory()->create();
    $this->org->addMember($member, $role, $scope);
    $response = $this->actingAs($member)->get('/organization/audit');
    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    [Role::Owner, 'all', true], [Role::Admin, 'all', true],
    [Role::Admin, 'granted_only', false], [Role::Owner, 'granted_only', false],
    [Role::Analyst, 'all', false], [Role::Viewer, 'all', false], [Role::Billing, 'all', false],
]);

it('requires a signed in organization member', function () {
    $this->get('/organization/audit')->assertRedirect('/login');
    $outsider = User::factory()->create();
    $outsider->forceFill(['current_organization_id' => $this->org->id])->save();
    $this->actingAs($outsider)->get('/organization/audit')->assertRedirect('/start');
});

it('isolates entries filter options and totals when the same owner switches organizations', function () {
    $foreignUser = User::factory()->create(['name' => 'Foreign member']);
    $this->other->addMember($foreignUser, Role::Owner);
    $this->other->addMember($this->user, Role::Owner);
    AuditLog::query()->delete();
    $this->actingAs($this->user);
    AuditLog::record('project.created', $this->org->id);
    $foreign = AuditLog::record('foreign.secret_action', $this->other->id, $foreignUser, actorType: 'user', actorId: (string) $foreignUser->id);
    AuditLog::record('test.global', null);

    $this->get('/organization/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('logs.total', 1)->has('actions', 1)->where('actions.0.value', 'project.created')
        ->has('actors', 1)->where('actors.0.value', 'user:'.$this->user->id));
    foreach ([['action' => 'foreign.secret_action'], ['actor' => 'user:'.$foreignUser->id], ['organization_id' => $this->other->id, 'action' => 'foreign.secret_action']] as $filters) {
        $this->get('/organization/audit?'.http_build_query($filters))->assertOk()->assertInertia(fn (Assert $page) => $page->where('logs.total', 0));
    }
    $this->post('/organizations/'.$this->other->id.'/switch')->assertRedirect();
    $this->get('/organization/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('logs.total', 1)->where('logs.data.0.id', $foreign->id)
        ->where('logs.data.0.actor.name', 'Foreign member'));
});

it('does not resolve legacy references to another organizations subjects or keys', function () {
    [$foreignKey] = ApiKey::issue(['organization_id' => $this->other->id, 'name' => 'Private integration', 'type' => 'org', 'scopes' => []]);
    $foreignSite = Project::create(['organization_id' => $this->other->id, 'name' => 'Private site', 'domain' => 'private.test']);
    AuditLog::create([
        'organization_id' => $this->org->id, 'actor_type' => 'api_key', 'actor_id' => $foreignKey->id,
        'action' => 'project.updated', 'subject_type' => 'Project', 'subject_id' => $foreignSite->id,
    ]);
    $this->actingAs($this->user)->get('/organization/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('logs.data.0.actor.name', 'Deleted API key')->where('logs.data.0.subject', 'Site #'.$foreignSite->id));
});

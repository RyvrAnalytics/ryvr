<?php

use App\Analytics\Analytics;
use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeClickHouse;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    $this->clickhouse = new FakeClickHouse;
    app()->instance('clickhouse.read', $this->clickhouse);
    $this->org = Organization::create(['name' => 'Team', 'slug' => 'team']);
    $this->site = Project::create(['organization_id' => $this->org->id, 'name' => 'Main', 'domain' => 'example.test']);
    $this->second = Project::create(['organization_id' => $this->org->id, 'name' => 'Other', 'domain' => 'other.test']);
    $this->user = User::factory()->create();
    $this->org->addMember($this->user, Role::Owner);
    $this->user->switchOrganization($this->org);
    $this->clickhouse->rows = [
        ['project_id' => $this->site->id, 'bucket' => '2026-10-31', 'accepted' => 10, 'filtered' => 2, 'shed' => 1, 'bytes' => 100],
        ['project_id' => $this->site->id, 'bucket' => '2026-11-01', 'accepted' => 20, 'filtered' => 3, 'shed' => 2, 'bytes' => 200],
        ['project_id' => $this->second->id, 'bucket' => '2026-11-02', 'accepted' => 30, 'filtered' => 4, 'shed' => 5, 'bytes' => 300],
    ];
});

it('allows owners admins and billing members to view usage', function (Role $role) {
    $this->org->addMember($this->user, $role);
    $this->actingAs($this->user)->get('/organization/usage')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('organization/Usage')->where('usage.totals.this_month', ['accepted' => 50, 'filtered' => 7, 'shed' => 7, 'bytes' => 500])
        ->where('usage.totals.last_month.accepted', 10)->where('usage.sites.0.this_month.accepted', 20)
        ->where('usage.sites.1.this_month.accepted', 30)->has('usage.daily', 17)
        ->where('usage.daily.0.accepted', 20)->where('usage.daily.1.accepted', 30)->where('usage.daily.2.accepted', 0));
    $query = collect($this->clickhouse->queries)->first(fn ($q) => str_contains($q['sql'], 'FROM ingest_usage'));
    expect($query['params']['organization'])->toBe($this->org->id);
})->with([Role::Owner, Role::Admin, Role::Billing]);

it('denies usage to viewers analysts and site limited admins', function (Role $role, string $access) {
    $this->org->addMember($this->user, $role, $access);
    $this->actingAs($this->user)->get('/organization/usage')->assertForbidden();
    expect($this->clickhouse->queries)->toBeEmpty();
})->with([[Role::Viewer, 'all'], [Role::Analyst, 'all'], [Role::Admin, 'granted_only']]);

it('requires authentication and an organization key with analytics read for the usage api', function () {
    $this->getJson('/api/v1/usage')->assertUnauthorized();
    [, $personal] = ApiKey::issue(['organization_id' => $this->org->id, 'user_id' => $this->user->id, 'name' => 'Personal', 'type' => 'pat', 'scopes' => ['analytics:read']]);
    $this->getJson('/api/v1/usage', ['Authorization' => 'Bearer '.$personal])->assertForbidden();
    [, $wrong] = ApiKey::issue(['organization_id' => $this->org->id, 'name' => 'Write', 'type' => 'org', 'scopes' => ['projects:write']]);
    $this->getJson('/api/v1/usage', ['Authorization' => 'Bearer '.$wrong])->assertForbidden();
    [, $key] = ApiKey::issue(['organization_id' => $this->org->id, 'name' => 'Reports', 'type' => 'org', 'scopes' => ['analytics:read']]);
    $this->getJson('/api/v1/usage', ['Authorization' => 'Bearer '.$key])->assertOk()
        ->assertJsonPath('data.totals.this_month.accepted', 50)->assertJsonPath('data.totals.last_month.accepted', 10)
        ->assertJsonPath('data.totals.this_month.shed', 7)->assertJsonCount(2, 'data.sites');
});

it('honors the site ceiling of organization keys in usage reads', function () {
    $this->clickhouse->rows = [$this->clickhouse->rows[1]];
    [, $key] = ApiKey::issue(['organization_id' => $this->org->id, 'name' => 'Limited', 'type' => 'org', 'scopes' => ['analytics:read'], 'project_ids' => [$this->site->id]]);
    $this->getJson('/api/v1/usage', ['Authorization' => 'Bearer '.$key])->assertOk()
        ->assertJsonCount(1, 'data.sites')->assertJsonPath('data.totals.this_month.accepted', 20);
    expect($this->clickhouse->queries[0]['params']['projects'])->toBe([$this->site->id]);
});

it('reads day and month usage through the read client with bound tenant and time predicates', function () {
    $analytics = app(Analytics::class);
    $from = CarbonImmutable::parse('2026-11-01', 'UTC');
    $analytics->usage($this->org->id, $from, $from->addMonth(), 'month', [$this->site->id]);
    $query = $this->clickhouse->queries[0];
    expect($query['sql'])->toContain("'%Y-%m'", 'organization_id = {organization:UUID}', 'project_id IN {projects:Array(UUID)}', 'sum(accepted)', 'hour < {to:DateTime}')
        ->and($query['params']['organization'])->toBe($this->org->id);
    $this->clickhouse->queries = [];
    expect($analytics->usage($this->org->id, $from, $from->addMonth(), projectIds: []))->toBe([])
        ->and($this->clickhouse->queries)->toBeEmpty();
});

it('includes archived and removed sites without losing metered totals', function () {
    $this->site->archive();
    $this->second->delete();
    $this->actingAs($this->user)->get('/organization/usage')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('usage.sites', 2)->where('usage.sites.0.name', 'Main')->where('usage.sites.1.name', 'Removed site')
        ->where('usage.totals.this_month.accepted', 50));
});

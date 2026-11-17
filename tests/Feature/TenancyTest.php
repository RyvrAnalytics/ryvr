<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectGrant;
use App\Models\ProjectGroup;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeClickHouse;

beforeEach(function () {
    $this->clickhouse = new FakeClickHouse;
    $this->app->instance(ClickHouse::class, $this->clickhouse);

    $this->acme = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->globex = Organization::create(['name' => 'Globex', 'slug' => 'globex']);
    $this->acmeSite = Project::create(['organization_id' => $this->acme->id, 'name' => 'Acme', 'domain' => 'acme.com']);
    $this->acmeShop = Project::create(['organization_id' => $this->acme->id, 'name' => 'Acme Shop', 'domain' => 'shop.acme.com']);
    $this->globexSite = Project::create(['organization_id' => $this->globex->id, 'name' => 'Globex', 'domain' => 'globex.com']);

    $this->owner = User::factory()->create();
    $this->acme->addMember($this->owner, Role::Owner);
});

it('lists only the current organization\'s sites', function () {
    $this->actingAs($this->owner)->get('/sites')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sites/Index')
            ->has('sites', 2)
            ->where('sites.0.name', 'Acme')
        );

    expect($this->clickhouse->queriedProjectIds())->not->toContain($this->globexSite->id);
});

it('returns 404 for another organization\'s site everywhere', function (string $path) {
    $this->actingAs($this->owner)->get(str_replace('{id}', $this->globexSite->id, $path))->assertNotFound();

    expect($this->clickhouse->queriedProjectIds())->not->toContain($this->globexSite->id);
})->with([
    '/sites/{id}',
    '/sites/{id}/breakdown?dimension=page',
    '/sites/{id}/realtime',
    '/sites/{id}/export',
    '/sites/{id}/settings',
]);

it('cannot change another organization\'s site', function () {
    $this->actingAs($this->owner);

    $this->put("/sites/{$this->globexSite->id}/settings", ['name' => 'Hacked', 'domain' => 'x.com', 'timezone' => 'UTC', 'environment' => 'production'])->assertNotFound();
    $this->post("/sites/{$this->globexSite->id}/rotate-key")->assertNotFound();
    $this->delete("/sites/{$this->globexSite->id}", ['confirm' => 'globex.com'])->assertNotFound();

    expect($this->globexSite->fresh()->name)->toBe('Globex');
});

it('renders a site dashboard with queries pinned to that site', function () {
    $this->actingAs($this->owner)->get("/sites/{$this->acmeSite->id}?period=7d&f[country]=CA")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sites/Show')
            ->where('site.id', $this->acmeSite->id)
            ->where('query.period', '7d')
            ->where('query.filters.country', 'CA')
        );

    // The dashboard reads this site; the navigation rail may read live counts for the
    // member's other sites. Nothing outside the organization is ever touched.
    expect($this->clickhouse->queriedProjectIds())
        ->toContain($this->acmeSite->id)
        ->not->toContain($this->globexSite->id);

    $breakdownQueries = array_filter($this->clickhouse->queries, fn ($q) => str_contains($q['sql'], 'GROUP BY value'));
    foreach ($breakdownQueries as $query) {
        expect($query['params']['projects'])->toBe([$this->acmeSite->id]);
    }
});

it('ignores unknown filters and dimensions', function () {
    $this->actingAs($this->owner)
        ->get("/sites/{$this->acmeSite->id}?f[props;DROP TABLE events]=x")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('query.filters', []));

    $this->actingAs($this->owner)->getJson("/sites/{$this->acmeSite->id}/breakdown?dimension=visitor_id")->assertStatus(422);
});

it('limits a site-scoped member to their granted sites', function () {
    $client = User::factory()->create();
    $this->acme->addMember($client, Role::Viewer, 'granted_only');
    ProjectGrant::create(['organization_id' => $this->acme->id, 'user_id' => $client->id, 'project_id' => $this->acmeShop->id, 'role' => Role::Viewer]);

    $this->actingAs($client)->get('/sites')
        ->assertInertia(fn (Assert $page) => $page->has('sites', 1)->where('sites.0.id', $this->acmeShop->id));

    $this->actingAs($client)->get("/sites/{$this->acmeSite->id}")->assertNotFound();
    $this->actingAs($client)->get("/sites/{$this->acmeShop->id}")->assertOk();
});

it('grants access to every site in a granted group', function () {
    $client = User::factory()->create();
    $this->acme->addMember($client, Role::Viewer, 'granted_only');
    $group = ProjectGroup::create(['organization_id' => $this->acme->id, 'name' => 'Client']);
    $group->projects()->attach([$this->acmeSite->id, $this->acmeShop->id]);
    ProjectGrant::create(['organization_id' => $this->acme->id, 'user_id' => $client->id, 'project_group_id' => $group->id, 'role' => Role::Viewer]);

    $this->actingAs($client)->get('/sites')->assertInertia(fn (Assert $page) => $page->has('sites', 2));
});

it('stops viewers from changing settings', function () {
    $viewer = User::factory()->create();
    $this->acme->addMember($viewer, Role::Viewer);

    $this->actingAs($viewer)->put("/sites/{$this->acmeSite->id}/settings", ['name' => 'X', 'domain' => 'acme.com', 'timezone' => 'UTC', 'environment' => 'production'])->assertNotFound();
    $this->actingAs($viewer)->post('/sites', ['name' => 'X', 'domain' => 'x.com', 'timezone' => 'UTC'])->assertForbidden();
    $this->actingAs($viewer)->post('/organization/exclusions', ['field' => 'ip', 'operator' => 'equals', 'value' => '1.2.3.4'])->assertForbidden();

    expect($this->acmeSite->fresh()->name)->toBe('Acme');
});

it('hides analytics from billing-only members', function () {
    $billing = User::factory()->create();
    $this->acme->addMember($billing, Role::Billing);

    $this->actingAs($billing)->get('/sites')->assertInertia(fn (Assert $page) => $page->has('sites', 0));
    $this->actingAs($billing)->get("/sites/{$this->acmeSite->id}")->assertNotFound();
});

it('switches only to organizations the user belongs to', function () {
    $this->actingAs($this->owner)->post("/organizations/{$this->globex->id}/switch")->assertNotFound();

    $this->globex->addMember($this->owner, Role::Viewer);
    $this->actingAs($this->owner)->post("/organizations/{$this->globex->id}/switch")->assertRedirect('/sites');
    expect($this->owner->fresh()->current_organization_id)->toBe($this->globex->id);
});

it('sends users without an organization to onboarding', function () {
    $loner = User::factory()->create();

    $this->actingAs($loner)->get('/sites')->assertRedirect('/start');

    $this->actingAs($loner)->post('/start', ['organization' => 'Solo', 'timezone' => 'UTC', 'site_name' => 'Blog', 'domain' => 'https://blog.example.com/'])
        ->assertRedirect();

    $organization = $loner->fresh()->currentOrganization();
    expect($organization->name)->toBe('Solo')
        ->and($organization->projects()->first()->domain)->toBe('blog.example.com');
});

it('shows the first-visit setup with the snippet until a site has data', function () {
    $this->actingAs($this->owner)->get("/sites/{$this->acmeSite->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('setup.snippet', fn ($snippet) => str_contains($snippet, $this->acmeSite->ingest_key))
        );

    Cache::put('ryvr:has-data:'.$this->acmeSite->id, true);
    $this->actingAs($this->owner)->get("/sites/{$this->acmeSite->id}")
        ->assertInertia(fn (Assert $page) => $page->where('setup', null));
});

it('hides the tracking key from members who cannot manage the site', function () {
    $viewer = User::factory()->create();
    $this->acme->addMember($viewer, Role::Viewer);

    $this->actingAs($viewer)->get("/sites/{$this->acmeSite->id}")
        ->assertInertia(fn (Assert $page) => $page->where('setup.snippet', null));
});

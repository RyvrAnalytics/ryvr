<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Embed\EmbedTokenVerifier;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\EmbedKey;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeClickHouse;
use Tests\Support\EmbedTokens;

beforeEach(function () {
    $this->app->instance(ClickHouse::class, new FakeClickHouse);
    $this->app->instance('clickhouse.read', $this->app->make(ClickHouse::class));
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->site = Project::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'domain' => 'acme.com']);
    $this->owner = User::factory()->create();
    $this->org->addMember($this->owner, Role::Owner);
});

function createEmbedKey(array $overrides = [])
{
    return test()->post('/organization/embedding', $overrides + [
        'name' => 'Portal',
        'allowed_origins' => ['https://App.Example.com/'],
        'widgets' => ['kpi', 'trend'],
        'max_ttl_seconds' => 600,
    ]);
}

function editEmbedKey(EmbedKey $key, array $overrides = [])
{
    return test()->patch("/organization/embedding/{$key->id}", $overrides + [
        'name' => $key->name,
        'allowed_origins' => $key->allowed_origins,
        'project_ids' => $key->project_ids,
        'widgets' => $key->widgets,
        'max_ttl_seconds' => $key->max_ttl_seconds,
    ]);
}

it('lets owners and admins create a key and shows the secret once', function () {
    $this->actingAs($this->owner);

    createEmbedKey()->assertSessionHas('newEmbedKey')->assertSessionHasNoErrors();

    $key = EmbedKey::sole();
    $flash = session('newEmbedKey');

    expect($flash['id'])->toBe($key->public_id)
        ->and($flash['secret'])->toStartWith('ryvr_embed_')
        ->and(strlen($flash['secret']))->toBe(strlen('ryvr_embed_') + 43)
        ->and($key->secret)->toBe($flash['secret'])
        ->and($key->allowed_origins)->toBe(['https://app.example.com'])
        ->and($key->widgets)->toBe(['kpi', 'trend'])
        ->and(AuditLog::where('action', 'embed.key.created')->exists())->toBeTrue();

    // Stored encrypted, never in plain text.
    $raw = DB::table('embed_keys')->value('secret');
    expect($raw)->not->toContain($flash['secret']);

    $this->get('/organization/embedding')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('organization/Embedding')
        ->has('keys', 1)
        ->where('keys.0.public_id', $key->public_id)
        ->missing('keys.0.secret'));
});

it('rejects unsafe origins', function (string $origin) {
    $this->actingAs($this->owner);

    createEmbedKey(['allowed_origins' => [$origin]])->assertSessionHasErrors('allowed_origins');
    expect(EmbedKey::count())->toBe(0);
})->with(['*', 'http://app.example.com', 'https://*.com', 'https://example.com/dashboard', 'javascript:alert(1)', "https://a.com 'unsafe-inline'"]);

it('keeps embedding settings away from analysts and viewers', function (Role $role) {
    $user = User::factory()->create();
    $this->org->addMember($user, $role);
    $this->actingAs($user);

    $this->get('/organization/embedding')->assertForbidden();
    createEmbedKey()->assertForbidden();
})->with([Role::Analyst, Role::Viewer]);

it('lets an admin manage keys', function () {
    $admin = User::factory()->create();
    $this->org->addMember($admin, Role::Admin);
    $this->actingAs($admin);

    createEmbedKey()->assertSessionHas('newEmbedKey');
    $this->get('/organization/embedding')->assertOk();
});

it('revokes a key, which invalidates its tokens immediately', function () {
    $this->actingAs($this->owner);
    [$key] = EmbedTokens::key($this->org);
    $token = EmbedTokens::token($key, [$this->site->id]);

    expect((new EmbedTokenVerifier)->verify($token))->not->toBeNull();

    $this->delete("/organization/embedding/{$key->id}")->assertRedirect();

    expect($key->fresh()->revoked_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'embed.key.revoked')->exists())->toBeTrue();
    $this->getJson("/embed/v1/{$key->public_id}/summary", ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
});

it('rotates a key into a new one with the same limits, leaving the old one active', function () {
    $this->actingAs($this->owner);
    [$key] = EmbedTokens::key($this->org, ['widgets' => ['kpi']]);

    $this->post("/organization/embedding/{$key->id}/rotate")->assertSessionHas('newEmbedKey');

    $new = EmbedKey::where('id', '!=', $key->id)->sole();
    expect($new->widgets)->toBe(['kpi'])
        ->and($new->allowed_origins)->toBe($key->allowed_origins)
        ->and($new->public_id)->not->toBe($key->public_id)
        ->and($key->fresh()->revoked_at)->toBeNull();
});

it('cannot touch another organization\'s keys', function () {
    $this->actingAs($this->owner);
    $other = Organization::create(['name' => 'Globex', 'slug' => 'globex']);
    [$foreign] = EmbedTokens::key($other);

    $this->delete("/organization/embedding/{$foreign->id}")->assertNotFound();
    $this->post("/organization/embedding/{$foreign->id}/rotate")->assertNotFound();
    expect($foreign->fresh()->revoked_at)->toBeNull();
});

it('limits a key to sites the creator can read', function () {
    $this->actingAs($this->owner);
    $other = Organization::create(['name' => 'Globex', 'slug' => 'globex']);
    $foreign = Project::create(['organization_id' => $other->id, 'name' => 'Globex', 'domain' => 'globex.com']);

    createEmbedKey(['project_ids' => [$this->site->id, $foreign->id]])->assertSessionHasNoErrors();

    expect(EmbedKey::sole()->project_ids)->toBe([$this->site->id]);
});

it('lets owners and admins edit all key settings and audits changed fields once', function (Role $role) {
    $user = User::factory()->create();
    $this->org->addMember($user, $role);
    $this->actingAs($user);
    [$key, $secret] = EmbedTokens::key($this->org);
    $originalId = $key->public_id;
    config(['ryvr.embed.max_active_keys' => 1]);

    editEmbedKey($key, [
        'name' => 'Updated portal',
        'allowed_origins' => ['https://Portal.Example.com/', 'https://portal.example.com'],
        'project_ids' => [$this->site->id, $this->site->id],
        'widgets' => ['trend', 'kpi', 'kpi'],
        'max_ttl_seconds' => 900,
        'secret' => 'replacement-secret',
        'max_period_days' => 1,
        'rate_limit_per_minute' => 1,
    ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('newEmbedKey');

    $key->refresh();
    expect($key->name)->toBe('Updated portal')
        ->and($key->allowed_origins)->toBe(['https://portal.example.com'])
        ->and($key->project_ids)->toBe([$this->site->id])
        ->and($key->widgets)->toBe(['kpi', 'trend'])
        ->and($key->max_ttl_seconds)->toBe(900)
        ->and($key->public_id)->toBe($originalId)
        ->and($key->secret)->toBe($secret)
        ->and($key->max_period_days)->toBe(400)
        ->and($key->rate_limit_per_minute)->toBe(600)
        ->and(EmbedKey::count())->toBe(1);

    $log = AuditLog::where('action', 'embed.key.updated')->sole();
    expect($log->metadata['changes'])->toEqualCanonicalizing(['name', 'allowed_origins', 'project_ids', 'widgets', 'max_ttl_seconds'])
        ->and($log->subject_id)->toBe($key->id)
        ->and($log->organization_id)->toBe($this->org->id)
        ->and($log->actor_type)->toBe('user')
        ->and($log->actor_id)->toBe((string) $user->id)
        ->and(json_encode($log->metadata))->not->toContain($secret);

    editEmbedKey($key)->assertSessionHasNoErrors();
    expect(AuditLog::where('action', 'embed.key.updated')->count())->toBe(1);
})->with([Role::Owner, Role::Admin]);

it('uses creation validation when editing and leaves invalid keys unchanged', function (array $changes, string $field) {
    $this->actingAs($this->owner);
    [$key] = EmbedTokens::key($this->org);
    $before = $key->refresh()->getRawOriginal();

    editEmbedKey($key, $changes)->assertSessionHasErrors($field);

    expect($key->fresh()->getRawOriginal())->toBe($before)
        ->and(AuditLog::where('action', 'embed.key.updated')->count())->toBe(0);
})->with([
    [['name' => ''], 'name'],
    [['name' => str_repeat('x', 101)], 'name'],
    [['allowed_origins' => ['*']], 'allowed_origins'],
    [['allowed_origins' => ['http://example.com']], 'allowed_origins'],
    [['allowed_origins' => ['https://example.com/path']], 'allowed_origins'],
    [['allowed_origins' => []], 'allowed_origins'],
    [['allowed_origins' => array_fill(0, 21, 'https://example.com')], 'allowed_origins'],
    [['project_ids' => ['invalid']], 'project_ids.0'],
    [['widgets' => []], 'widgets'],
    [['widgets' => ['unknown']], 'widgets.0'],
    [['max_ttl_seconds' => 60], 'max_ttl_seconds'],
    [['max_ttl_seconds' => 7200], 'max_ttl_seconds'],
]);

it('keeps editing away from analysts and viewers', function (Role $role) {
    $user = User::factory()->create();
    $this->org->addMember($user, $role);
    $this->actingAs($user);
    [$key] = EmbedTokens::key($this->org);

    editEmbedKey($key, ['name' => 'Changed'])->assertForbidden();
    expect($key->fresh()->name)->toBe($key->name);
})->with([Role::Analyst, Role::Viewer]);

it('does not edit foreign or revoked keys', function () {
    $this->actingAs($this->owner);
    $other = Organization::create(['name' => 'Other', 'slug' => 'other']);
    [$foreign] = EmbedTokens::key($other);
    [$revoked] = EmbedTokens::key($this->org);
    $revoked->forceFill(['revoked_at' => now()])->save();

    editEmbedKey($foreign, ['name' => 'Changed'])->assertNotFound();
    editEmbedKey($revoked, ['name' => 'Changed'])->assertNotFound();
    expect(AuditLog::where('action', 'embed.key.updated')->count())->toBe(0);
});

it('lets a site-limited admin edit covered keys without widening beyond their sites', function () {
    $admin = User::factory()->create();
    $this->org->addMember($admin, Role::Admin, 'granted_only');
    ProjectGrant::create(['organization_id' => $this->org->id, 'user_id' => $admin->id, 'project_id' => $this->site->id, 'role' => Role::Admin]);
    $hidden = Project::create(['organization_id' => $this->org->id, 'name' => 'Hidden', 'domain' => 'hidden.test']);
    [$key] = EmbedTokens::key($this->org, ['project_ids' => [$this->site->id]]);
    [$wide] = EmbedTokens::key($this->org);
    [$uncovered] = EmbedTokens::key($this->org, ['project_ids' => [$this->site->id, $hidden->id]]);
    $this->actingAs($admin);

    $this->get('/organization/embedding')->assertInertia(fn (Assert $page) => $page
        ->where('canCreateUnlimited', false)
        ->where('keys', fn ($keys) => collect($keys)->keyBy('id')[$key->id]['manageable'] === true
            && collect($keys)->keyBy('id')[$wide->id]['manageable'] === false));

    editEmbedKey($key, ['name' => 'Changed'])->assertSessionHasNoErrors();
    expect($key->fresh()->name)->toBe('Changed');

    foreach ([null, [], [$hidden->id], [$this->site->id, $hidden->id]] as $sites) {
        editEmbedKey($key, ['project_ids' => $sites])->assertForbidden();
    }
    editEmbedKey($wide, ['project_ids' => [$this->site->id]])->assertForbidden();
    editEmbedKey($uncovered, ['project_ids' => [$this->site->id]])->assertForbidden();
    expect($key->fresh()->project_ids)->toBe([$this->site->id])
        ->and(AuditLog::where('action', 'embed.key.updated')->count())->toBe(1);
});

it('applies edited ceilings to already issued tokens', function () {
    $this->actingAs($this->owner);
    [$key] = EmbedTokens::key($this->org);
    $token = EmbedTokens::token($key, [$this->site->id]);

    editEmbedKey($key, ['widgets' => ['kpi']])->assertSessionHasNoErrors();

    $this->withToken($token)->getJson("/embed/v1/{$key->public_id}/summary")
        ->assertForbidden()->assertJsonPath('error.code', 'widget_not_allowed');
});

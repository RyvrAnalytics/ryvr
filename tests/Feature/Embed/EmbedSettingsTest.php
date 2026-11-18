<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Embed\EmbedTokenVerifier;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\EmbedKey;
use App\Models\Organization;
use App\Models\Project;
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

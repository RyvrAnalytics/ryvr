<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Embed\EmbedTokenVerifier;
use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Tests\Fakes\FakeClickHouse;
use Tests\Support\EmbedTokens;

beforeEach(function () {
    $this->app->instance(ClickHouse::class, new FakeClickHouse);

    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->other = Organization::create(['name' => 'Globex', 'slug' => 'globex']);
    $this->site = Project::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'domain' => 'acme.com']);
    $this->shop = Project::create(['organization_id' => $this->org->id, 'name' => 'Shop', 'domain' => 'shop.acme.com']);
    $this->foreign = Project::create(['organization_id' => $this->other->id, 'name' => 'Globex', 'domain' => 'globex.com']);

    [$this->key] = EmbedTokens::key($this->org);
    [, $this->apiKey] = ApiKey::issue(['organization_id' => $this->org->id, 'name' => 'Server', 'type' => 'org', 'scopes' => ['embed:mint']]);
});

function mint(array $body, ?string $apiKey = null, array $headers = [])
{
    return test()->postJson('/api/v1/embed/tokens', $body, $headers + ['Authorization' => 'Bearer '.($apiKey ?? test()->apiKey)]);
}

it('mints a token that works on the embed API', function () {
    $response = mint(['key_id' => $this->key->public_id, 'sites' => [$this->site->id], 'widgets' => ['kpi'], 'ttl' => 300, 'sub' => 'viewer-9'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonStructure(['token', 'key_id', 'jti', 'expires_at']);

    $grant = (new EmbedTokenVerifier)->verify($response->json('token'), $this->key->public_id);
    expect($grant->projectIds())->toBe([$this->site->id])
        ->and($grant->widgets)->toBe(['kpi'])
        ->and($grant->sub)->toBe('viewer-9')
        ->and($grant->expiresAt - time())->toBeLessThanOrEqual(300);

    $this->getJson('/embed/v1/'.$this->key->public_id.'/summary', ['Authorization' => 'Bearer '.$response->json('token')])->assertOk();
});

it('requires the embed:mint scope', function () {
    [, $readOnly] = ApiKey::issue(['organization_id' => $this->org->id, 'name' => 'Reader', 'type' => 'org', 'scopes' => ['analytics:read']]);

    mint(['key_id' => $this->key->public_id], $readOnly)->assertForbidden();
    test()->postJson('/api/v1/embed/tokens', ['key_id' => $this->key->public_id])->assertUnauthorized();
});

it('refuses browser requests', function () {
    mint(['key_id' => $this->key->public_id], null, ['Origin' => 'https://app.example.com'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'browser_not_allowed');
});

it('applies the same ceilings as verification', function () {
    $this->key->forceFill(['project_ids' => [$this->site->id], 'widgets' => ['kpi', 'trend']])->save();

    mint(['key_id' => $this->key->public_id, 'sites' => [$this->shop->id]])->assertForbidden()->assertJsonPath('error.code', 'site_not_allowed');
    mint(['key_id' => $this->key->public_id, 'widgets' => ['realtime']])->assertForbidden()->assertJsonPath('error.code', 'widget_not_allowed');
    mint(['key_id' => $this->key->public_id, 'origins' => ['https://evil.test']])->assertForbidden()->assertJsonPath('error.code', 'origin_not_allowed');
    mint(['key_id' => $this->key->public_id, 'ttl' => 601])->assertStatus(422)->assertJsonPath('error.code', 'token_lifetime_too_long');
    mint(['key_id' => $this->key->public_id, 'ttl' => 7200])->assertStatus(422);
    mint(['key_id' => $this->key->public_id, 'period' => ['allowed' => ['forever']]])->assertStatus(422)->assertJsonPath('error.code', 'invalid_claims');
});

it('cannot mint for sites the API key cannot read or for another organization', function () {
    mint(['key_id' => $this->key->public_id, 'sites' => [$this->foreign->id]])->assertForbidden();

    [$foreignKey] = EmbedTokens::key($this->other);
    mint(['key_id' => $foreignKey->public_id])->assertNotFound();

    // A personal key never mints beyond its owner's projects.
    $user = User::factory()->create();
    $this->org->addMember($user, Role::Analyst, 'selected');
    [, $personal] = ApiKey::issue(['organization_id' => $this->org->id, 'user_id' => $user->id, 'name' => 'Mine', 'type' => 'pat', 'scopes' => ['embed:mint']]);
    mint(['key_id' => $this->key->public_id, 'sites' => [$this->site->id]], $personal)->assertForbidden();
});

it('revokes a single token by jti', function () {
    $minted = mint(['key_id' => $this->key->public_id, 'sites' => [$this->site->id]])->assertOk();
    $headers = ['Authorization' => 'Bearer '.$minted->json('token')];
    $url = '/embed/v1/'.$this->key->public_id.'/summary';

    $this->getJson($url, $headers)->assertOk();

    test()->postJson('/api/v1/embed/tokens/'.$minted->json('jti').'/revoke', [], ['Authorization' => 'Bearer '.$this->apiKey])
        ->assertOk()
        ->assertJsonPath('revoked', true);

    $this->getJson($url, $headers)->assertUnauthorized()->assertJsonPath('error.code', 'token_revoked');
});

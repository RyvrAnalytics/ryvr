<?php

use App\Models\ExclusionRule;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SecretIngestKey;
use Tests\Fakes\FakeClickHouse;

beforeEach(function () {
    $this->clickhouse = new FakeClickHouse;
    app()->instance('clickhouse.read', $this->clickhouse);
});

function snapshotFixture(): Project
{
    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme', 'timezone' => 'UTC']);

    $project = Project::create([
        'organization_id' => $org->id, 'name' => 'Site', 'domain' => 'example.com',
        'allowed_hostnames' => ['*.app.example.com'], 'timezone' => 'UTC', 'settings' => ['hash_routing' => true],
    ]);
    Project::create(['organization_id' => $org->id, 'name' => 'Old', 'domain' => 'old.example', 'timezone' => 'UTC'])
        ->forceFill(['archived_at' => now()])->save();

    ExclusionRule::create(['organization_id' => $org->id, 'project_id' => $project->id, 'field' => 'path', 'operator' => 'starts_with', 'value' => '/admin', 'enabled' => true]);
    ExclusionRule::create(['organization_id' => $org->id, 'field' => 'ip', 'operator' => 'cidr', 'value' => '192.0.2.0/24', 'enabled' => true]);
    ExclusionRule::create(['organization_id' => $org->id, 'field' => 'ip', 'operator' => 'equals', 'value' => '203.0.113.9', 'enabled' => false]);

    return $project;
}

it('is off unless a token is configured', function () {
    config(['ryvr.ingest.snapshot_token' => null]);

    $this->get('/internal/ingest/snapshot')->assertNotFound();
});

it('refuses a missing or wrong token', function () {
    config(['ryvr.ingest.snapshot_token' => 'secret-token']);

    $this->get('/internal/ingest/snapshot')->assertUnauthorized();
    $this->get('/internal/ingest/snapshot', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
});

it('serves live projects, enabled rules and salts, and answers 304 when nothing changed', function () {
    config(['ryvr.ingest.snapshot_token' => 'secret-token']);
    $project = snapshotFixture();

    $response = $this->get('/internal/ingest/snapshot', ['Authorization' => 'Bearer secret-token'])->assertOk();
    $data = $response->json();

    expect($data['projects'])->toHaveCount(1)
        ->and($data['projects'][0]['ingest_key'])->toBe($project->ingest_key)
        ->and($data['projects'][0]['hostnames'])->toContain('example.com', 'www.example.com', '*.app.example.com')
        ->and($data['projects'][0]['settings'])->toBe(['hash_routing' => true])
        ->and($data['projects'][0]['secret_key_digests'])->toBe([])
        ->and($data['projects'][0]['burst_per_second'])->toBe(0)
        ->and($data['quotas'][$project->organization_id])->toBe(['monthly_events' => null, 'overage' => 'bill', 'burst_per_second' => 0, 'used_this_month' => 0])
        ->and($data['projects'][0]['rules'])->toBe([['field' => 'path', 'operator' => 'starts_with', 'value' => '/admin']])
        ->and($data['organization_rules'][$project->organization_id])->toBe([['field' => 'ip', 'operator' => 'cidr', 'value' => '192.0.2.0/24']])
        ->and($data['salts'])->toHaveKey(now()->utc()->toDateString());

    $etag = $response->headers->get('ETag');
    $this->get('/internal/ingest/snapshot', ['Authorization' => 'Bearer secret-token', 'If-None-Match' => $etag])->assertStatus(304);

    $project->update(['allowed_hostnames' => []]);
    $this->get('/internal/ingest/snapshot', ['Authorization' => 'Bearer secret-token', 'If-None-Match' => $etag])->assertOk();
});

it('exports quota usage and only active secret digests and changes its etag with them', function () {
    config(['ryvr.ingest.snapshot_token' => 'secret-token']);
    $project = snapshotFixture();
    $project->organization->forceFill(['monthly_events' => 1000, 'overage' => 'hard_cap', 'burst_per_second' => 20])->save();
    [$key, $plain] = SecretIngestKey::issue($project);
    [$revoked] = SecretIngestKey::issue($project);
    $revoked->forceFill(['revoked_at' => now()])->save();
    $this->clickhouse->rows = [['organization_id' => $project->organization_id, 'accepted' => '725']];
    $response = $this->get('/internal/ingest/snapshot', ['Authorization' => 'Bearer secret-token'])->assertOk()
        ->assertJsonPath('quotas.'.$project->organization_id, ['monthly_events' => 1000, 'overage' => 'hard_cap', 'burst_per_second' => 20, 'used_this_month' => 725])
        ->assertJsonPath('projects.0.secret_key_digests', [$key->token_hash])
        ->assertJsonPath('projects.0.burst_per_second', 20);
    expect($response->getContent())->not->toContain($plain)->not->toContain($revoked->token_hash);
    $key->forceFill(['revoked_at' => now()])->save();
    $this->get('/internal/ingest/snapshot', ['Authorization' => 'Bearer secret-token', 'If-None-Match' => $response->headers->get('ETag')])
        ->assertOk()->assertJsonPath('projects.0.secret_key_digests', []);
    $this->clickhouse->rows = [['organization_id' => $project->organization_id, 'accepted' => 726]];
    $this->get('/internal/ingest/snapshot', ['Authorization' => 'Bearer secret-token', 'If-None-Match' => $response->headers->get('ETag')])
        ->assertOk()->assertJsonPath('quotas.'.$project->organization_id.'.used_this_month', 726);
});

it('signs the body with Ed25519 when a key is set', function () {
    $pair = sodium_crypto_sign_keypair();
    config([
        'ryvr.ingest.snapshot_token' => 'secret-token',
        'ryvr.ingest.snapshot_signing_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
    ]);
    snapshotFixture();

    $response = $this->get('/internal/ingest/snapshot', ['Authorization' => 'Bearer secret-token'])->assertOk();
    $signature = base64_decode((string) $response->headers->get('X-Ryvr-Signature'), true);

    expect(sodium_crypto_sign_verify_detached($signature, $response->getContent(), sodium_crypto_sign_publickey($pair)))->toBeTrue()
        ->and(sodium_crypto_sign_verify_detached($signature, $response->getContent().' ', sodium_crypto_sign_publickey($pair)))->toBeFalse();
});

it('only serves allowlisted addresses when an allowlist is set', function () {
    config(['ryvr.ingest.snapshot_token' => 'secret-token', 'ryvr.ingest.snapshot_allow' => '203.0.113.7, 198.51.100.0/24']);

    $this->get('/internal/ingest/snapshot', ['Authorization' => 'Bearer secret-token'])->assertNotFound();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
        ->get('/internal/ingest/snapshot', ['Authorization' => 'Bearer secret-token'])->assertOk();
});

it('refuses hits when the built-in collector is switched off', function () {
    config(['ryvr.ingest.collector_enabled' => false]);

    $this->call('POST', '/e', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{"k":"ryvr_ingest_x","u":"https://example.com/"}')
        ->assertNotFound();
});

it('points snippets at the configured tracker host', function () {
    config(['ryvr.tracker_url' => 'https://in.example.net/']);
    expect(Project::trackerUrl())->toBe('https://in.example.net/r.js');

    config(['ryvr.tracker_url' => null]);
    expect(Project::trackerUrl())->toBe(url('/r.js'));
});

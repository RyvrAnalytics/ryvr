<?php

use App\Enums\Role;
use App\Ingestion\Queue\ArrayQueue;
use App\Ingestion\Queue\IngestQueue;
use App\Models\ApiKey;
use App\Models\ExclusionRule;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SecretIngestKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeClickHouse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    $this->queue = new ArrayQueue;
    app()->instance(IngestQueue::class, $this->queue);
    app()->instance('clickhouse.read', new FakeClickHouse);
    $this->org = Organization::create(['name' => 'Team', 'slug' => 'team']);
    $this->site = Project::create(['organization_id' => $this->org->id, 'name' => 'Main', 'domain' => 'example.test']);
    [$this->key, $this->secret] = SecretIngestKey::issue($this->site);
    $this->event = ['name' => 'Purchase', 'url' => 'https://example.test/thanks', 'user_agent' => CHROME];
});

function batch(array $events, string $secret)
{
    return test()->postJson('/v1/batch', $events, ['Authorization' => 'Bearer '.$secret]);
}

it('authenticates only active secret keys and honors collector enablement and archives', function () {
    batch([$this->event], $this->site->ingest_key)->assertUnauthorized();
    batch([$this->event], 'ryvr_sk_'.str_repeat('x', 40))->assertUnauthorized();
    $this->site->archive();
    batch([$this->event], $this->secret)->assertUnauthorized();
    $this->site->restore();
    config(['ryvr.ingest.collector_enabled' => false]);
    batch([$this->event], $this->secret)->assertNotFound();
});

it('enriches valid batch events and applies the same hostname and exclusion rules', function () {
    ExclusionRule::create(['organization_id' => $this->org->id, 'field' => 'ip', 'operator' => 'equals', 'value' => '203.0.113.2']);
    $good = $this->event + ['ip' => '203.0.113.1', 'props' => ['sku' => 'A1'], 'revenue' => 49.99, 'currency' => 'cad'];
    batch([$good, array_replace($this->event, ['url' => 'https://foreign.test/']), $this->event + ['ip' => '203.0.113.2']], $this->secret)
        ->assertStatus(202)->assertExactJson(['accepted' => 1, 'rejected' => 2]);
    expect($this->queue->events[0])->name->toBe('Purchase')->pathname->toBe('/thanks')->revenue->toBe(49.99)->currency->toBe('CAD')->props->toBe(['sku' => 'A1']);
    expect(json_encode($this->queue->events))->not->toContain('203.0.113.1')
        ->and(array_column($this->queue->drops, 'reason'))->toBe(['hostname', 'rule']);
});

it('validates the top level and each event and accepts up to five hundred events', function () {
    batch(['name' => 'wrong shape'], $this->secret)->assertUnprocessable();
    batch(array_fill(0, 501, $this->event), $this->secret)->assertUnprocessable();
    batch([null, 'bad', [], ['name' => []], $this->event + ['ip' => 'invalid'], $this->event + ['ts' => 'invalid'], $this->event + ['props' => ['nested' => true]], $this->event], $this->secret)
        ->assertStatus(202)->assertJsonPath('accepted', 2)->assertJsonPath('rejected', 6);
    batch(array_fill(0, 500, $this->event), $this->secret)->assertStatus(202)->assertJsonPath('accepted', 500);
});

it('rejects malformed json and oversized batches', function () {
    $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->secret, 'CONTENT_TYPE' => 'application/json'];
    $this->call('POST', '/v1/batch', [], [], [], $headers, '{bad')->assertUnprocessable();
    $this->call('POST', '/v1/batch', [], [], [], $headers, str_repeat('x', 4096001))->assertStatus(413);
});

it('deduplicates event ids for twenty four hours across requests and key rotation', function () {
    $event = $this->event + ['event_id' => 'order-123'];
    batch([$event, $event], $this->secret)->assertExactJson(['accepted' => 1, 'rejected' => 1]);
    batch([$event], $this->secret)->assertExactJson(['accepted' => 0, 'rejected' => 1]);
    [, $rotated] = SecretIngestKey::issue($this->site);
    batch([$event], $rotated)->assertJsonPath('accepted', 0);
    expect($this->queue->events)->toHaveCount(1);
    $this->travel(86401)->seconds();
    batch([$event], $rotated)->assertJsonPath('accepted', 1);
});

it('does not consume ids for quota rejections and scopes ids to the site', function () {
    $this->org->forceFill(['monthly_events' => 0, 'overage' => 'hard_cap'])->save();
    $event = $this->event + ['event_id' => 'retry'];
    batch([$event], $this->secret)->assertJsonPath('rejected', 1);
    $this->org->forceFill(['overage' => 'bill'])->save();
    batch([$event], $this->secret)->assertJsonPath('accepted', 1);
    $other = Project::create(['organization_id' => $this->org->id, 'name' => 'Other', 'domain' => 'example.test']);
    [, $secret] = SecretIngestKey::issue($other);
    batch([$event], $secret)->assertJsonPath('accepted', 1);
    expect($this->queue->drops[0]['reason'])->toBe('quota');
});

it('clamps timestamps and preserves valid event uuids', function () {
    $uuid = '019a8f01-0060-7000-8000-000000000001';
    batch([$this->event + ['ts' => '2020-01-01T00:00:00Z', 'event_id' => $uuid], $this->event + ['ts' => 2000000000], $this->event + ['ts' => '2026-11-16T13:00:00+02:00']], $this->secret)
        ->assertJsonPath('accepted', 3);
    expect(array_column($this->queue->events, 'ts'))->toBe(['2026-11-14 12:00:00.000', '2026-11-17 12:05:00.000', '2026-11-16 11:00:00.000'])
        ->and($this->queue->events[0]['event_id'])->toBe($uuid);
});

it('rate limits per key and leaves other keys available', function () {
    config(['ryvr.ingest.batch_rate_limit' => 1]);
    batch([$this->event], $this->secret)->assertStatus(202);
    batch([$this->event], $this->secret)->assertStatus(429)->assertHeader('Retry-After');
    [, $other] = SecretIngestKey::issue($this->site);
    batch([$this->event], $other)->assertStatus(202);
});

it('stores hmac digests and shows newly created rotated keys only once', function () {
    $user = User::factory()->create();
    $this->org->addMember($user, Role::Owner);
    $user->switchOrganization($this->org);
    $this->actingAs($user)->from('/sites/'.$this->site->id.'/settings')
        ->post('/sites/'.$this->site->id.'/secret-keys')->assertRedirect()->assertSessionHas('newIngestKey');
    $plain = session('newIngestKey');
    $key = SecretIngestKey::where('token_hash', ApiKey::digest($plain))->firstOrFail();
    expect($plain)->toStartWith('ryvr_sk_')->and($key->toArray())->not->toHaveKey('token_hash');
    $this->get('/sites/'.$this->site->id.'/settings')->assertOk()->assertInertia(fn (Assert $page) => $page->where('once.newIngestKey', $plain));
    $this->get('/sites/'.$this->site->id.'/settings')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('once.newIngestKey'));
    $this->post('/sites/'.$this->site->id.'/secret-keys/'.$key->id.'/rotate')->assertRedirect()->assertSessionHas('newIngestKey');
    batch([$this->event], $plain)->assertUnauthorized();
    $replacement = session('newIngestKey');
    $new = SecretIngestKey::findActive($replacement);
    $this->delete('/sites/'.$this->site->id.'/secret-keys/'.$new->id)->assertRedirect();
    batch([$this->event], $replacement)->assertUnauthorized();
    Cache::flush();
    expect(SecretIngestKey::findActive($replacement))->toBeNull();
});

it('blocks secret key management for viewers and other organizations', function () {
    $user = User::factory()->create();
    $this->org->addMember($user, Role::Viewer);
    $user->switchOrganization($this->org);
    $this->actingAs($user)->post('/sites/'.$this->site->id.'/secret-keys')->assertNotFound();
    $this->org->addMember($user, Role::Admin);
    $other = Organization::create(['name' => 'Other', 'slug' => 'other']);
    $site = Project::create(['organization_id' => $other->id, 'name' => 'Other', 'domain' => 'other.test']);
    [$key] = SecretIngestKey::issue($site);
    $this->post('/sites/'.$this->site->id.'/secret-keys/'.$key->id.'/rotate')->assertNotFound();
    $this->delete('/sites/'.$this->site->id.'/secret-keys/'.$key->id)->assertNotFound();
});

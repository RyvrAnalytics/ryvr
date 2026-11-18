<?php

use App\Ingestion\Queue\ArrayQueue;
use App\Ingestion\Queue\IngestQueue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeClickHouse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    $this->queue = new ArrayQueue;
    app()->instance(IngestQueue::class, $this->queue);
    $this->clickhouse = new FakeClickHouse;
    app()->instance('clickhouse.read', $this->clickhouse);
    $this->org = Organization::create(['name' => 'Team', 'slug' => 'team']);
    $this->site = Project::create(['organization_id' => $this->org->id, 'name' => 'Main', 'domain' => 'example.test']);
});

function quotaHit(Project $site)
{
    return test()->postJson('/e', ['k' => $site->ingest_key, 'u' => 'https://'.$site->domain.'/'], ['User-Agent' => CHROME]);
}

it('keeps defaults unlimited without reading usage', function () {
    quotaHit($this->site)->assertStatus(202);
    expect($this->queue->events)->toHaveCount(1)->and($this->clickhouse->queries)->toBeEmpty();
});

it('sheds at a hard cap using cached monthly usage and resumes after the cache expires', function () {
    $this->org->forceFill(['monthly_events' => 10, 'overage' => 'hard_cap'])->save();
    $this->clickhouse->rows = [['organization_id' => $this->org->id, 'accepted' => 10]];
    quotaHit($this->site)->assertStatus(202);
    $this->clickhouse->rows = [['organization_id' => $this->org->id, 'accepted' => 0]];
    quotaHit($this->site)->assertStatus(202);
    expect($this->queue->events)->toBeEmpty()->and(array_column($this->queue->drops, 'reason'))->toBe(['quota', 'quota'])
        ->and($this->clickhouse->queries)->toHaveCount(1);
    $this->travel(61)->seconds();
    quotaHit($this->site)->assertStatus(202);
    expect($this->queue->events)->toHaveCount(1);
});

it('allows bill overages and treats a zero hard cap as zero', function () {
    $this->org->forceFill(['monthly_events' => 0, 'overage' => 'bill'])->save();
    quotaHit($this->site);
    $this->org->forceFill(['overage' => 'hard_cap'])->save();
    quotaHit($this->site);
    expect($this->queue->events)->toHaveCount(1)->and($this->queue->drops[0]['reason'])->toBe('quota');
});

it('shares a refillable burst bucket across sites of one organization', function () {
    $this->org->forceFill(['burst_per_second' => 2])->save();
    $second = Project::create(['organization_id' => $this->org->id, 'name' => 'Second', 'domain' => 'second.test']);
    quotaHit($this->site);
    quotaHit($second);
    quotaHit($this->site);
    expect($this->queue->events)->toHaveCount(2)->and($this->queue->drops[0]['reason'])->toBe('quota');
    $this->travel(500)->milliseconds();
    quotaHit($second);
    quotaHit($second);
    expect($this->queue->events)->toHaveCount(3)->and($this->queue->drops)->toHaveCount(2);
});

it('isolates usage and burst buckets between organizations and calendar months', function () {
    $this->org->forceFill(['monthly_events' => 1, 'overage' => 'hard_cap', 'burst_per_second' => 1])->save();
    $this->clickhouse->rows = [['organization_id' => $this->org->id, 'accepted' => 1]];
    quotaHit($this->site);
    $other = Organization::create(['name' => 'Other', 'slug' => 'other']);
    $site = Project::create(['organization_id' => $other->id, 'name' => 'Other', 'domain' => 'other.test']);
    quotaHit($site);
    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'));
    $this->clickhouse->rows = [];
    quotaHit($this->site);
    expect($this->queue->events)->toHaveCount(2)->and($this->queue->drops)->toHaveCount(1);
});

it('changes quotas from the administrative command by slug or id and can reset limits', function () {
    $this->artisan('ryvr:org:quota', ['organization' => 'team', '--monthly' => '100', '--overage' => 'hard_cap', '--burst' => '5'])->assertSuccessful();
    expect($this->org->fresh())->monthly_events->toBe(100)->overage->toBe('hard_cap')->burst_per_second->toBe(5);
    $this->artisan('ryvr:org:quota', ['organization' => $this->org->id, '--monthly' => 'unlimited', '--burst' => '0'])->assertSuccessful();
    expect($this->org->fresh())->monthly_events->toBeNull()->burst_per_second->toBe(0)->overage->toBe('hard_cap');
});

it('validates quota options without changing any fields', function (array $options) {
    $this->artisan('ryvr:org:quota', ['organization' => 'team'] + $options)->assertFailed();
    expect($this->org->fresh())->monthly_events->toBeNull()->overage->toBe('bill')->burst_per_second->toBe(0);
})->with([[['--monthly' => '-1']], [['--monthly' => 'lots']], [['--burst' => '-1']], [['--overage' => 'ignore']]]);

it('refuses nonadministrator callers and allows instance administrators', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->artisan('ryvr:org:quota', ['organization' => 'team', '--monthly' => '100'])->assertFailed();
    $user->forceFill(['is_instance_admin' => true])->save();
    $this->artisan('ryvr:org:quota', ['organization' => 'team', '--monthly' => '100'])->assertSuccessful();
});

<?php

use App\Ingestion\Exclusion\Field;
use App\Ingestion\Exclusion\Operator;
use App\Ingestion\Queue\ArrayQueue;
use App\Ingestion\Queue\IngestQueue;
use App\Models\ExclusionRule;
use App\Models\Organization;
use App\Models\Project;

beforeEach(function () {
    $this->queue = new ArrayQueue;
    $this->app->instance(IngestQueue::class, $this->queue);

    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'domain' => 'acme.com']);
});

function hit(array $payload, string $ua = CHROME, array $server = [])
{
    return test()->call('POST', '/e', [], [], [], ['HTTP_USER_AGENT' => $ua, 'CONTENT_TYPE' => 'text/plain'] + $server, json_encode($payload));
}

it('accepts a pageview and enriches it', function () {
    hit(['k' => $this->project->ingest_key, 'u' => 'https://www.acme.com/pricing/?utm_source=launch', 'r' => 'https://news.ycombinator.com/item?id=1', 'w' => 1440])
        ->assertStatus(202)
        ->assertHeader('Access-Control-Allow-Origin', '*');

    expect($this->queue->events)->toHaveCount(1);
    $event = $this->queue->events[0];

    expect($event)
        ->project_id->toBe($this->project->id)
        ->organization_id->toBe($this->org->id)
        ->name->toBe('pageview')
        ->hostname->toBe('www.acme.com')
        ->pathname->toBe('/pricing')
        ->referrer_domain->toBe('news.ycombinator.com')
        ->channel->toBe('Social')
        ->utm_source->toBe('launch')
        ->browser->toBe('Chrome')
        ->device->toBe('desktop');
    expect($event['visitor_id'])->toBeGreaterThan(0)
        ->and($event['session_id'])->toBeGreaterThan(0);
});

it('keeps one session across hits from the same visitor', function () {
    hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/']);
    hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/about']);

    [$a, $b] = $this->queue->events;
    expect($a['visitor_id'])->toBe($b['visitor_id'])->and($a['session_id'])->toBe($b['session_id']);
});

it('carries the entry source through the whole visit', function () {
    hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/?utm_source=launch', 'r' => 'https://www.google.com/']);
    hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/pricing', 'r' => 'https://acme.com/']);

    expect($this->queue->events[1])
        ->channel->toBe('Search')
        ->referrer_domain->toBe('google.com')
        ->utm_source->toBe('launch');
});

it('never stores the IP address', function () {
    hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/'], CHROME, ['REMOTE_ADDR' => '203.0.113.9']);

    expect(json_encode($this->queue->events))->not->toContain('203.0.113.9');
});

it('rejects unknown keys and malformed bodies', function () {
    hit(['k' => 'ryvr_ingest_doesnotexist', 'u' => 'https://acme.com/'])->assertStatus(400);
    test()->call('POST', '/e', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'not json')->assertStatus(400);
    hit(['k' => $this->project->ingest_key, 'u' => 'javascript:alert(1)'])->assertStatus(400);

    expect($this->queue->events)->toBeEmpty();
});

it('drops hits from other hostnames without telling the caller', function () {
    hit(['k' => $this->project->ingest_key, 'u' => 'https://copycat.example/'])->assertStatus(202);

    expect($this->queue->events)->toBeEmpty()
        ->and($this->queue->drops)->toBe([['project_id' => $this->project->id, 'reason' => 'hostname']]);
});

it('accepts extra hostnames and wildcard subdomains', function () {
    $this->project->update(['allowed_hostnames' => ['*.acme.app', 'staging.acme.dev']]);

    hit(['k' => $this->project->ingest_key, 'u' => 'https://eu.acme.app/']);
    hit(['k' => $this->project->ingest_key, 'u' => 'https://staging.acme.dev/']);

    expect($this->queue->events)->toHaveCount(2);
});

it('drops monitoring probes, AI agents and automated browsers', function (string $ua, array $extra, string $reason) {
    hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/'] + $extra, $ua)->assertStatus(202);

    expect($this->queue->events)->toBeEmpty()
        ->and($this->queue->drops[0]['reason'])->toBe($reason);
})->with([
    'grafana probe' => ['Grafana/11.3.0', [], 'monitor'],
    'blackbox exporter' => ['Blackbox Exporter/0.25.0', [], 'monitor'],
    'ai agent' => ['Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)', [], 'ai_agent'],
    'webdriver browser' => [CHROME, ['wd' => 1], 'automation'],
]);

it('applies organization and project exclusion rules', function () {
    ExclusionRule::create(['organization_id' => $this->org->id, 'field' => Field::Ip, 'operator' => Operator::Cidr, 'value' => '198.51.100.0/24']);
    ExclusionRule::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'field' => Field::Path, 'operator' => Operator::StartsWith, 'value' => '/admin']);

    hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/'], CHROME, ['REMOTE_ADDR' => '198.51.100.20']);
    hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/admin/users']);
    hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/blog']);

    expect($this->queue->events)->toHaveCount(1)
        ->and($this->queue->events[0]['pathname'])->toBe('/blog')
        ->and(array_column($this->queue->drops, 'reason'))->toBe(['rule', 'rule']);
});

it('ignores disabled rules', function () {
    ExclusionRule::create(['organization_id' => $this->org->id, 'field' => Field::Path, 'operator' => Operator::Equals, 'value' => '/', 'enabled' => false]);

    hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/']);

    expect($this->queue->events)->toHaveCount(1);
});

it('caps custom event names and properties', function () {
    $props = [];
    $props = ['long' => str_repeat('v', 1000), 'nested' => ['no' => 'arrays']];
    for ($i = 0; $i < 50; $i++) {
        $props["k{$i}"] = 'x';
    }

    hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/', 'n' => str_repeat('E', 500), 'p' => $props])->assertStatus(202);

    $event = $this->queue->events[0];
    expect(mb_strlen($event['name']))->toBe(120)
        ->and($event['props'])->toHaveCount(30)
        ->and(mb_strlen($event['props']['long']))->toBe(512)
        ->and($event['props'])->not->toHaveKey('nested');
});

it('rejects oversized bodies', function () {
    hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/', 'p' => ['x' => str_repeat('v', 9000)]])->assertStatus(400);
});

it('records revenue with a currency', function () {
    hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/thanks', 'n' => 'Purchase', '$' => 49.99, 'cur' => 'cad']);

    expect($this->queue->events[0])->revenue->toBe(49.99)->currency->toBe('CAD');
});

it('stops collecting for archived projects and rotated keys', function () {
    $oldKey = $this->project->ingest_key;
    $this->project->rotateIngestKey();

    hit(['k' => $oldKey, 'u' => 'https://acme.com/'])->assertStatus(400);
    hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/'])->assertStatus(202);

    $this->project->archive();
    hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/'])->assertStatus(400);
});

it('keeps collecting while the app is in maintenance mode', function () {
    $this->artisan('down');

    try {
        hit(['k' => $this->project->ingest_key, 'u' => 'https://acme.com/'])->assertStatus(202);
        $this->get('/login')->assertStatus(503);
    } finally {
        $this->artisan('up');
    }

    expect($this->queue->events)->toHaveCount(1);
});

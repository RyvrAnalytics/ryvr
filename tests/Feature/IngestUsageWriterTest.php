<?php

use App\Console\Commands\IngestWorkCommand;
use App\Models\Organization;
use App\Models\Project;
use Tests\Fakes\FakeClickHouse;

it('meters accepted filtered and shed rows by organization site and intake hour with stable retry tokens', function () {
    $org = Organization::create(['name' => 'Team', 'slug' => 'team']);
    $site = Project::create(['organization_id' => $org->id, 'name' => 'Main', 'domain' => 'example.test']);
    $other = Organization::create(['name' => 'Other', 'slug' => 'other']);
    $second = Project::create(['organization_id' => $other->id, 'name' => 'Second', 'domain' => 'second.test']);
    $event = ['project_id' => $site->id, 'organization_id' => $org->id, 'props' => [], 'ts' => '2026-11-14 12:00:00'];
    $messages = [
        '1794916800000-0' => ['t' => 'e', 'd' => json_encode($event)],
        '1794916800001-0' => ['t' => 'd', 'd' => json_encode(['project_id' => $site->id, 'day' => '2026-11-17', 'reason' => 'bot'])],
        '1794916800002-0' => ['t' => 'd', 'd' => json_encode(['project_id' => $site->id, 'day' => '2026-11-17', 'reason' => 'quota'])],
        '1794920400000-0' => ['t' => 'e', 'd' => json_encode(array_replace($event, ['project_id' => $second->id, 'organization_id' => $other->id]))],
    ];
    $fake = new FakeClickHouse;
    $method = new ReflectionMethod(IngestWorkCommand::class, 'write');
    $command = new IngestWorkCommand;
    expect($method->invoke($command, $fake, $messages))->toBe(2);
    $method->invoke($command, $fake, $messages);
    $usage = $fake->queries[2];
    expect($usage['sql'])->toBe('INSERT INTO ingest_usage')
        ->and($usage['params']['token'])->toBe($fake->queries[0]['params']['token'])->toBe($fake->queries[5]['params']['token'])
        ->and($usage['params']['rows'])->toHaveCount(2)
        ->and($usage['params']['rows'][0])->toMatchArray(['organization_id' => $org->id, 'project_id' => $site->id, 'hour' => '2026-11-17 12:00:00', 'accepted' => 1, 'filtered' => 1, 'shed' => 1])
        ->and($usage['params']['rows'][0]['bytes'])->toBe(array_sum(array_map(fn ($entry) => strlen($entry['d']), array_slice($messages, 0, 3))))
        ->and($usage['params']['rows'][1])->toMatchArray(['organization_id' => $other->id, 'project_id' => $second->id, 'hour' => '2026-11-17 13:00:00', 'accepted' => 1, 'filtered' => 0, 'shed' => 0]);
});

it('does not acknowledge a batch whose usage write failed and reuses the token on retry', function () {
    $org = Organization::create(['name' => 'Team', 'slug' => 'team']);
    $site = Project::create(['organization_id' => $org->id, 'name' => 'Main', 'domain' => 'example.test']);
    $fake = new class extends FakeClickHouse
    {
        public bool $fail = true;

        public function insert(string $table, array $rows, bool $async = true, ?string $deduplicationToken = null): void
        {
            parent::insert($table, $rows, $async, $deduplicationToken);
            if ($table === 'ingest_usage' && $this->fail) {
                $this->fail = false;
                throw new RuntimeException('Interrupted usage write');
            }
        }
    };
    $messages = ['1794916800000-0' => ['t' => 'e', 'd' => json_encode(['project_id' => $site->id, 'organization_id' => $org->id])]];
    $method = new ReflectionMethod(IngestWorkCommand::class, 'write');
    $command = new IngestWorkCommand;
    expect(fn () => $method->invoke($command, $fake, $messages))->toThrow(RuntimeException::class, 'Interrupted usage write');
    expect($method->invoke($command, $fake, $messages))->toBe(1);
    expect($fake->queries[2]['params'])->toBe($fake->queries[5]['params']);
});

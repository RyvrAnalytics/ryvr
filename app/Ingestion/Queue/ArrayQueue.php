<?php

namespace App\Ingestion\Queue;

/**
 * In-memory queue for tests.
 */
class ArrayQueue implements IngestQueue
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    /** @var list<array{project_id: string, reason: string}> */
    public array $drops = [];

    public function push(array $event): void
    {
        $this->events[] = $event;
    }

    public function drop(string $projectId, string $reason): void
    {
        $this->drops[] = ['project_id' => $projectId, 'reason' => $reason];
    }
}

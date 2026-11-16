<?php

namespace App\Ingestion\Queue;

interface IngestQueue
{
    /**
     * Buffer one accepted event (already enriched, ready for the event store).
     *
     * @param  array<string, mixed>  $event
     */
    public function push(array $event): void;

    /**
     * Count one filtered hit so the dashboard can show what was excluded and why.
     */
    public function drop(string $projectId, string $reason): void;
}

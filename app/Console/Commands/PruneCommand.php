<?php

namespace App\Console\Commands;

use App\Analytics\ClickHouse\ClickHouse;
use App\Ingestion\Enrichment\Salts;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Project;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ryvr:prune')]
#[Description('Delete expired salts and invitations, and events older than each site\'s retention period')]
class PruneCommand extends Command
{
    public function handle(Salts $salts, ClickHouse $clickhouse): int
    {
        $this->components->task('Expired visitor salts', fn () => $salts->purgeOld() >= 0);
        $this->components->task('Expired invitations', fn () => Invitation::whereNull('accepted_at')->where('expires_at', '<', now()->subDays(30))->delete() >= 0);

        $default = (int) config('ryvr.retention_months', 0);
        foreach (Project::query()->get() as $project) {
            $months = (int) $project->setting('retention_months', $default);
            if ($months <= 0) {
                continue;
            }

            $cutoff = now()->utc()->subMonthsNoOverflow($months)->startOfMonth();
            $this->components->task("{$project->name}: events before {$cutoff->toDateString()}", function () use ($clickhouse, $project, $cutoff) {
                // Whole old partitions are cheap to drop; lightweight deletes cover partial months.
                $clickhouse->statement(
                    'DELETE FROM events WHERE project_id = {p:UUID} AND ts < {cutoff:DateTime64(3)}',
                    ['p' => $project->id, 'cutoff' => $cutoff],
                );
                $clickhouse->statement(
                    'DELETE FROM ingest_drops WHERE project_id = {p:UUID} AND day < toDate({cutoff:DateTime64(3)})',
                    ['p' => $project->id, 'cutoff' => $cutoff],
                );
                AuditLog::record('retention.applied', $project->organization_id, $project, ['before' => $cutoff->toDateString()]);
            });
        }

        return self::SUCCESS;
    }
}

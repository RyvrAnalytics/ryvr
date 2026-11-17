<?php

namespace App\Console\Commands;

use App\Analytics\Analytics;
use App\Analytics\ClickHouse\ClickHouse;
use App\Analytics\Query;
use App\Ingestion\Collector;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

#[Signature('ryvr:smoke')]
#[Description('End-to-end check: collect hits, write them through the stream, read them back, clean up')]
class SmokeTestCommand extends Command
{
    public function handle(Collector $collector, Analytics $analytics, ClickHouse $clickhouse): int
    {
        $organization = Organization::create(['name' => 'Smoke test', 'slug' => 'smoke-'.Str::lower(Str::random(8))]);
        $project = Project::create(['organization_id' => $organization->id, 'name' => 'Smoke', 'domain' => 'smoke.invalid']);

        try {
            $browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
            $hits = [
                [['u' => 'https://smoke.invalid/', 'r' => 'https://www.google.com/'], $browser],
                [['u' => 'https://smoke.invalid/pricing'], $browser],
                [['u' => 'https://smoke.invalid/', 'n' => 'Signup', 'p' => ['plan' => 'pro']], $browser],
                [['u' => 'https://smoke.invalid/'], 'Blackbox Exporter/0.25.0'],
            ];

            foreach ($hits as [$payload, $ua]) {
                $request = Request::create('/e', 'POST', server: ['HTTP_USER_AGENT' => $ua, 'REMOTE_ADDR' => '203.0.113.10']);
                $collector->collect(['k' => $project->ingest_key] + $payload, $request);
            }

            Artisan::call('ryvr:ingest:work', ['--once' => true]);

            $query = Query::forPeriod([$project->id], 'today', 'UTC');
            $summary = $analytics->summary($query);
            $drops = $analytics->drops($query);

            $this->table(['metric', 'value'], collect($summary)->map(fn ($v, $k) => [$k, $v])->values()->all());

            $ok = $summary['visitors'] === 1 && $summary['pageviews'] === 2 && $summary['events'] === 1 && ($drops['monitor'] ?? 0) === 1;
            $ok ? $this->components->info('Smoke test passed.') : $this->components->error('Smoke test failed: '.json_encode(['summary' => $summary, 'drops' => $drops]));

            return $ok ? self::SUCCESS : self::FAILURE;
        } finally {
            $clickhouse->statement('DELETE FROM events WHERE project_id = {p:UUID}', ['p' => $project->id]);
            $clickhouse->statement('DELETE FROM ingest_drops WHERE project_id = {p:UUID}', ['p' => $project->id]);
            $organization->delete();
        }
    }
}

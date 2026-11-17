<?php

namespace App\Console\Commands;

use App\Analytics\ClickHouse\ClickHouse;
use App\Ingestion\Enrichment\Source;
use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('ryvr:demo {project? : Project id (default: every project)} {--days=60} {--visits=300 : Average visits per day} {--force : Allow in production}')]
#[Description('Generate realistic sample traffic for local development and demos')]
class DemoDataCommand extends Command
{
    private const PAGES = ['/' => 30, '/pricing' => 12, '/features' => 10, '/blog' => 8, '/blog/launch-week' => 6,
        '/blog/privacy-first-analytics' => 5, '/docs' => 7, '/docs/install' => 6, '/about' => 4, '/contact' => 3, '/signup' => 5, '/login' => 4];

    private const SOURCES = [
        ['', '', '', 30], ['https://www.google.com/', '', '', 28], ['https://duckduckgo.com/', '', '', 4],
        ['https://news.ycombinator.com/', '', '', 5], ['https://www.reddit.com/r/selfhosted/', '', '', 5],
        ['https://chatgpt.com/', '', '', 4], ['https://www.perplexity.ai/', '', '', 2], ['https://t.co/x', '', '', 4],
        ['https://www.linkedin.com/', '', '', 3], ['https://github.com/', '', '', 5],
        ['', 'newsletter', 'email', 4], ['https://www.google.com/', 'google', 'cpc', 3],
    ];

    private const COUNTRIES = ['US' => 34, 'CA' => 14, 'GB' => 10, 'DE' => 8, 'FR' => 5, 'IN' => 6, 'NL' => 4, 'AU' => 4, 'BR' => 3, 'JP' => 3, 'SE' => 2, 'ES' => 3];

    private const AGENTS = [
        ['Chrome', '131', 'Windows', '10/11', 'desktop', 1536, 30], ['Chrome', '131', 'macOS', '10.15', 'desktop', 1440, 16],
        ['Safari', '18', 'macOS', '10.15', 'desktop', 1512, 8], ['Safari', '18', 'iOS', '18', 'mobile', 393, 18],
        ['Chrome', '131', 'Android', '14', 'mobile', 412, 14], ['Firefox', '133', 'Windows', '10/11', 'desktop', 1920, 5],
        ['Edge', '131', 'Windows', '10/11', 'desktop', 1536, 6], ['Samsung Internet', '26', 'Android', '14', 'mobile', 384, 2],
        ['Safari', '18', 'iPadOS', '18', 'tablet', 820, 1],
    ];

    public function handle(ClickHouse $clickhouse, Source $sources): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error('Refusing to write sample data in production without --force.');

            return self::FAILURE;
        }

        $projects = $this->argument('project')
            ? Project::whereKey($this->argument('project'))->get()
            : Project::all();

        foreach ($projects as $index => $project) {
            $scale = 1 / (1 + $index * 0.8);
            $rows = [];
            $total = 0;
            for ($d = (int) $this->option('days') - 1; $d >= 0; $d--) {
                $day = now($project->timezone)->startOfDay()->subDays($d);
                $weekday = in_array($day->dayOfWeekIso, [6, 7], true) ? 0.65 : 1.0;
                $growth = 0.7 + 0.6 * (1 - $d / max(1, (int) $this->option('days')));
                $visits = (int) round((int) $this->option('visits') * $scale * $weekday * $growth * (0.85 + mt_rand() / mt_getrandmax() * 0.3));

                for ($v = 0; $v < $visits; $v++) {
                    array_push($rows, ...$this->visit($project, $day, $sources));
                    if (count($rows) >= 20000) {
                        $clickhouse->insert('events', $rows, async: false);
                        $total += count($rows);
                        $rows = [];
                    }
                }
            }
            $clickhouse->insert('events', $rows, async: false);
            $total += count($rows);
            $this->components->info("{$project->name}: {$total} events");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function visit(Project $project, CarbonInterface $day, Source $sources): array
    {
        $hour = $this->weighted([0 => 1, 3 => 1, 6 => 3, 8 => 6, 9 => 8, 10 => 9, 11 => 9, 13 => 9, 14 => 9, 15 => 8, 17 => 6, 19 => 5, 21 => 4, 23 => 2]);
        $start = $day->copy()->setTime($hour, random_int(0, 59), random_int(0, 59))->utc();
        if ($start->isFuture()) {
            $start = now()->utc()->subMinutes(random_int(1, 300));
        }

        [$referrer, $utmSource, $utmMedium] = $this->weightedRow(self::SOURCES);
        $query = array_filter(['utm_source' => $utmSource, 'utm_medium' => $utmMedium, 'utm_campaign' => $utmSource ? 'autumn-launch' : '']);
        $source = $sources->resolve($referrer, $query, $project->hostnames());
        [$browser, $bv, $os, $osv, $device, $width] = $this->weightedRow(self::AGENTS);
        $country = $this->weighted(self::COUNTRIES);

        $visitor = random_int(1, PHP_INT_MAX >> 3);
        $session = random_int(1, PHP_INT_MAX >> 3);
        $pages = $this->weighted([1 => 45, 2 => 22, 3 => 14, 4 => 9, 5 => 6, 7 => 4]);

        $base = [
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'visitor_id' => $visitor, 'session_id' => $session,
            'hostname' => $project->domain, 'country' => $country, 'region' => '', 'city' => '', 'browser' => $browser, 'browser_version' => $bv,
            'os' => $os, 'os_version' => $osv, 'device' => $device, 'screen_width' => $width, 'language' => 'en-us',
            'revenue' => 0, 'currency' => '', 'consent' => 'none', 'props' => new \stdClass,
        ] + $source;

        $rows = [];
        $ts = $start;
        $path = $referrer === '' && $utmSource === '' ? '/' : $this->weighted(self::PAGES);
        for ($p = 0; $p < $pages; $p++) {
            $rows[] = ['ts' => $ts->format('Y-m-d H:i:s.v'), 'event_id' => (string) Str::uuid7(), 'name' => 'pageview', 'pathname' => $path, 'engagement_ms' => 0] + $base;
            $stay = random_int(4, 180);
            $rows[] = ['ts' => $ts->copy()->addSeconds($stay)->format('Y-m-d H:i:s.v'), 'event_id' => (string) Str::uuid7(), 'name' => 'engagement', 'pathname' => $path, 'engagement_ms' => $stay * 1000] + $base;

            if ($path === '/pricing' && random_int(1, 100) <= 18) {
                $plan = $this->weighted(['starter' => 5, 'pro' => 3, 'team' => 1]);
                $rows[] = ['ts' => $ts->copy()->addSeconds($stay - 1)->format('Y-m-d H:i:s.v'), 'event_id' => (string) Str::uuid7(), 'name' => 'Signup', 'pathname' => $path, 'engagement_ms' => 0, 'props' => ['plan' => $plan]] + $base;
            }
            if (random_int(1, 100) <= 4) {
                $rows[] = ['ts' => $ts->copy()->addSeconds(2)->format('Y-m-d H:i:s.v'), 'event_id' => (string) Str::uuid7(), 'name' => 'Outbound link', 'pathname' => $path, 'engagement_ms' => 0, 'props' => ['url' => 'https://github.com/']] + $base;
            }

            $ts = $ts->copy()->addSeconds($stay + random_int(1, 5));
            $path = $this->weighted(self::PAGES);
        }

        return $rows;
    }

    /**
     * @template TKey of array-key
     *
     * @param  non-empty-array<TKey, int>  $weights
     * @return TKey
     */
    private function weighted(array $weights): int|string
    {
        $roll = random_int(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            if (($roll -= $weight) <= 0) {
                return $value;
            }
        }

        return array_key_first($weights);
    }

    /**
     * @param  non-empty-list<array<int, mixed>>  $rows  rows whose last element is the weight
     * @return array<int, mixed>
     */
    private function weightedRow(array $rows): array
    {
        $index = $this->weighted(array_map(fn ($r) => end($r), $rows));

        return $rows[$index];
    }
}

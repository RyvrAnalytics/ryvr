<?php

namespace Database\Seeders;

use App\Analytics\ClickHouse\ClickHouse;
use App\Enums\Role;
use App\Ingestion\Exclusion\Field;
use App\Ingestion\Exclusion\Operator;
use App\Models\ApiKey;
use App\Models\ExclusionRule;
use App\Models\Goal;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectGrant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Fixed demo data for the README screenshots: a studio with four sites, a small team,
 * goals, exclusion rules, API keys and seeded traffic (including visitors right now).
 * Needs PostgreSQL and ClickHouse. Safe to run again: it replaces its own events.
 *
 * Run with: php artisan db:seed --class=ScreenshotSeeder
 * Sign in as alex@northwind.test with the password "password".
 */
class ScreenshotSeeder extends Seeder
{
    /** name, domain, average visits per day, visitors right now */
    private const SITES = [
        ['Northwind', 'northwind.example', 420, 30],
        ['Northwind Docs', 'docs.northwind.example', 260, 14],
        ['Fieldnote', 'fieldnote.example', 170, 8],
        ['Harbor Coffee', 'harborcoffee.example', 90, 4],
    ];

    /** reason => hits per day for the first site; later sites get fewer */
    private const DROPS = ['bot' => 40, 'ai_agent' => 22, 'monitor' => 18, 'automation' => 6, 'rule' => 9, 'referrer_spam' => 3];

    public function run(ClickHouse $clickhouse): void
    {
        $owner = $this->user('alex@northwind.test', 'Alex Morgan', admin: true);

        $organization = Organization::firstOrCreate(['slug' => 'northwind'], ['name' => 'Northwind Studio', 'timezone' => 'America/Toronto']);
        $organization->addMember($owner, Role::Owner);
        $owner->switchOrganization($organization);

        $projects = [];
        foreach (self::SITES as [$name, $domain]) {
            $projects[] = Project::firstOrCreate(
                ['organization_id' => $organization->id, 'domain' => $domain],
                ['name' => $name, 'timezone' => 'America/Toronto'],
            );
        }
        [$main, $docs, , $coffee] = $projects;

        $organization->addMember($this->user('priya@northwind.test', 'Priya Shah'), Role::Admin);
        $organization->addMember($this->user('sam@northwind.test', 'Sam Lee'), Role::Analyst);
        $client = $this->user('jordan@harborcoffee.test', 'Jordan Ellis');
        $organization->addMember($client, Role::Viewer, 'granted_only');
        ProjectGrant::firstOrCreate(['organization_id' => $organization->id, 'user_id' => $client->id, 'project_id' => $coffee->id], ['role' => Role::Viewer]);

        foreach ([[$main, 'Signed up', 'event', 'Signup'], [$main, 'Saw pricing', 'pageview', '/pricing'], [$docs, 'Read the install guide', 'pageview', '/docs/install']] as [$project, $name, $type, $value]) {
            Goal::firstOrCreate(['project_id' => $project->id, 'name' => $name], ['organization_id' => $organization->id, 'type' => $type, 'value' => $value]);
        }

        $rules = [
            [null, Field::Ip, Operator::Cidr, '203.0.113.0/24', 'Studio office network'],
            [null, Field::UserAgent, Operator::Contains, 'uptime-checker', 'Our own uptime checks'],
            [$main->id, Field::Path, Operator::StartsWith, '/admin', 'Staff pages'],
            [$main->id, Field::Hostname, Operator::Equals, 'staging.northwind.example', 'Staging'],
            [$main->id, Field::Referrer, Operator::Contains, 'free-traffic', 'Referrer spam'],
        ];
        foreach ($rules as [$projectId, $field, $operator, $value, $note]) {
            ExclusionRule::firstOrCreate(
                ['organization_id' => $organization->id, 'project_id' => $projectId, 'field' => $field, 'value' => $value],
                ['operator' => $operator, 'note' => $note, 'enabled' => true],
            );
        }

        if (! ApiKey::where('organization_id', $organization->id)->exists()) {
            [$report] = ApiKey::issue(['organization_id' => $organization->id, 'name' => 'Weekly report script', 'type' => 'org', 'scopes' => ['analytics:read'], 'expires_at' => now()->addDays(90)]);
            $report->forceFill(['last_used_at' => now()->subHours(3)])->save();
            [$assistant] = ApiKey::issue(['organization_id' => $organization->id, 'user_id' => $owner->id, 'name' => 'Assistant over MCP', 'type' => 'pat', 'scopes' => ['analytics:read']]);
            $assistant->forceFill(['last_used_at' => now()->subMinutes(12)])->save();
        }

        $ids = array_map(fn (Project $p) => $p->id, $projects);
        $clickhouse->statement('DELETE FROM events WHERE project_id IN {p:Array(UUID)}', ['p' => $ids]);
        $clickhouse->statement('DELETE FROM ingest_drops WHERE project_id IN {p:Array(UUID)}', ['p' => $ids]);

        foreach (self::SITES as $i => [, , $visits, $live]) {
            Artisan::call('ryvr:demo', ['project' => $ids[$i], '--days' => 60, '--visits' => $visits, '--live' => $live, '--seed' => 1000 + $i]);
            $this->command?->info(trim(Artisan::output()));

            $drops = [];
            for ($d = 0; $d < 60; $d++) {
                foreach (self::DROPS as $reason => $hits) {
                    $drops[] = ['project_id' => $ids[$i], 'day' => now()->subDays($d)->toDateString(), 'reason' => $reason, 'hits' => intdiv($hits * (8 + ($d * 7 + $i * 3) % 5), 10 * ($i + 1))];
                }
            }
            $clickhouse->insert('ingest_drops', $drops, async: false);
        }
    }

    private function user(string $email, string $name, bool $admin = false): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => 'password', 'email_verified_at' => now(), 'is_instance_admin' => $admin],
        );
    }
}

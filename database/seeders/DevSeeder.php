<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development data: one owner, one organization, two projects.
 * Run with: php artisan db:seed --class=DevSeeder
 */
class DevSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'dev@ryvr.test'],
            ['name' => 'Dev User', 'password' => 'password', 'email_verified_at' => now(), 'is_instance_admin' => true],
        );

        $organization = Organization::firstOrCreate(['slug' => 'dev'], ['name' => 'Dev Studio', 'timezone' => 'America/Toronto']);
        $organization->addMember($user, Role::Owner);
        $user->switchOrganization($organization);

        foreach (['example.test' => 'Example', 'shop.example.test' => 'Example Shop'] as $domain => $name) {
            $project = Project::firstOrCreate(
                ['organization_id' => $organization->id, 'domain' => $domain],
                ['name' => $name, 'timezone' => 'America/Toronto'],
            );
            $this->command?->info("{$name}: {$project->ingest_key}");
        }
    }
}

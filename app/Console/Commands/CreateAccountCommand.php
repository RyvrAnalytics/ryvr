<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

#[Signature('ryvr:account:create {email} {name} {organization} {--admin : Also make the user an instance admin} {--timezone=UTC}')]
#[Description('Create (or reuse) a user and an organization they own, and print a one-time link to set the password')]
class CreateAccountCommand extends Command
{
    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('That is not a valid email address.');

            return self::FAILURE;
        }

        [$user, $organization] = DB::transaction(function () use ($email) {
            $user = User::where('email', $email)->first() ?? User::create([
                'name' => $this->argument('name'),
                'email' => $email,
                // Unusable until the owner sets their own password through the link below.
                'password' => Str::random(64),
            ]);
            $user->forceFill([
                'email_verified_at' => $user->email_verified_at ?? now(),
                'is_instance_admin' => $user->is_instance_admin || $this->option('admin'),
            ])->save();

            $name = (string) $this->argument('organization');
            $organization = Organization::whereHas('memberships', fn ($q) => $q->where('user_id', $user->id))->where('name', $name)->first()
                ?? Organization::create(['name' => $name, 'slug' => Organization::uniqueSlug($name), 'timezone' => $this->option('timezone')]);
            $organization->addMember($user, Role::Owner);
            $user->switchOrganization($organization);

            AuditLog::record('organization.created', $organization->id, $organization, ['by' => 'console'], 'system');

            return [$user, $organization];
        });

        $token = Password::broker()->createToken($user);
        $link = url(route('password.reset', ['token' => $token, 'email' => $user->email], false));

        $this->components->info("{$user->email} owns \"{$organization->name}\".");
        $this->line('Set the password here (valid for '.config('auth.passwords.users.expire', 60).' minutes):');
        $this->line($link);

        return self::SUCCESS;
    }
}

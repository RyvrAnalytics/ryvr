<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Tenancy\Registration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        if (! Registration::allows((string) $input['email'])) {
            throw ValidationException::withMessages([
                'email' => 'Sign-ups are closed on this instance. Ask an admin for an invitation.',
            ]);
        }

        return DB::transaction(function () use ($input) {
            $first = ! User::query()->exists();
            $invitation = Registration::pendingInvitation();

            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            // The first account on a fresh install administers the instance.
            if ($first) {
                $user->forceFill(['is_instance_admin' => true])->save();
            }

            // An invitation sent to this address proves it, so the address counts as verified.
            if ($invitation && strcasecmp($invitation->email, $user->email) === 0) {
                $user->forceFill(['email_verified_at' => now()])->save();
                Registration::accept($invitation, $user);
            }

            return $user;
        });
    }
}

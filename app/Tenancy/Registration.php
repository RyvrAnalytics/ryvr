<?php

namespace App\Tenancy;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\ProjectGrant;
use App\Models\User;

/**
 * Who may create an account: the very first user of an instance, anyone holding a
 * valid invitation, or everyone when RYVR_REGISTRATION=true.
 */
class Registration
{
    public const SESSION_KEY = 'ryvr.invitation';

    public static function open(): bool
    {
        return config('ryvr.registration') || ! User::query()->exists();
    }

    public static function pendingInvitation(): ?Invitation
    {
        $token = session(self::SESSION_KEY);

        return is_string($token) ? Invitation::findPending($token) : null;
    }

    public static function allows(string $email): bool
    {
        if (self::open()) {
            return true;
        }

        $invitation = self::pendingInvitation();

        return $invitation !== null && strcasecmp($invitation->email, $email) === 0;
    }

    /**
     * Add the user to the invited organization with the invited role and grants.
     */
    public static function accept(Invitation $invitation, User $user): void
    {
        $organization = $invitation->organization;
        $organization->addMember($user, $invitation->role, $invitation->project_access);

        if ($invitation->project_access === 'granted_only') {
            foreach ($invitation->project_ids ?? [] as $projectId) {
                if ($organization->projects()->whereKey($projectId)->exists()) {
                    ProjectGrant::create([
                        'organization_id' => $organization->id,
                        'user_id' => $user->id,
                        'project_id' => $projectId,
                        'role' => $invitation->role,
                    ]);
                }
            }
        }

        $invitation->forceFill(['accepted_at' => now()])->save();
        $user->switchOrganization($organization);
        session()->forget(self::SESSION_KEY);

        AuditLog::record('member.joined', $organization->id, $user, ['role' => $invitation->role->value], 'user', (string) $user->id);
    }
}

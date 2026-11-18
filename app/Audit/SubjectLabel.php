<?php

namespace App\Audit;

use App\Models\ApiKey;
use App\Models\EmbedKey;
use App\Models\Goal;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectGrant;
use App\Models\ProjectGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class SubjectLabel
{
    public static function for(Model $subject): ?string
    {
        return match (true) {
            $subject instanceof User => $subject->name.' ('.$subject->email.')',
            $subject instanceof Membership => $subject->user ? self::for($subject->user) : 'Deleted member',
            $subject instanceof ProjectGrant => ($user = User::whereHas('memberships', fn ($q) => $q->where('organization_id', $subject->organization_id))->find($subject->user_id)) ? self::for($user) : 'Deleted member',
            $subject instanceof Invitation => $subject->email,
            $subject instanceof Project, $subject instanceof ProjectGroup,
            $subject instanceof ApiKey, $subject instanceof EmbedKey,
            $subject instanceof Organization, $subject instanceof Goal => $subject->name,
            default => null,
        };
    }

    public static function type(string $type): string
    {
        return match ($type) {
            'Project' => 'Site',
            'Membership', 'User' => 'Member',
            'ApiKey' => 'API key',
            'EmbedKey' => 'Embed key',
            default => Str::headline($type),
        };
    }
}

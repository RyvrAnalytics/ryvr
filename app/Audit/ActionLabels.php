<?php

namespace App\Audit;

use Illuminate\Support\Str;

final class ActionLabels
{
    private const LABELS = [
        'auth.signed_in' => 'Signed in',
        'auth.signed_out' => 'Signed out',
        'auth.sign_in_failed' => 'Sign-in failed',
        'two_factor.enabled' => 'Two-factor authentication enabled',
        'two_factor.disabled' => 'Two-factor authentication disabled',
        'password.changed' => 'Password changed',
        'passkey.added' => 'Passkey added',
        'passkey.removed' => 'Passkey removed',
        'invitation.accepted' => 'Invitation accepted',
        'invitation.declined' => 'Invitation declined',
        'member.joined' => 'Member joined',
        'member.invited' => 'Member invited',
        'member.invitation_revoked' => 'Invitation revoked',
        'member.updated' => 'Member access updated',
        'member.left' => 'Member left',
        'member.removed' => 'Member removed',
        'owner.granted' => 'Owner role granted',
        'owner.revoked' => 'Owner role revoked',
        'project_grant.created' => 'Site or group access granted',
        'project_grant.updated' => 'Site or group access updated',
        'project_grant.deleted' => 'Site or group access removed',
        'project.created' => 'Site created',
        'project.updated' => 'Site settings updated',
        'project.groups_updated' => 'Site groups updated',
        'project.archived' => 'Site archived',
        'project.restored' => 'Site restored',
        'project.deleted' => 'Site deleted',
        'project.ingest_key_rotated' => 'Tracking key rotated',
        'project.exported' => 'Site data exported',
        'goal.created' => 'Goal created',
        'goal.updated' => 'Goal updated',
        'goal.deleted' => 'Goal deleted',
        'group.created' => 'Site group created',
        'group.deleted' => 'Site group deleted',
        'organization.created' => 'Organization created',
        'organization.updated' => 'Organization settings updated',
        'organization.deleted' => 'Organization deleted',
        'api_key.created' => 'API key created',
        'api_key.revoked' => 'API key revoked',
        'api.used' => 'API accessed',
        'mcp.used' => 'MCP accessed',
        'embed.key.created' => 'Embed key created',
        'embed.key.updated' => 'Embed key settings updated',
        'embed.token_rejected' => 'Embed token rejected',
        'embed.key.rotated' => 'Embed key rotated',
        'embed.key.revoked' => 'Embed key revoked',
        'embed.token_minted' => 'Embed token minted',
        'embed.token_revoked' => 'Embed token revoked',
        'embed.viewed' => 'Embed viewed',
        'exclusion.created' => 'Exclusion rule created',
        'exclusion.enabled' => 'Exclusion rule enabled',
        'exclusion.disabled' => 'Exclusion rule disabled',
        'exclusion.deleted' => 'Exclusion rule deleted',
        'retention.applied' => 'Data retention applied',
    ];

    public static function for(string $action): string
    {
        return self::LABELS[$action] ?? Str::ucfirst(str_replace(['.', '_'], ' ', $action));
    }
}

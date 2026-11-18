<?php

namespace App\Audit;

use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\EmbedKey;
use App\Models\Goal;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use Illuminate\Support\Collection;

final class LogPresenter
{
    /** @var array<int, User> */
    private array $users;

    /** @var array<string, string> */
    private array $keys;

    /** @var array<string, string> */
    private array $embedKeys;

    /** @var array<string, string> */
    private array $subjects = [];

    /** @param Collection<int, AuditLog> $logs */
    public function __construct(string $organizationId, Collection $logs)
    {
        $this->users = User::whereIn('id', $logs->where('actor_type', 'user')->pluck('actor_id')->filter())
            ->whereHas('memberships', fn ($q) => $q->where('organization_id', $organizationId))
            ->get(['id', 'name', 'email'])->keyBy('id')->all();
        $this->keys = ApiKey::where('organization_id', $organizationId)
            ->whereIn('id', $logs->whereIn('actor_type', ['api_key', 'mcp_client'])->pluck('actor_id')->filter())
            ->pluck('name', 'id')->all();
        $this->embedKeys = EmbedKey::where('organization_id', $organizationId)
            ->whereIn('id', $logs->where('actor_type', 'embed_key')->pluck('actor_id')->filter())
            ->pluck('name', 'id')->all();

        foreach ([Project::class, Membership::class, ApiKey::class, EmbedKey::class, Goal::class, ProjectGroup::class, Invitation::class] as $model) {
            $type = class_basename($model);
            $query = $model::where('organization_id', $organizationId)
                ->whereIn('id', $logs->where('subject_type', $type)->pluck('subject_id')->filter());
            if ($model === Membership::class) {
                $query->with('user');
            }
            foreach ($query->get() as $subject) {
                $label = SubjectLabel::for($subject);
                if ($label !== null) {
                    $this->subjects[$type.':'.$subject->getKey()] = $label;
                }
            }
        }
        foreach ($this->users as $user) {
            $this->subjects['User:'.$user->id] = SubjectLabel::for($user);
        }
        $organization = Organization::find($organizationId);
        if ($organization) {
            $this->subjects['Organization:'.$organizationId] = $organization->name;
        }
    }

    /** @return array{name: string, email: string|null, type: string} */
    public function actor(AuditLog $log): array
    {
        $meta = $log->metadata ?? [];
        $type = $log->actor_type === 'mcp_client' ? 'api_key' : $log->actor_type;
        $user = $this->users[$log->actor_id] ?? null;

        return [
            'name' => match ($type) {
                'user' => $meta['actor_name'] ?? $user->name ?? 'Deleted user',
                'api_key' => $meta['api_key_name'] ?? $this->keys[$log->actor_id] ?? 'Deleted API key',
                'embed_key' => $meta['embed_key_name'] ?? $this->embedKeys[$log->actor_id] ?? 'Deleted embed key',
                'console' => 'Console',
                default => 'System',
            },
            'email' => $type === 'user' ? ($meta['actor_email'] ?? $user?->email) : null,
            'type' => $type,
        ];
    }

    /** @return array<string, mixed> */
    public function entry(AuditLog $log, string $timezone): array
    {
        $metadata = $log->metadata ?? [];
        $subject = $metadata['subject_name'] ?? $this->subjects[$log->subject_type.':'.$log->subject_id] ?? $metadata['email'] ?? null;
        if ($subject === null && $log->subject_type !== null) {
            $subject = SubjectLabel::type($log->subject_type).($log->subject_id ? ' #'.$log->subject_id : '');
        }
        unset($metadata['actor_name'], $metadata['actor_email'], $metadata['api_key_name'], $metadata['embed_key_name'], $metadata['subject_name']);

        return [
            'id' => $log->id,
            'action' => $log->action,
            'label' => ActionLabels::for($log->action),
            'actor' => $this->actor($log),
            'subject' => $subject,
            'metadata' => $metadata ?: null,
            'ip' => $log->ip_address,
            'at' => $log->created_at->setTimezone($timezone)->toIso8601String(),
        ];
    }
}

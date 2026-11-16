<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Append-only record of security-relevant changes and API/MCP reads.
 *
 * @property int $id
 * @property string|null $organization_id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $metadata
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_address'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(string $action, ?string $organizationId = null, ?Model $subject = null, array $metadata = [], ?string $actorType = null, ?string $actorId = null): self
    {
        return self::create([
            'organization_id' => $organizationId,
            'actor_type' => $actorType ?? (Auth::check() ? 'user' : 'system'),
            'actor_id' => $actorId ?? (string) Auth::id() ?: null,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'metadata' => $metadata ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Audit log entries are append-only.');
        }

        return parent::save($options);
    }
}

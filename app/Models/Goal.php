<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A conversion: a page path (with * wildcards) or a custom event name.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $name
 * @property string $type "pageview" or "event"
 * @property string $value
 */
class Goal extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'project_id', 'name', 'type', 'value'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}

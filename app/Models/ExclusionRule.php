<?php

namespace App\Models;

use App\Ingestion\Exclusion\Field;
use App\Ingestion\Exclusion\Operator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * @property string $id
 * @property string $organization_id
 * @property string|null $project_id
 * @property Field $field
 * @property Operator $operator
 * @property string $value
 * @property string|null $note
 * @property bool $enabled
 */
class ExclusionRule extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'project_id', 'field', 'operator', 'value', 'note', 'enabled'];

    protected function casts(): array
    {
        return [
            'field' => Field::class,
            'operator' => Operator::class,
            'enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $flush = function (ExclusionRule $rule) {
            $ids = $rule->project_id
                ? [$rule->project_id]
                : Project::where('organization_id', $rule->organization_id)->pluck('id')->all();
            foreach ($ids as $id) {
                Cache::forget('ryvr:exclusions:'.$id);
            }
        };
        static::saved($flush);
        static::deleted($flush);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}

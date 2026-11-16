<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property string $currency
 * @property bool $mcp_enabled
 */
class Organization extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'slug', 'timezone', 'currency', 'mcp_enabled'];

    protected function casts(): array
    {
        return ['mcp_enabled' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->withPivot(['id', 'role', 'project_access'])
            ->withTimestamps();
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<ProjectGroup, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(ProjectGroup::class);
    }

    /** @return HasMany<ExclusionRule, $this> */
    public function exclusionRules(): HasMany
    {
        return $this->hasMany(ExclusionRule::class);
    }

    public function addMember(User $user, Role $role, string $projectAccess = 'all'): Membership
    {
        return $this->memberships()->updateOrCreate(
            ['user_id' => $user->id],
            ['role' => $role, 'project_access' => $projectAccess],
        );
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}

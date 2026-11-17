<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $domain
 * @property list<string>|null $allowed_hostnames
 * @property string $timezone
 * @property string $environment
 * @property string $ingest_key
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $archived_at
 * @property-read Organization $organization
 */
class Project extends Model
{
    use HasUuids;

    public const INGEST_PREFIX = 'ryvr_ingest_';

    protected $fillable = ['organization_id', 'name', 'domain', 'allowed_hostnames', 'timezone', 'environment', 'settings'];

    protected $hidden = ['ingest_key'];

    protected function casts(): array
    {
        return [
            'allowed_hostnames' => 'array',
            'settings' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            $project->ingest_key ??= self::newIngestKey();
        });

        // The collector caches projects by ingest key; drop the entry whenever a project changes.
        static::saved(fn (Project $project) => $project->forgetIngestCache());
        static::deleted(fn (Project $project) => $project->forgetIngestCache());
    }

    public static function newIngestKey(): string
    {
        return self::INGEST_PREFIX.Str::lower(Str::random(24));
    }

    public function rotateIngestKey(): void
    {
        $this->forgetIngestCache();
        $this->ingest_key = self::newIngestKey();
        $this->save();
    }

    public function archive(): void
    {
        $this->forceFill(['archived_at' => now()])->save();
    }

    public function restore(): void
    {
        $this->forceFill(['archived_at' => null])->save();
    }

    public function forgetIngestCache(): void
    {
        Cache::forget('ryvr:project-by-key:'.$this->getOriginal('ingest_key'));
        Cache::forget('ryvr:project-by-key:'.$this->ingest_key);
        Cache::forget('ryvr:exclusions:'.$this->id);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<ProjectGroup, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ProjectGroup::class, 'project_group_project');
    }

    /** @return HasMany<Goal, $this> */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    /** @return HasMany<ExclusionRule, $this> */
    public function exclusionRules(): HasMany
    {
        return $this->hasMany(ExclusionRule::class);
    }

    /**
     * @param  Builder<Project>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Hostnames that may send events for this project: the main domain, its www. twin and
     * any extra hostnames. A leading "*." allows every subdomain.
     *
     * @return list<string>
     */
    public function hostnames(): array
    {
        $domain = self::normalizeHost($this->domain);
        $hosts = [$domain, str_starts_with($domain, 'www.') ? substr($domain, 4) : 'www.'.$domain];

        foreach ($this->allowed_hostnames ?? [] as $host) {
            $hosts[] = self::normalizeHost($host);
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    public function acceptsHostname(string $hostname): bool
    {
        $hostname = self::normalizeHost($hostname);
        if ($hostname === '') {
            return false;
        }

        foreach ($this->hostnames() as $allowed) {
            if ($allowed === $hostname) {
                return true;
            }
            if (str_starts_with($allowed, '*.') && str_ends_with($hostname, substr($allowed, 1))) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeHost(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value) ?? $value;
        $value = explode('/', $value, 2)[0];

        return rtrim(explode(':', $value, 2)[0], '.');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }
}

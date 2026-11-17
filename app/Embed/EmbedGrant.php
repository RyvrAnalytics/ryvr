<?php

namespace App\Embed;

use App\Models\EmbedKey;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

/**
 * What one verified embed token may read: the token's claims intersected with its key's
 * ceilings and the organization's live projects. The embed equivalent of Access; embed
 * queries take project ids only from here, never from request data.
 */
final class EmbedGrant
{
    /**
     * @param  Collection<int, Project>  $projects  effective, active projects
     * @param  list<string>  $widgets
     * @param  list<string>  $origins  origin patterns the token may be shown on
     * @param  list<string>  $periods  periods the viewer may pick
     * @param  array<string, string>  $lockedFilters  always applied; the viewer cannot change them
     * @param  list<string>  $adjustable  dimensions the viewer may filter on
     */
    public function __construct(
        public readonly EmbedKey $key,
        public readonly Collection $projects,
        public readonly array $widgets,
        public readonly array $origins,
        public readonly string $defaultPeriod,
        public readonly array $periods,
        public readonly array $lockedFilters,
        public readonly array $adjustable,
        public readonly ?string $timezone,
        public readonly string $theme,
        public readonly string $jti,
        public readonly ?string $sub,
        public readonly int $expiresAt,
    ) {}

    public function organization(): Organization
    {
        return $this->key->organization;
    }

    /**
     * @return list<string>
     */
    public function projectIds(): array
    {
        return array_values($this->projects->map(fn (Project $p) => (string) $p->id)->all());
    }

    public function findProject(string $id): ?Project
    {
        return $this->projects->first(fn (Project $p) => $p->id === $id);
    }

    public function allowsWidget(string $widget): bool
    {
        return in_array($widget, $this->widgets, true);
    }

    public function allowsOrigin(string $origin): bool
    {
        return Origins::matchesAny($origin, $this->origins);
    }
}

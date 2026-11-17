<?php

namespace App\Ingestion\Exclusion;

use App\Models\ExclusionRule;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;

/**
 * Decides whether a hit is counted. Returns a drop reason, or null to keep the hit.
 *
 * Order matters: built-in automation checks first (cheap, most volume), then the
 * organization's and project's own rules.
 */
class TrafficFilter
{
    public const REASON_BOT = 'bot';

    public const REASON_AI_AGENT = 'ai_agent';

    public const REASON_MONITOR = 'monitor';

    public const REASON_AUTOMATION = 'automation';

    public const REASON_RULE = 'rule';

    public const REASON_HOSTNAME = 'hostname';

    public const REASON_SPAM = 'referrer_spam';

    public function __construct(private readonly BotDetector $bots) {}

    /**
     * @param  array{ip: string, user_agent: string, path: string, hostname: string, referrer: string, country: string, webdriver: bool}  $hit
     */
    public function reason(Project $project, array $hit): ?string
    {
        if ($hit['webdriver']) {
            return self::REASON_AUTOMATION;
        }

        if ($reason = $this->bots->classify($hit['user_agent'])) {
            return $reason;
        }

        if ($hit['referrer'] !== '' && $this->bots->isSpamReferrer($hit['referrer'])) {
            return self::REASON_SPAM;
        }

        foreach ($this->rulesFor($project) as $rule) {
            $subject = $hit[$rule['field']] ?? '';
            if (Operator::from($rule['operator'])->matches(is_string($subject) ? $subject : '', $rule['value'])) {
                return self::REASON_RULE;
            }
        }

        return null;
    }

    /**
     * Enabled rules for the project plus organization-wide rules, cached briefly.
     *
     * @return list<array{field: string, operator: string, value: string}>
     */
    public function rulesFor(Project $project): array
    {
        return Cache::remember('ryvr:exclusions:'.$project->id, 300, function () use ($project) {
            return array_values(ExclusionRule::query()
                ->where('organization_id', $project->organization_id)
                ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $project->id))
                ->where('enabled', true)
                ->get(['field', 'operator', 'value'])
                ->map(fn (ExclusionRule $r) => [
                    'field' => $r->field->value,
                    'operator' => $r->operator->value,
                    'value' => $r->value,
                ])
                ->all());
        });
    }
}

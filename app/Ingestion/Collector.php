<?php

namespace App\Ingestion;

use App\Ingestion\Enrichment\Geo;
use App\Ingestion\Enrichment\Salts;
use App\Ingestion\Enrichment\Sessions;
use App\Ingestion\Enrichment\Source;
use App\Ingestion\Enrichment\UserAgent;
use App\Ingestion\Exclusion\TrafficFilter;
use App\Ingestion\Queue\IngestQueue;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Turns one tracker hit into an event-store row, or a counted drop.
 */
class Collector
{
    public const ACCEPTED = 'accepted';

    public function __construct(
        private readonly TrafficFilter $filter,
        private readonly Salts $salts,
        private readonly Sessions $sessions,
        private readonly UserAgent $userAgents,
        private readonly Geo $geo,
        private readonly Source $sources,
        private readonly IngestQueue $queue,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return string "accepted", a drop reason, or "invalid"
     */
    public function collect(array $payload, Request $request): string
    {
        $project = $this->project((string) ($payload['k'] ?? ''));
        if (! $project) {
            return 'invalid';
        }

        $caps = config('ryvr.ingest');
        $url = mb_substr((string) ($payload['u'] ?? ''), 0, $caps['max_url_length']);
        $parts = parse_url($url);
        if (! $parts || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return 'invalid';
        }

        $hostname = Project::normalizeHost($parts['host']);
        $path = $this->path($parts, (bool) $project->setting('hash_routing', false));
        parse_str($parts['query'] ?? '', $query);

        $ip = $this->clientIp($request);
        $userAgent = mb_substr((string) $request->userAgent(), 0, 512);
        $referrer = mb_substr((string) ($payload['r'] ?? ''), 0, $caps['max_url_length']);
        $source = $this->sources->resolve($referrer, array_map(fn ($v) => is_string($v) ? $v : '', $query), $project->hostnames());
        $geo = $this->geo->locate($ip, $request);

        if (! $project->acceptsHostname($hostname)) {
            $this->queue->drop($project->id, TrafficFilter::REASON_HOSTNAME);

            return TrafficFilter::REASON_HOSTNAME;
        }

        $reason = $this->filter->reason($project, [
            'ip' => $ip,
            'user_agent' => $userAgent,
            'path' => $path,
            'hostname' => $hostname,
            'referrer' => $source['referrer_domain'],
            'country' => $geo['country'],
            'webdriver' => ! empty($payload['wd']),
        ]);

        if ($reason !== null) {
            $this->queue->drop($project->id, $reason);

            return $reason;
        }

        $now = now()->utc();
        $width = max(0, min(65535, (int) ($payload['w'] ?? 0)));
        $visitorId = $this->salts->visitorId($project->id, $ip, $userAgent, $now);
        $name = $this->eventName($payload['n'] ?? 'pageview');
        [$sessionId, $source] = $this->sessions->resolve($project->id, $visitorId, $source);

        $event = [
            'project_id' => $project->id,
            'organization_id' => $project->organization_id,
            'ts' => $now->format('Y-m-d H:i:s.v'),
            'event_id' => (string) Str::uuid7(),
            'name' => $name,
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'hostname' => $hostname,
            'pathname' => $path,
            'screen_width' => $width,
            'language' => $this->language($request),
            'props' => $this->props($payload['p'] ?? []),
            'engagement_ms' => $name === 'engagement' ? max(0, min(3_600_000, (int) ($payload['e'] ?? 0))) : 0,
            'revenue' => 0,
            'currency' => '',
            'consent' => in_array($payload['c'] ?? null, ['granted', 'denied', 'minimal'], true) ? $payload['c'] : 'none',
        ] + $source + $geo + $this->userAgents->parse($userAgent, $width);

        if (isset($payload['$']) && is_numeric($payload['$'])) {
            $event['revenue'] = round(max(0, min(1e12, (float) $payload['$'])), 4);
            $event['currency'] = preg_match('/^[A-Z]{3}$/', strtoupper((string) ($payload['cur'] ?? ''))) ? strtoupper((string) $payload['cur']) : '';
        }

        $this->queue->push($event);

        return self::ACCEPTED;
    }

    public function project(string $key): ?Project
    {
        if (! str_starts_with($key, Project::INGEST_PREFIX) || strlen($key) > 64) {
            return null;
        }

        // Cache raw attributes, not the model: the cache refuses to unserialize objects.
        $attributes = Cache::remember('ryvr:project-by-key:'.$key, 300, function () use ($key) {
            return Project::where('ingest_key', $key)->whereNull('archived_at')->first()?->getAttributes() ?? false;
        });

        return is_array($attributes) ? (new Project)->newFromBuilder($attributes) : null;
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function path(array $parts, bool $hashRouting): string
    {
        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = rawurldecode($path);
        if ($hashRouting && ! empty($parts['fragment']) && str_starts_with($parts['fragment'], '/')) {
            $path = rtrim($path, '/').$parts['fragment'];
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        return mb_substr($path, 0, 1024);
    }

    private function eventName(mixed $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $name) ?? '');

        return $name === '' ? 'pageview' : mb_substr($name, 0, config('ryvr.ingest.max_event_name_length'));
    }

    /**
     * @return array<string, string>
     */
    private function props(mixed $props): array
    {
        if (! is_array($props)) {
            return [];
        }

        $caps = config('ryvr.ingest');
        $clean = [];
        foreach ($props as $key => $value) {
            if (count($clean) >= $caps['max_props']) {
                break;
            }
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $key = mb_substr(trim((string) $key), 0, $caps['max_prop_key_length']);
            if ($key !== '') {
                $clean[$key] = mb_substr(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, 0, $caps['max_prop_value_length']);
            }
        }

        return $clean;
    }

    private function language(Request $request): string
    {
        $lang = strtolower(substr((string) $request->header('Accept-Language', ''), 0, 5));

        return preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $lang) ? $lang : '';
    }

    private function clientIp(Request $request): string
    {
        $header = config('ryvr.ingest.client_ip_header');
        if ($header && ($value = $request->header($header))) {
            $ip = trim(explode(',', $value)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return (string) $request->ip();
    }
}

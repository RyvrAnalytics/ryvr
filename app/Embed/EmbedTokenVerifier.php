<?php

namespace App\Embed;

use App\Analytics\Query;
use App\Models\EmbedKey;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Verifies embed tokens: compact HS256 JWTs signed by the customer's server with an embed key.
 *
 * Deliberately small and strict. The header must be exactly {alg: HS256, typ: JWT, kid}; any
 * other algorithm (including "none") is rejected before a key is even loaded. Signatures are
 * compared in constant time. Claims are checked against the key's ceilings and the
 * organization's live projects, and the result is an EmbedGrant.
 */
final class EmbedTokenVerifier
{
    public const AUDIENCE = 'ryvr:embed';

    /** Periods an embed viewer may use, with their longest span in days. */
    public const PERIODS = [
        'today' => 1,
        'yesterday' => 1,
        '24h' => 2,
        '7d' => 7,
        '30d' => 30,
        'month' => 31,
        'last_month' => 31,
        '90d' => 90,
        '12mo' => 366,
        'year' => 366,
    ];

    public const THEMES = ['auto', 'light', 'dark'];

    private const CLAIM_KEYS = ['sites', 'widgets', 'origins', 'period', 'filters', 'timezone', 'theme'];

    /**
     * Dimensions embeds can filter on. City is left out on purpose: embeds show country and
     * region at most.
     *
     * @return list<string>
     */
    public static function dimensions(): array
    {
        return array_values(array_diff(array_keys(Query::DIMENSIONS), ['city']));
    }

    public static function revocationKey(string $organizationId, string $jti): string
    {
        return 'embed:revoked:'.$organizationId.':'.hash('sha256', $jti);
    }

    /**
     * @param  string|null  $expectedKid  the key id from the URL; the token must name the same key
     *
     * @throws EmbedException
     */
    public function verify(string $token, ?string $expectedKid = null): EmbedGrant
    {
        if ($token === '') {
            throw EmbedException::unauthorized('token_missing', 'An embed token is required. Send it as "Authorization: Bearer <token>".');
        }
        if (strlen($token) > (int) config('ryvr.embed.max_token_bytes', 4096)) {
            throw EmbedException::unauthorized('token_too_large', 'The embed token is too large.');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3 || ! self::isSegment($parts[0]) || ! self::isSegment($parts[1]) || ! self::isSegment($parts[2])) {
            throw EmbedException::unauthorized('token_malformed', 'The embed token is malformed.');
        }

        $header = self::decodeJson($parts[0]);
        if ($header === null || ! self::validHeader($header)) {
            throw EmbedException::unauthorized('invalid_header', 'The embed token header must be exactly {"alg":"HS256","typ":"JWT","kid":"ek_..."}.');
        }

        /** @var string $kid */
        $kid = $header['kid'];
        if ($expectedKid !== null && ! hash_equals($expectedKid, $kid)) {
            throw EmbedException::unauthorized('key_mismatch', 'The embed token was signed with a different key.');
        }

        $key = EmbedKey::findActive($kid);
        if (! $key) {
            throw EmbedException::unauthorized('key_not_found', 'The embed key does not exist or was revoked.');
        }

        $expected = hash_hmac('sha256', $parts[0].'.'.$parts[1], $key->secret, true);
        $given = self::b64decode($parts[2]);
        if ($given === null || ! hash_equals($expected, $given)) {
            throw EmbedException::unauthorized('signature_invalid', 'The embed token signature is not valid.');
        }

        $claims = self::decodeJson($parts[1]);
        if ($claims === null) {
            throw EmbedException::unauthorized('invalid_claims', 'The embed token payload is not a JSON object.');
        }

        $this->checkRegisteredClaims($claims, $key);

        return $this->grant($claims, $key);
    }

    /**
     * @param  array<array-key, mixed>  $claims
     */
    private function checkRegisteredClaims(array $claims, EmbedKey $key): void
    {
        $now = time();
        $skew = (int) config('ryvr.embed.clock_skew', 60);

        if (($claims['iss'] ?? null) !== $key->public_id) {
            throw EmbedException::unauthorized('invalid_claims', 'The "iss" claim must equal the key id.');
        }
        if (($claims['aud'] ?? null) !== self::AUDIENCE) {
            throw EmbedException::unauthorized('invalid_claims', 'The "aud" claim must be "'.self::AUDIENCE.'".');
        }

        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        if (! is_int($iat) || ! is_int($exp)) {
            throw EmbedException::unauthorized('invalid_claims', 'The "iat" and "exp" claims are required (seconds since the epoch).');
        }
        if ($exp <= $now - $skew) {
            throw EmbedException::unauthorized('token_expired', 'The embed token has expired.');
        }
        if ($iat > $now + $skew) {
            throw EmbedException::unauthorized('token_not_yet_valid', 'The embed token was issued in the future. Check your server clock.');
        }
        if (array_key_exists('nbf', $claims) && (! is_int($claims['nbf']) || $claims['nbf'] > $now + $skew)) {
            throw EmbedException::unauthorized('token_not_yet_valid', 'The embed token is not valid yet.');
        }
        if ($exp <= $iat) {
            throw EmbedException::unauthorized('invalid_claims', 'The "exp" claim must be after "iat".');
        }
        if ($exp - $iat > $key->ttlCap()) {
            throw EmbedException::unauthorized('token_lifetime_too_long', "The embed token lives longer than this key allows ({$key->ttlCap()} seconds).");
        }

        $jti = $claims['jti'] ?? null;
        if (! is_string($jti) || ! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $jti)) {
            throw EmbedException::unauthorized('invalid_claims', 'The "jti" claim is required: a unique id of 8 to 128 characters.');
        }
        if (array_key_exists('sub', $claims) && (! is_string($claims['sub']) || mb_strlen($claims['sub']) > 128)) {
            throw EmbedException::unauthorized('invalid_claims', 'The "sub" claim must be a string of at most 128 characters.');
        }
        if (Cache::has(self::revocationKey($key->organization_id, $jti))) {
            throw EmbedException::unauthorized('token_revoked', 'This embed token was revoked.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $claims
     */
    private function grant(array $claims, EmbedKey $key): EmbedGrant
    {
        $ryvr = $claims['ryvr'] ?? null;
        if (! is_array($ryvr) || ($ryvr !== [] && array_is_list($ryvr))) {
            throw EmbedException::unauthorized('invalid_claims', 'The "ryvr" claim is required.');
        }
        $unknown = array_diff(array_map('strval', array_keys($ryvr)), self::CLAIM_KEYS);
        if ($unknown !== []) {
            throw EmbedException::unauthorized('invalid_claims', 'Unknown claim ryvr.'.reset($unknown).'. Allowed: '.implode(', ', self::CLAIM_KEYS).'.');
        }

        $projects = $this->sites($ryvr['sites'] ?? null, $key);
        $widgets = $this->widgets($ryvr['widgets'] ?? null, $key);
        $origins = $this->origins($ryvr['origins'] ?? null, $key);
        [$default, $periods] = $this->periods($ryvr['period'] ?? null, $key);
        [$locked, $adjustable] = $this->filters($ryvr['filters'] ?? null);

        $timezone = $ryvr['timezone'] ?? null;
        if ($timezone !== null && (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true))) {
            throw EmbedException::unauthorized('invalid_claims', 'ryvr.timezone must be an IANA timezone such as Europe/London.');
        }

        $theme = $ryvr['theme'] ?? 'auto';
        if (! is_string($theme) || ! in_array($theme, self::THEMES, true)) {
            throw EmbedException::unauthorized('invalid_claims', 'ryvr.theme must be one of: '.implode(', ', self::THEMES).'.');
        }

        /** @var string $jti */
        $jti = $claims['jti'];
        /** @var int $exp */
        $exp = $claims['exp'];
        $sub = isset($claims['sub']) && is_string($claims['sub']) ? $claims['sub'] : null;

        return new EmbedGrant($key, $projects, $widgets, $origins, $default, $periods, $locked, $adjustable, $timezone, $theme, $jti, $sub, $exp);
    }

    /**
     * Claimed sites ∩ key ceiling ∩ live projects of the key's organization.
     *
     * @return Collection<int, Project>
     */
    private function sites(mixed $sites, EmbedKey $key): Collection
    {
        if (! self::isStringList($sites) || $sites === [] || count($sites) > (int) config('ryvr.embed.max_sites', 50)) {
            throw EmbedException::unauthorized('invalid_claims', 'ryvr.sites must list between 1 and '.config('ryvr.embed.max_sites', 50).' site ids.');
        }
        foreach ($sites as $site) {
            if (! Str::isUuid($site)) {
                throw EmbedException::unauthorized('invalid_claims', 'ryvr.sites must contain site ids.');
            }
            if ($key->project_ids !== null && ! in_array($site, $key->project_ids, true)) {
                throw EmbedException::forbidden('site_not_allowed', 'The token names a site this embed key may not show.');
            }
        }

        // Archived, deleted or foreign projects drop out here; tokens never widen access.
        $projects = Project::active()
            ->where('organization_id', $key->organization_id)
            ->whereIn('id', array_values(array_unique($sites)))
            ->orderBy('name')
            ->get();

        if ($projects->isEmpty()) {
            throw EmbedException::forbidden('no_sites', 'None of the sites in this token can be shown.');
        }

        return $projects;
    }

    /**
     * @return list<string>
     */
    private function widgets(mixed $widgets, EmbedKey $key): array
    {
        if (! self::isStringList($widgets) || $widgets === []) {
            throw EmbedException::unauthorized('invalid_claims', 'ryvr.widgets must list at least one of: '.implode(', ', EmbedKey::WIDGETS).'.');
        }
        foreach ($widgets as $widget) {
            if (! in_array($widget, EmbedKey::WIDGETS, true)) {
                throw EmbedException::unauthorized('invalid_claims', "Unknown widget [{$widget}].");
            }
            if (! in_array($widget, $key->widgets, true)) {
                throw EmbedException::forbidden('widget_not_allowed', "This embed key does not allow the {$widget} widget.");
            }
        }

        return array_values(array_unique($widgets));
    }

    /**
     * @return list<string>
     */
    private function origins(mixed $origins, EmbedKey $key): array
    {
        if (! self::isStringList($origins) || $origins === [] || count($origins) > 20) {
            throw EmbedException::unauthorized('invalid_claims', 'ryvr.origins must list between 1 and 20 origins, such as https://app.example.com.');
        }

        $normalized = [];
        foreach ($origins as $origin) {
            $value = Origins::normalize($origin);
            if ($value === null || $value !== $origin) {
                throw EmbedException::unauthorized('invalid_claims', 'ryvr.origins must contain origins like https://app.example.com (no paths, no trailing slash).');
            }
            if (! Origins::covers($key->allowed_origins, $value)) {
                throw EmbedException::forbidden('origin_not_allowed', 'The token names an origin this embed key does not allow.');
            }
            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function periods(mixed $period, EmbedKey $key): array
    {
        $fits = fn (string $p) => self::PERIODS[$p] <= $key->max_period_days;

        if ($period === null) {
            $allowed = array_values(array_filter(['7d', '30d', '90d'], $fits)) ?: ['today'];

            return [in_array('30d', $allowed, true) ? '30d' : $allowed[0], $allowed];
        }

        if (! is_array($period) || ($period !== [] && array_is_list($period)) || array_diff(array_map('strval', array_keys($period)), ['default', 'allowed']) !== []) {
            throw EmbedException::unauthorized('invalid_claims', 'ryvr.period must be an object with "default" and "allowed".');
        }

        $allowed = $period['allowed'] ?? null;
        if (! self::isStringList($allowed) || $allowed === []) {
            throw EmbedException::unauthorized('invalid_claims', 'ryvr.period.allowed must list at least one period.');
        }
        foreach ($allowed as $p) {
            if (! isset(self::PERIODS[$p])) {
                throw EmbedException::unauthorized('invalid_claims', "Unknown period [{$p}]. Use: ".implode(', ', array_keys(self::PERIODS)).'.');
            }
            if (! $fits($p)) {
                throw EmbedException::forbidden('period_not_allowed', "The period {$p} is longer than this embed key allows.");
            }
        }
        $allowed = array_values(array_unique($allowed));

        $default = $period['default'] ?? (in_array('30d', $allowed, true) ? '30d' : $allowed[0]);
        if (! is_string($default) || ! in_array($default, $allowed, true)) {
            throw EmbedException::unauthorized('invalid_claims', 'ryvr.period.default must be one of ryvr.period.allowed.');
        }

        return [$default, $allowed];
    }

    /**
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function filters(mixed $filters): array
    {
        if ($filters === null) {
            return [[], []];
        }
        if (! is_array($filters) || ($filters !== [] && array_is_list($filters)) || array_diff(array_map('strval', array_keys($filters)), ['locked', 'adjustable']) !== []) {
            throw EmbedException::unauthorized('invalid_claims', 'ryvr.filters must be an object with "locked" and "adjustable".');
        }

        $dimensions = self::dimensions();
        $locked = [];
        $lockedClaim = $filters['locked'] ?? [];
        if (! is_array($lockedClaim) || ($lockedClaim !== [] && array_is_list($lockedClaim))) {
            throw EmbedException::unauthorized('invalid_claims', 'ryvr.filters.locked must be an object of dimension: value.');
        }
        foreach ($lockedClaim as $dimension => $value) {
            if (! in_array((string) $dimension, $dimensions, true)) {
                throw EmbedException::forbidden('filter_not_allowed', "Embeds cannot filter on [{$dimension}].");
            }
            if (! is_string($value) || $value === '' || mb_strlen($value) > 500) {
                throw EmbedException::unauthorized('invalid_claims', 'Locked filter values must be non-empty strings.');
            }
            $locked[(string) $dimension] = $value;
        }

        $adjustable = $filters['adjustable'] ?? [];
        if (! self::isStringList($adjustable)) {
            throw EmbedException::unauthorized('invalid_claims', 'ryvr.filters.adjustable must be a list of dimensions.');
        }
        foreach ($adjustable as $dimension) {
            if (! in_array($dimension, $dimensions, true)) {
                throw EmbedException::forbidden('filter_not_allowed', "Embeds cannot filter on [{$dimension}].");
            }
            if (isset($locked[$dimension])) {
                throw EmbedException::unauthorized('invalid_claims', "The dimension [{$dimension}] cannot be both locked and adjustable.");
            }
        }

        return [$locked, array_values(array_unique($adjustable))];
    }

    /**
     * @param  array<array-key, mixed>  $header
     */
    private static function validHeader(array $header): bool
    {
        $keys = array_keys($header);
        sort($keys);

        return $keys === ['alg', 'kid', 'typ']
            && $header['alg'] === 'HS256'
            && $header['typ'] === 'JWT'
            && is_string($header['kid'])
            && EmbedKey::isPublicId($header['kid']);
    }

    /**
     * @phpstan-assert-if-true list<string> $value
     */
    private static function isStringList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (! is_string($item)) {
                return false;
            }
        }

        return true;
    }

    private static function isSegment(string $segment): bool
    {
        return $segment !== '' && (bool) preg_match('/^[A-Za-z0-9_-]+$/', $segment);
    }

    private static function b64decode(string $segment): ?string
    {
        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decodeJson(string $segment): ?array
    {
        $raw = self::b64decode($segment);
        if ($raw === null) {
            return null;
        }

        try {
            $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return null;
        }

        return is_array($value) && ($value === [] || ! array_is_list($value)) ? $value : null;
    }
}

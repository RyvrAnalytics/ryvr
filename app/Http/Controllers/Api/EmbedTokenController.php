<?php

namespace App\Http\Controllers\Api;

use App\Embed\EmbedException;
use App\Embed\EmbedTokenSigner;
use App\Embed\EmbedTokenVerifier;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EmbedKey;
use App\Tenancy\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Server-to-server token minting for customers without a JWT library. Authenticated with an
 * API key that has the embed:mint scope. RYVR applies the same ceilings at mint time as at
 * verification time (the minted token is run through the verifier before it is returned).
 *
 * Never call this from a browser: requests carrying an Origin header are refused.
 */
class EmbedTokenController extends Controller
{
    public function __construct(
        private readonly EmbedTokenSigner $signer,
        private readonly EmbedTokenVerifier $verifier,
    ) {}

    public function store(Request $request, Access $access): JsonResponse
    {
        if ($request->headers->has('Origin')) {
            return $this->error('browser_not_allowed', 'Mint embed tokens on your server. This endpoint does not accept browser requests.', 400);
        }

        $data = $request->validate([
            'key_id' => ['required', 'string', 'max:40'],
            'ttl' => ['nullable', 'integer', 'min:30', 'max:'.(int) config('ryvr.embed.max_ttl_hard_cap', 3600)],
            'sub' => ['nullable', 'string', 'max:128'],
            'sites' => ['nullable', 'array', 'max:'.(int) config('ryvr.embed.max_sites', 50)],
            'sites.*' => ['uuid'],
            'widgets' => ['nullable', 'array'],
            'widgets.*' => ['string'],
            'origins' => ['nullable', 'array', 'max:20'],
            'origins.*' => ['string', 'max:255'],
            'period' => ['nullable', 'array'],
            'filters' => ['nullable', 'array'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'theme' => ['nullable', 'string'],
        ]);

        $key = EmbedKey::active()
            ->where('organization_id', $access->organization->id)
            ->where('public_id', $data['key_id'])
            ->first();

        if (! $key) {
            return $this->error('key_not_found', 'Embed key not found in this organization.', 404);
        }

        // An API key never mints access to sites its own principal cannot read.
        $readable = $access->projectIds();
        $sites = $data['sites'] ?? ($key->project_ids ?? $readable);
        if (array_diff($sites, $readable) !== []) {
            return $this->error('site_not_allowed', 'The API key cannot read one of these sites.', 403);
        }

        $ttl = (int) ($data['ttl'] ?? min((int) config('ryvr.embed.default_ttl', 600), $key->ttlCap()));
        if ($ttl > $key->ttlCap()) {
            return $this->error('token_lifetime_too_long', "This embed key allows tokens of at most {$key->ttlCap()} seconds.", 422);
        }

        $now = time();
        $jti = (string) Str::uuid();
        $ryvr = array_filter([
            'sites' => array_values($sites),
            'widgets' => $data['widgets'] ?? $key->widgets,
            'origins' => $data['origins'] ?? $key->allowed_origins,
            'period' => $data['period'] ?? null,
            'filters' => isset($data['filters']) ? $this->filters($data['filters']) : null,
            'timezone' => $data['timezone'] ?? null,
            'theme' => $data['theme'] ?? null,
        ], fn ($v) => $v !== null);

        $claims = array_filter([
            'iss' => $key->public_id,
            'aud' => EmbedTokenVerifier::AUDIENCE,
            'sub' => $data['sub'] ?? null,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => $jti,
            'ryvr' => $ryvr,
        ], fn ($v) => $v !== null);

        $token = $this->signer->sign($key, $claims);

        try {
            $this->verifier->verify($token, $key->public_id);
        } catch (EmbedException $e) {
            return $this->error($e->reason, $e->getMessage(), $e->status === 403 ? 403 : 422);
        }

        AuditLog::record('embed.token_minted', $access->organization->id, $key, ['jti' => $jti, 'sub' => $data['sub'] ?? null, 'ttl' => $ttl], 'api_key', $access->apiKey()?->id);

        return response()->json([
            'token' => $token,
            'key_id' => $key->public_id,
            'jti' => $jti,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + $ttl),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Revoke one token by its jti until it would have expired anyway.
     */
    public function revoke(Access $access, string $jti): JsonResponse
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $jti)) {
            return $this->error('invalid_parameter', 'That is not a token id.', 400);
        }

        $ttl = (int) config('ryvr.embed.max_ttl_hard_cap', 3600) + (int) config('ryvr.embed.clock_skew', 60) + 60;
        cache()->put(EmbedTokenVerifier::revocationKey($access->organization->id, $jti), true, $ttl);

        AuditLog::record('embed.token_revoked', $access->organization->id, null, ['jti' => $jti], 'api_key', $access->apiKey()?->id);

        return response()->json(['revoked' => true, 'jti' => $jti]);
    }

    /**
     * Keep empty locked filters as a JSON object rather than a list.
     *
     * @param  array<array-key, mixed>  $filters
     * @return array<array-key, mixed>
     */
    private function filters(array $filters): array
    {
        if (isset($filters['locked']) && $filters['locked'] === []) {
            unset($filters['locked']);
        }

        return $filters;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}

<?php

namespace Tests\Support;

use App\Embed\EmbedTokenSigner;
use App\Models\EmbedKey;
use App\Models\Organization;
use Illuminate\Support\Str;

/**
 * Builds embed keys and tokens for tests.
 */
final class EmbedTokens
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: EmbedKey, 1: string}
     */
    public static function key(Organization $organization, array $attributes = []): array
    {
        return EmbedKey::issue($attributes + [
            'organization_id' => $organization->id,
            'name' => 'Customer app',
            'allowed_origins' => ['https://app.example.com', 'https://*.customer.test'],
            'project_ids' => null,
            'widgets' => EmbedKey::WIDGETS,
            'max_ttl_seconds' => 600,
        ]);
    }

    /**
     * @param  list<string>  $sites
     * @param  array<string, mixed>  $ryvr  merged over the defaults
     * @param  array<string, mixed>  $claims  merged over the registered claims
     * @return array<string, mixed>
     */
    public static function claims(EmbedKey $key, array $sites, array $ryvr = [], array $claims = []): array
    {
        $now = time();

        return array_merge([
            'iss' => $key->public_id,
            'aud' => 'ryvr:embed',
            'sub' => 'viewer-1',
            'iat' => $now,
            'exp' => $now + 300,
            'jti' => (string) Str::uuid(),
            'ryvr' => array_merge([
                'sites' => $sites,
                'widgets' => EmbedKey::WIDGETS,
                'origins' => ['https://app.example.com'],
            ], $ryvr),
        ], $claims);
    }

    /**
     * @param  list<string>  $sites
     * @param  array<string, mixed>  $ryvr
     * @param  array<string, mixed>  $claims
     */
    public static function token(EmbedKey $key, array $sites, array $ryvr = [], array $claims = []): string
    {
        return (new EmbedTokenSigner)->sign($key, self::claims($key, $sites, $ryvr, $claims));
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     */
    public static function raw(array $header, array $claims, string $secret): string
    {
        return EmbedTokenSigner::encode($header, $claims, $secret);
    }
}

<?php

namespace App\Embed;

use App\Models\EmbedKey;

/**
 * Signs embed tokens (HS256 JWT) with a key's secret. Used by the mint endpoint, for
 * customers without a JWT library, and by tests.
 */
final class EmbedTokenSigner
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function sign(EmbedKey $key, array $claims): string
    {
        return self::encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $key->public_id], $claims, $key->secret);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     */
    public static function encode(array $header, array $claims, string $secret): string
    {
        $input = self::b64(self::json($header)).'.'.self::b64(self::json($claims));

        return $input.'.'.self::b64(hash_hmac('sha256', $input, $secret, true));
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

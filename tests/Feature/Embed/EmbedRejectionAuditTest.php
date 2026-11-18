<?php

use App\Embed\EmbedTokenVerifier;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Tests\Support\EmbedTokens;

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Example', 'slug' => 'example']);
    $this->site = Project::create(['organization_id' => $this->org->id, 'name' => 'Example', 'domain' => 'example.test']);
    [$this->key] = EmbedTokens::key($this->org);
});

it('audits rejected tokens for known keys without storing tokens or viewer addresses', function (string $case, string $reason) {
    $token = match ($case) {
        'signature' => EmbedTokens::raw(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $this->key->public_id], EmbedTokens::claims($this->key, [$this->site->id]), 'wrong-secret'),
        'expired' => EmbedTokens::token($this->key, [$this->site->id], [], ['iat' => time() - 600, 'exp' => time() - 300]),
        default => EmbedTokens::token($this->key, [$this->site->id], [], ['jti' => 'rejected-token-id']),
    };
    if ($case === 'revoked key') {
        $this->key->forceFill(['revoked_at' => now()])->save();
    }
    if ($case === 'revoked token') {
        Cache::put(EmbedTokenVerifier::revocationKey($this->org->id, 'rejected-token-id'), true, 600);
    }
    $origin = $case === 'origin' ? 'https://other.example.com' : 'https://app.example.com';

    $this->withToken($token)->getJson("/embed/v1/{$this->key->public_id}/meta", ['Origin' => $origin])
        ->assertStatus($case === 'origin' ? 403 : 401)->assertJsonPath('error.code', $reason);

    $log = AuditLog::where('action', 'embed.token_rejected')->sole();
    expect($log->organization_id)->toBe($this->org->id)
        ->and($log->subject_type)->toBe('EmbedKey')
        ->and($log->subject_id)->toBe($this->key->id)
        ->and($log->actor_type)->toBe('embed_key')
        ->and($log->actor_id)->toBe($this->key->id)
        ->and($log->metadata['reason'])->toBe($reason)
        ->and($log->metadata['origin'])->toBe($origin)
        ->and($log->metadata['kid'])->toBe($this->key->public_id)
        ->and($log->ip_address)->toBeNull()
        ->and(json_encode($log->metadata))->not->toContain($token)->not->toContain($this->key->secret);
})->with([
    ['signature', 'signature_invalid'],
    ['expired', 'token_expired'],
    ['origin', 'origin_not_allowed'],
    ['revoked key', 'key_not_found'],
    ['revoked token', 'token_revoked'],
]);

it('does not attribute an unknown key to an organization', function () {
    $unknown = 'ek_'.str_repeat('z', 20);
    $token = EmbedTokens::raw(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $unknown], [], 'unknown-secret');

    $this->withToken($token)->getJson("/embed/v1/{$unknown}/meta")
        ->assertUnauthorized()->assertJsonPath('error.code', 'key_not_found');

    expect(AuditLog::where('action', 'embed.token_rejected')->count())->toBe(0);
});

it('limits rejection audits per key and reason for a minute across origins', function () {
    [$second] = EmbedTokens::key($this->org);
    foreach (['https://app.example.com', 'https://other.example.com'] as $origin) {
        $this->withToken('bad')->getJson("/embed/v1/{$this->key->public_id}/meta", ['Origin' => $origin])->assertUnauthorized();
    }
    $this->withToken('bad')->getJson("/embed/v1/{$second->public_id}/meta")->assertUnauthorized();
    $this->withHeader('Authorization', '')->getJson("/embed/v1/{$this->key->public_id}/meta")->assertUnauthorized();

    expect(AuditLog::where('action', 'embed.token_rejected')->count())->toBe(3);

    $this->travel(59)->seconds();
    $this->withToken('bad')->getJson("/embed/v1/{$this->key->public_id}/meta")->assertUnauthorized();
    expect(AuditLog::where('action', 'embed.token_rejected')->count())->toBe(3);

    $this->travel(2)->seconds();
    $this->withToken('bad')->getJson("/embed/v1/{$this->key->public_id}/meta")->assertUnauthorized();
    expect(AuditLog::where('action', 'embed.token_rejected')->count())->toBe(4);
});

it('records a missing origin explicitly and does not audit valid tokens as rejections', function () {
    $this->withToken('bad')->getJson("/embed/v1/{$this->key->public_id}/meta")->assertUnauthorized();
    expect(AuditLog::where('action', 'embed.token_rejected')->sole()->metadata)->toHaveKey('origin', null);

    $this->withToken(EmbedTokens::token($this->key, [$this->site->id]))
        ->getJson("/embed/v1/{$this->key->public_id}/meta")->assertOk();
    expect(AuditLog::where('action', 'embed.token_rejected')->count())->toBe(1);
});

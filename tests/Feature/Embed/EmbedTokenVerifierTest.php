<?php

use App\Embed\EmbedException;
use App\Embed\EmbedTokenSigner;
use App\Embed\EmbedTokenVerifier;
use App\Embed\Origins;
use App\Models\EmbedKey;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Tests\Support\EmbedTokens;

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->other = Organization::create(['name' => 'Globex', 'slug' => 'globex']);
    $this->site = Project::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'domain' => 'acme.com']);
    $this->shop = Project::create(['organization_id' => $this->org->id, 'name' => 'Shop', 'domain' => 'shop.acme.com']);
    $this->foreign = Project::create(['organization_id' => $this->other->id, 'name' => 'Globex', 'domain' => 'globex.com']);

    [$this->key, $this->secret] = EmbedTokens::key($this->org);
    $this->verifier = new EmbedTokenVerifier;
});

/**
 * Assert that verifying $token fails with the given reason code.
 */
function embedRejects(string $token, string $reason, ?string $kid = null): void
{
    try {
        test()->verifier->verify($token, $kid);
    } catch (EmbedException $e) {
        expect($e->reason)->toBe($reason);

        return;
    }

    test()->fail("Expected the token to be rejected with [{$reason}].");
}

function embedHeader(EmbedKey $key, array $overrides = []): array
{
    return array_merge(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $key->public_id], $overrides);
}

it('accepts a valid token and builds a grant from its claims', function () {
    $token = EmbedTokens::token($this->key, [$this->site->id], [
        'widgets' => ['kpi', 'trend'],
        'period' => ['default' => '7d', 'allowed' => ['7d', '30d']],
        'filters' => ['locked' => ['hostname' => 'acme.com'], 'adjustable' => ['page', 'country']],
        'timezone' => 'Europe/London',
        'theme' => 'dark',
    ]);

    $grant = $this->verifier->verify($token, $this->key->public_id);

    expect($grant->projectIds())->toBe([$this->site->id])
        ->and($grant->widgets)->toBe(['kpi', 'trend'])
        ->and($grant->defaultPeriod)->toBe('7d')
        ->and($grant->periods)->toBe(['7d', '30d'])
        ->and($grant->lockedFilters)->toBe(['hostname' => 'acme.com'])
        ->and($grant->adjustable)->toBe(['page', 'country'])
        ->and($grant->timezone)->toBe('Europe/London')
        ->and($grant->theme)->toBe('dark')
        ->and($grant->sub)->toBe('viewer-1');
});

it('rejects a tampered payload', function () {
    $token = EmbedTokens::token($this->key, [$this->site->id]);
    [$h, , $s] = explode('.', $token);
    $claims = EmbedTokens::claims($this->key, [$this->site->id, $this->shop->id]);

    embedRejects($h.'.'.EmbedTokenSigner::b64(json_encode($claims)).'.'.$s, 'signature_invalid');
});

it('rejects filter tampering after signing', function () {
    $claims = EmbedTokens::claims($this->key, [$this->site->id], ['filters' => ['locked' => ['hostname' => 'acme.com']]]);
    $token = EmbedTokens::raw(embedHeader($this->key), $claims, $this->secret);
    [$h, , $s] = explode('.', $token);

    $claims['ryvr']['filters']['locked'] = (object) [];

    embedRejects($h.'.'.EmbedTokenSigner::b64(json_encode($claims)).'.'.$s, 'signature_invalid');
});

it('rejects a token signed with another secret', function () {
    embedRejects(EmbedTokens::raw(embedHeader($this->key), EmbedTokens::claims($this->key, [$this->site->id]), 'ryvr_embed_wrong'), 'signature_invalid');
});

it('rejects alg none and other algorithms', function (array $header) {
    $claims = EmbedTokens::claims($this->key, [$this->site->id]);
    $unsigned = EmbedTokenSigner::b64(json_encode(embedHeader($this->key, $header))).'.'.EmbedTokenSigner::b64(json_encode($claims)).'.';

    embedRejects($unsigned, 'token_malformed');
    embedRejects($unsigned.'c2ln', 'invalid_header');
    embedRejects(EmbedTokens::raw(embedHeader($this->key, $header), $claims, $this->secret), 'invalid_header');
})->with([
    'none' => [['alg' => 'none']],
    'None' => [['alg' => 'None']],
    'HS512' => [['alg' => 'HS512']],
    'RS256' => [['alg' => 'RS256']],
]);

it('rejects unexpected header shapes', function (array $header) {
    embedRejects(EmbedTokens::raw($header, EmbedTokens::claims($this->key, [$this->site->id]), $this->secret), 'invalid_header');
})->with([
    'wrong typ' => fn () => embedHeader($this->key, ['typ' => 'JOSE']),
    'missing kid' => fn () => ['alg' => 'HS256', 'typ' => 'JWT'],
    'extra jku' => fn () => embedHeader($this->key, ['jku' => 'https://evil.test/keys']),
    'bad kid' => fn () => embedHeader($this->key, ['kid' => '../../etc']),
]);

it('rejects an unknown or mismatched kid', function () {
    [$other] = EmbedTokens::key($this->org);
    $token = EmbedTokens::token($this->key, [$this->site->id]);

    embedRejects($token, 'key_mismatch', $other->public_id);

    $ghost = EmbedTokens::raw(embedHeader($this->key, ['kid' => 'ek_'.str_repeat('a', 20)]), EmbedTokens::claims($this->key, [$this->site->id]), $this->secret);
    embedRejects($ghost, 'key_not_found');
});

it('rejects tokens of a revoked key', function () {
    $token = EmbedTokens::token($this->key, [$this->site->id]);
    $this->key->forceFill(['revoked_at' => now()])->save();

    embedRejects($token, 'key_not_found');
});

it('rejects expired tokens, allowing a minute of clock skew', function () {
    $now = time();
    $fresh = EmbedTokens::token($this->key, [$this->site->id], [], ['iat' => $now - 330, 'exp' => $now - 30]);
    expect($this->verifier->verify($fresh))->not->toBeNull();

    embedRejects(EmbedTokens::token($this->key, [$this->site->id], [], ['iat' => $now - 400, 'exp' => $now - 100]), 'token_expired');
});

it('rejects tokens issued in the future', function () {
    $now = time();
    embedRejects(EmbedTokens::token($this->key, [$this->site->id], [], ['iat' => $now + 120, 'exp' => $now + 300]), 'token_not_yet_valid');
});

it('rejects lifetimes over the key cap and the hard cap', function () {
    $now = time();
    embedRejects(EmbedTokens::token($this->key, [$this->site->id], [], ['iat' => $now, 'exp' => $now + 601]), 'token_lifetime_too_long');

    $this->key->forceFill(['max_ttl_seconds' => 999999])->save();
    embedRejects(EmbedTokens::token($this->key, [$this->site->id], [], ['iat' => $now, 'exp' => $now + 3601]), 'token_lifetime_too_long');
});

it('requires exp, iat, jti, iss and aud', function (string $claim, mixed $value) {
    $claims = EmbedTokens::claims($this->key, [$this->site->id]);
    if ($value === null) {
        unset($claims[$claim]);
    } else {
        $claims[$claim] = $value;
    }

    embedRejects((new EmbedTokenSigner)->sign($this->key, $claims), 'invalid_claims');
})->with([
    ['exp', null],
    ['iat', null],
    ['exp', '9999999999'],
    ['jti', null],
    ['jti', 'x'],
    ['iss', null],
    ['iss', 'ek_someoneelse'],
    ['aud', 'ryvr:api'],
]);

it('rejects unknown ryvr claims so typos fail loudly', function () {
    embedRejects(EmbedTokens::token($this->key, [$this->site->id], ['site' => 'all']), 'invalid_claims');
});

it('rejects origins outside the key allowlist', function () {
    embedRejects(EmbedTokens::token($this->key, [$this->site->id], ['origins' => ['https://evil.test']]), 'origin_not_allowed');
    embedRejects(EmbedTokens::token($this->key, [$this->site->id], ['origins' => ['https://*.example.com']]), 'origin_not_allowed');
    embedRejects(EmbedTokens::token($this->key, [$this->site->id], ['origins' => ['*']]), 'invalid_claims');
    embedRejects(EmbedTokens::token($this->key, [$this->site->id], ['origins' => ['http://app.example.com']]), 'invalid_claims');

    $grant = $this->verifier->verify(EmbedTokens::token($this->key, [$this->site->id], ['origins' => ['https://eu.customer.test', 'https://*.app.customer.test']]));
    expect($grant->allowsOrigin('https://x.app.customer.test'))->toBeTrue()
        ->and($grant->allowsOrigin('https://customer.test'))->toBeFalse();
});

it('rejects sites outside the key ceiling', function () {
    $this->key->forceFill(['project_ids' => [$this->shop->id]])->save();

    embedRejects(EmbedTokens::token($this->key, [$this->site->id]), 'site_not_allowed');
    expect($this->verifier->verify(EmbedTokens::token($this->key, [$this->shop->id]))->projectIds())->toBe([$this->shop->id]);
});

it('drops foreign, archived and deleted projects and refuses when none remain', function () {
    $grant = $this->verifier->verify(EmbedTokens::token($this->key, [$this->site->id, $this->foreign->id]));
    expect($grant->projectIds())->toBe([$this->site->id]);

    embedRejects(EmbedTokens::token($this->key, [$this->foreign->id]), 'no_sites');

    $this->shop->archive();
    embedRejects(EmbedTokens::token($this->key, [$this->shop->id]), 'no_sites');
});

it('rejects widgets the key does not allow', function () {
    $this->key->forceFill(['widgets' => ['kpi']])->save();

    embedRejects(EmbedTokens::token($this->key, [$this->site->id], ['widgets' => ['kpi', 'realtime']]), 'widget_not_allowed');
    embedRejects(EmbedTokens::token($this->key, [$this->site->id], ['widgets' => ['raw_events']]), 'invalid_claims');
});

it('rejects city filters and periods longer than the key allows', function () {
    embedRejects(EmbedTokens::token($this->key, [$this->site->id], ['filters' => ['adjustable' => ['city']]]), 'filter_not_allowed');
    embedRejects(EmbedTokens::token($this->key, [$this->site->id], ['filters' => ['locked' => ['city' => 'Paris']]]), 'filter_not_allowed');
    embedRejects(EmbedTokens::token($this->key, [$this->site->id], ['period' => ['allowed' => ['forever']]]), 'invalid_claims');

    $this->key->forceFill(['max_period_days' => 30])->save();
    embedRejects(EmbedTokens::token($this->key, [$this->site->id], ['period' => ['allowed' => ['90d']]]), 'period_not_allowed');
});

it('rejects oversized and malformed tokens', function () {
    embedRejects(str_repeat('a', 5000), 'token_too_large');
    embedRejects('abc.def', 'token_malformed');
    embedRejects('a+b.c/d.e=f', 'token_malformed');
    embedRejects('', 'token_missing');
});

it('rejects a revoked token id', function () {
    $claims = EmbedTokens::claims($this->key, [$this->site->id]);
    Cache::put(EmbedTokenVerifier::revocationKey($this->org->id, $claims['jti']), true, 600);

    embedRejects((new EmbedTokenSigner)->sign($this->key, $claims), 'token_revoked');
});

it('accepts tokens in the shape common JWT libraries produce', function () {
    // Same bytes a typical library emits: header {"typ":"JWT","alg":"HS256","kid":...}.
    $token = EmbedTokens::raw(['typ' => 'JWT', 'alg' => 'HS256', 'kid' => $this->key->public_id], EmbedTokens::claims($this->key, [$this->site->id]), $this->secret);

    expect($this->verifier->verify($token)->projectIds())->toBe([$this->site->id]);
});

it('normalises and matches origin patterns', function () {
    expect(Origins::normalize('HTTPS://App.Example.com/'))->toBe('https://app.example.com')
        ->and(Origins::normalize('http://localhost:5173'))->toBe('http://localhost:5173')
        ->and(Origins::normalize('https://*.example.com'))->toBe('https://*.example.com')
        ->and(Origins::normalize('*'))->toBeNull()
        ->and(Origins::normalize('http://example.com'))->toBeNull()
        ->and(Origins::normalize('https://*.com'))->toBeNull()
        ->and(Origins::normalize('https://example.com/path'))->toBeNull()
        ->and(Origins::normalize("https://example.com; script-src 'unsafe-inline'"))->toBeNull()
        ->and(Origins::matches('https://a.example.com', 'https://*.example.com'))->toBeTrue()
        ->and(Origins::matches('https://example.com', 'https://*.example.com'))->toBeFalse()
        ->and(Origins::matches('https://evilexample.com', 'https://*.example.com'))->toBeFalse()
        ->and(Origins::matches('https://a.example.com:8443', 'https://*.example.com'))->toBeFalse();
});

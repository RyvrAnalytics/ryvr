<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Models\AuditLog;
use App\Models\EmbedKey;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fakes\FakeClickHouse;
use Tests\Support\EmbedTokens;

beforeEach(function () {
    $this->clickhouse = new FakeClickHouse;
    $this->app->instance(ClickHouse::class, $this->clickhouse);
    $this->app->instance('clickhouse.read', $this->app->make(ClickHouse::class));

    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->other = Organization::create(['name' => 'Globex', 'slug' => 'globex']);
    $this->site = Project::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'domain' => 'acme.com']);
    $this->shop = Project::create(['organization_id' => $this->org->id, 'name' => 'Shop', 'domain' => 'shop.acme.com']);
    $this->foreign = Project::create(['organization_id' => $this->other->id, 'name' => 'Globex', 'domain' => 'globex.com']);

    [$this->key] = EmbedTokens::key($this->org);
    $this->token = EmbedTokens::token($this->key, [$this->site->id], [
        'period' => ['default' => '30d', 'allowed' => ['7d', '30d']],
        'filters' => ['locked' => ['hostname' => 'acme.com'], 'adjustable' => ['page', 'country']],
    ]);
});

function embed(string $path, ?string $token = null, array $headers = [], ?EmbedKey $key = null)
{
    $kid = ($key ?? test()->key)->public_id;

    return test()->getJson("/embed/v1/{$kid}/{$path}", array_filter($headers + [
        'Authorization' => $token ? 'Bearer '.$token : null,
    ]));
}

it('requires an embed token', function () {
    embed('summary')->assertUnauthorized()->assertHeader('WWW-Authenticate')->assertJsonPath('error.code', 'token_missing');
    embed('summary', 'not-a-token')->assertUnauthorized()->assertJsonPath('error.code', 'token_malformed');
});

it('does not accept API keys or session cookies', function () {
    embed('summary', 'ryvr_org_'.str_repeat('x', 40))->assertUnauthorized();
});

it('serves every widget for a valid token, scoped to the granted site', function () {
    foreach (['meta', 'summary', 'trend', 'top/pages', 'top/sources', 'realtime'] as $path) {
        embed($path, $this->token)->assertOk()->assertJsonStructure(['data']);
    }

    expect($this->clickhouse->queriedProjectIds())->toBe([$this->site->id]);
});

it('describes the grant without revealing locked filter values', function () {
    $token = EmbedTokens::token($this->key, [$this->site->id], [
        'period' => ['default' => '30d', 'allowed' => ['7d', '30d']],
        'filters' => ['locked' => ['hostname' => 'private.acme.com']],
    ]);

    embed('meta', $token)
        ->assertOk()
        ->assertJsonPath('data.sites.0.id', $this->site->id)
        ->assertJsonPath('data.period.allowed', ['7d', '30d'])
        ->assertJsonPath('data.filters.locked', ['hostname'])
        ->assertDontSee('private.acme.com')
        ->assertJsonPath('data.key_id', $this->key->public_id);

    embed('summary', $token)->assertOk()->assertDontSee('private.acme.com');
});

it('always applies locked filters, merged last', function () {
    embed('summary?filters[page]=/pricing', $this->token)->assertOk();

    $params = collect($this->clickhouse->queries)->pluck('params');
    expect($params->every(fn ($p) => in_array('acme.com', $p, true)))->toBeTrue()
        ->and($params->every(fn ($p) => in_array('/pricing', $p, true)))->toBeTrue();
});

it('rejects a key id in the URL that does not match the token', function () {
    [$other] = EmbedTokens::key($this->org);

    embed('summary', $this->token, [], $other)->assertUnauthorized()->assertJsonPath('error.code', 'key_mismatch');
});

it('returns 404 for malformed key ids', function () {
    $this->getJson('/embed/v1/ek_BAD/summary')->assertNotFound();
});

it('rejects tampering with the query', function (string $query) {
    embed($query, $this->token)->assertStatus(400)->assertJsonPath('error.code', 'invalid_parameter');
    expect($this->clickhouse->queries)->toBe([]);
})->with([
    'another site of the org' => fn () => 'summary?site='.$this->shop->id,
    'a foreign site' => fn () => 'summary?site='.$this->foreign->id,
    'site as array' => fn () => 'summary?site[]='.$this->site->id,
    'period outside the grant' => ['summary?period=90d'],
    'custom range' => ['summary?period=custom&from=2020-01-01&to=2026-01-01'],
    'overriding a locked filter' => ['summary?filters[hostname]=globex.com'],
    'filter not adjustable' => ['summary?filters[referrer]=x.com'],
    'city filter' => ['summary?filters[city]=Paris'],
    'unknown parameter' => ['summary?projects=all'],
    'interval on trend' => ['trend?interval=minute'],
    'limit over 50' => ['top/pages?limit=51'],
    'limit zero' => ['top/pages?limit=0'],
    'limit text' => ['top/pages?limit=ten'],
    'unknown breakdown' => ['top/sources?by=city'],
    'parameters on meta' => ['meta?site=x'],
]);

it('accepts allowed viewer choices', function () {
    $token = EmbedTokens::token($this->key, [$this->site->id, $this->shop->id], [
        'period' => ['default' => '30d', 'allowed' => ['7d', '30d']],
        'filters' => ['adjustable' => ['country']],
    ]);

    embed('summary?site='.$this->shop->id.'&period=7d&filters[country]=DE', $token)->assertOk()->assertJsonPath('data.range.period', '7d');
    embed('top/sources?by=referrer&limit=50', $token)->assertOk()->assertJsonPath('data.dimension', 'referrer');

    expect($this->clickhouse->queriedProjectIds())->toEqualCanonicalizing([$this->site->id, $this->shop->id]);
});

it('refuses widgets the token does not include', function () {
    $token = EmbedTokens::token($this->key, [$this->site->id], ['widgets' => ['kpi']]);

    embed('summary', $token)->assertOk();
    embed('trend', $token)->assertForbidden()->assertJsonPath('error.code', 'widget_not_allowed');
    embed('realtime', $token)->assertForbidden();
});

it('checks the browser origin against the token', function () {
    embed('summary', $this->token, ['Origin' => 'https://evil.test'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'origin_not_allowed')
        ->assertHeaderMissing('Access-Control-Allow-Origin');

    // Allowed by the key, but not named in this token.
    embed('summary', $this->token, ['Origin' => 'https://eu.customer.test'])->assertForbidden();

    embed('summary', $this->token, ['Origin' => 'https://app.example.com'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');
});

it('answers CORS preflight from the key allowlist only', function () {
    $kid = $this->key->public_id;

    $this->call('OPTIONS', "/embed/v1/{$kid}/summary", [], [], [], ['HTTP_ORIGIN' => 'https://app.example.com', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET'])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com')
        ->assertHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
        ->assertHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');

    $this->call('OPTIONS', "/embed/v1/{$kid}/top/pages", [], [], [], ['HTTP_ORIGIN' => 'https://evil.test'])
        ->assertForbidden()
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('never sets cookies and sends cache and robots headers', function () {
    $response = embed('summary', $this->token, ['Origin' => 'https://app.example.com']);

    $response->assertOk()
        ->assertHeaderMissing('Set-Cookie')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    expect($response->headers->get('Vary'))->toContain('Origin')->toContain('Authorization');

    embed('summary')->assertHeaderMissing('Set-Cookie');
});

it('stops working as soon as the key is revoked', function () {
    embed('summary', $this->token)->assertOk();

    $this->key->forceFill(['revoked_at' => now()])->save();

    embed('summary', $this->token)->assertUnauthorized()->assertJsonPath('error.code', 'key_not_found');
});

it('drops sites that are archived after the token was issued', function () {
    $token = EmbedTokens::token($this->key, [$this->site->id, $this->shop->id]);
    $this->shop->archive();

    embed('summary?site='.$this->shop->id, $token)->assertStatus(400);
    embed('meta', $token)->assertJsonCount(1, 'data.sites');
});

it('rate limits per token', function () {
    config(['ryvr.embed.rate_limit_per_token' => 2]);

    embed('summary', $this->token)->assertOk();
    embed('summary', $this->token)->assertOk();
    embed('summary', $this->token)->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error.code', 'rate_limited');

    // Another token on the same key still works.
    embed('summary', EmbedTokens::token($this->key, [$this->site->id]))->assertOk();
});

it('rate limits per key', function () {
    $this->key->forceFill(['rate_limit_per_minute' => 1])->save();

    embed('summary', $this->token)->assertOk();
    embed('summary', EmbedTokens::token($this->key, [$this->site->id]))->assertStatus(429);
});

it('slows down clients that send bad tokens', function () {
    config(['ryvr.embed.rate_limit_rejected_per_ip' => 2]);

    embed('summary', 'bad')->assertUnauthorized();
    embed('summary', 'bad')->assertUnauthorized();
    embed('summary', $this->token)->assertStatus(429);

    RateLimiter::clear('embed-rejected:127.0.0.1');
});

it('records usage cheaply: last used, and one audit entry per token', function () {
    embed('summary', $this->token)->assertOk();
    embed('trend', $this->token)->assertOk();
    embed('meta', $this->token)->assertOk();

    expect($this->key->fresh()->last_used_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'embed.viewed')->count())->toBe(1)
        ->and(AuditLog::where('action', 'embed.viewed')->first()->ip_address)->toBeNull()
        ->and(cache()->get('embed:usage:'.$this->key->id.':'.now()->format('Ymd')))->toBe(3);
});

it('keeps cache entries apart for tokens with different locked filters', function () {
    $this->clickhouse->rows = [['visitors' => 5, 'visits' => 5, 'pageviews' => 9, 'bounces' => 1, 'avg_duration' => 10, 'events' => 0]];
    $a = EmbedTokens::token($this->key, [$this->site->id], ['filters' => ['locked' => ['hostname' => 'a.acme.com']]]);
    $b = EmbedTokens::token($this->key, [$this->site->id], ['filters' => ['locked' => ['hostname' => 'b.acme.com']]]);

    embed('summary', $a)->assertOk();
    $before = count($this->clickhouse->queries);
    embed('summary', $b)->assertOk();

    expect(count($this->clickhouse->queries))->toBeGreaterThan($before);
});

it('serves the iframe page with frame-ancestors from the key and no X-Frame-Options', function () {
    $response = $this->get('/embed/v1/'.$this->key->public_id.'/dashboard');

    $response->assertOk()
        ->assertHeaderMissing('X-Frame-Options')
        ->assertHeaderMissing('Set-Cookie')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('data-kid="'.$this->key->public_id.'"', false);

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain('frame-ancestors https://app.example.com https://*.customer.test')
        ->toContain("default-src 'none'")
        ->toContain("script-src 'self' 'nonce-")
        ->toContain("base-uri 'none'");
});

it('does not serve the iframe page for revoked or unknown keys', function () {
    $this->key->forceFill(['revoked_at' => now()])->save();

    $this->get('/embed/v1/'.$this->key->public_id.'/dashboard')->assertNotFound()->assertHeaderMissing('X-Frame-Options');
    $this->get('/embed/v1/ek_'.str_repeat('z', 20).'/dashboard')->assertNotFound();
});

it('keeps X-Frame-Options DENY on the rest of the app', function () {
    $this->get('/login')->assertHeader('X-Frame-Options', 'DENY');
});

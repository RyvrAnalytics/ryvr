<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Enums\Role;
use App\Models\EmbedKey;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Tests\Fakes\FakeClickHouse;

beforeEach(function () {
    $this->app->instance(ClickHouse::class, new FakeClickHouse);
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->site = Project::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'domain' => 'acme.com']);
    $this->owner = User::factory()->create();
    $this->org->addMember($this->owner, Role::Owner);
});

/**
 * @return array<string, string>
 */
function cspDirectives(string $policy): array
{
    $directives = [];
    foreach (array_filter(array_map('trim', explode(';', $policy))) as $directive) {
        [$name, $value] = array_pad(explode(' ', $directive, 2), 2, '');
        $directives[$name] = $value;
    }

    return $directives;
}

it('sends a nonce-based policy on the login page and puts the nonce on every inline script and style', function () {
    $response = $this->get('/login')->assertOk();

    $policy = cspDirectives((string) $response->headers->get('Content-Security-Policy'));
    preg_match("/'nonce-([^']+)'/", $policy['script-src'] ?? '', $match);
    $nonce = $match[1] ?? null;

    expect($nonce)->not->toBeNull()
        ->and($policy['default-src'])->toBe("'self'")
        ->and($policy['script-src'])->toBe("'self' 'nonce-{$nonce}'")
        ->and($policy['style-src-elem'])->toBe("'self' 'nonce-{$nonce}'")
        ->and($policy['img-src'])->toBe("'self' data: https:")
        ->and($policy['font-src'])->toBe("'self'")
        ->and($policy['connect-src'])->toBe("'self'")
        ->and($policy['frame-ancestors'])->toBe("'none'")
        ->and($policy['base-uri'])->toBe("'self'")
        ->and($policy['form-action'])->toBe("'self'")
        ->and($policy['object-src'])->toBe("'none'");

    // Every executable inline <script> and every <style> element carries the nonce.
    $html = $response->getContent();
    preg_match_all('/<script(?![^>]*type="application\/json")[^>]*>/', $html, $scripts);
    preg_match_all('/<style[^>]*>/', $html, $styles);

    expect($scripts[0])->not->toBeEmpty()
        ->and($styles[0])->not->toBeEmpty();
    foreach ([...$scripts[0], ...$styles[0]] as $tag) {
        expect($tag)->toContain("nonce=\"{$nonce}\"");
    }
});

it('sends the policy on dashboard pages with a fresh nonce per response', function () {
    $first = $this->actingAs($this->owner)->get('/sites')->assertOk()->headers->get('Content-Security-Policy');
    $second = $this->actingAs($this->owner)->get("/sites/{$this->site->id}")->assertOk()->headers->get('Content-Security-Policy');

    expect($first)->toContain("frame-ancestors 'none'")
        ->and($second)->toContain("frame-ancestors 'none'")
        ->and($first)->not->toBe($second);
});

it('can report violations without enforcing', function () {
    config(['ryvr.csp.report_only' => true]);

    $this->get('/login')->assertOk()
        ->assertHeaderMissing('Content-Security-Policy')
        ->assertHeader('Content-Security-Policy-Report-Only');
});

it('can be turned off', function () {
    config(['ryvr.csp.enabled' => false]);

    $this->get('/login')->assertOk()->assertHeaderMissing('Content-Security-Policy');
});

it('allows the Vite dev server while it runs', function () {
    $hot = storage_path('framework/testing-hot');
    file_put_contents($hot, 'http://[::1]:5173');
    Vite::useHotFile($hot);

    try {
        $policy = cspDirectives((string) $this->get('/login')->headers->get('Content-Security-Policy'));
    } finally {
        @unlink($hot);
    }

    expect($policy['script-src'])->toContain('http://[::1]:5173')
        ->and($policy['style-src-elem'])->toContain("'unsafe-inline'")
        ->and($policy['connect-src'])->toContain('ws://[::1]:5173');
});

it('keeps the embed page\'s own policy', function () {
    [$key] = EmbedKey::issue([
        'organization_id' => $this->org->id,
        'name' => 'Portal',
        'allowed_origins' => ['https://app.example.com'],
        'project_ids' => [$this->site->id],
        'widgets' => ['kpi'],
        'max_ttl_seconds' => 600,
    ]);

    $policy = cspDirectives((string) $this->get("/embed/v1/{$key->public_id}/dashboard")->assertOk()->headers->get('Content-Security-Policy'));

    expect($policy['default-src'])->toBe("'none'")
        ->and($policy['frame-ancestors'])->toBe('https://app.example.com');
});

it('keeps the favicon proxy\'s own policy', function () {
    Storage::fake('local');
    $key = 'favicons/'.sha1('example.com');
    Storage::disk('local')->put($key.'.json', (string) json_encode(['fetched_at' => time(), 'type' => 'image/png']));
    Storage::disk('local')->put($key.'.bin', "\x89PNG\r\n\x1a\n");

    $this->actingAs($this->owner)->get('/icons/example.com')
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'none'");
});

it('sends no dashboard policy on the collector and health check', function () {
    $this->call('POST', '/e', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{}')
        ->assertHeaderMissing('Content-Security-Policy');
    $this->get('/health')->assertHeaderMissing('Content-Security-Policy');
});

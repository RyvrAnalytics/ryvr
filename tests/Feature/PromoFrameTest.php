<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Tests\Fakes\FakeClickHouse;

beforeEach(function () {
    $this->app->instance(ClickHouse::class, new FakeClickHouse);
    $this->app->instance('clickhouse.read', $this->app->make(ClickHouse::class));
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
    Project::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'domain' => 'acme.com']);
    $this->owner = User::factory()->create();
    $this->org->addMember($this->owner, Role::Owner);
});

/**
 * @return array<string, string>
 */
function promoCsp(string $policy): array
{
    $directives = [];
    foreach (array_filter(array_map('trim', explode(';', $policy))) as $directive) {
        [$name, $value] = array_pad(explode(' ', $directive, 2), 2, '');
        $directives[$name] = $value;
    }

    return $directives;
}

it('shares no promo by default', function () {
    $this->actingAs($this->owner)->get('/sites')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('promo'));
});

it('shares the promo frame when configured with an https URL', function () {
    config(['ryvr.promo.frame_url' => 'https://promo.example.com/frame/', 'ryvr.promo.height' => 200]);

    $this->actingAs($this->owner)->get('/sites')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('promo.url', 'https://promo.example.com/frame/')
            ->where('promo.height', 200));
});

it('rejects unsafe or non-https promo URLs in production', function (string $url) {
    app()->detectEnvironment(fn () => 'production');
    config(['ryvr.promo.frame_url' => $url]);

    $response = $this->actingAs($this->owner)->get('/sites')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('promo'));

    expect(promoCsp((string) $response->headers->get('Content-Security-Policy')))->not->toHaveKey('frame-src');
})->with([
    'javascript' => ['javascript:alert(1)'],
    'plain http' => ['http://promo.example.com/frame/'],
    'data' => ['data:text/html,hi'],
    'not a url' => ['promo frame'],
    'credentials' => ['https://user:pass@promo.example.com/'],
]);

it('allows plain http in the local environment', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['ryvr.promo.frame_url' => 'http://localhost:8080/frame']);

    $this->actingAs($this->owner)->get('/sites')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('promo.url', 'http://localhost:8080/frame'));
});

it('adds only the promo origin to frame-src when configured', function () {
    config(['ryvr.promo.frame_url' => 'https://promo.example.com:8443/promo/frame/?x=1']);

    $policy = promoCsp((string) $this->actingAs($this->owner)->get('/sites')->headers->get('Content-Security-Policy'));

    expect($policy['frame-src'])->toBe("'self' https://promo.example.com:8443")
        ->and($policy['default-src'])->toBe("'self'")
        ->and($policy['frame-ancestors'])->toBe("'none'");
});

it('leaves the policy without frame-src when no promo is configured', function () {
    $policy = promoCsp((string) $this->actingAs($this->owner)->get('/sites')->headers->get('Content-Security-Policy'));

    expect($policy)->not->toHaveKey('frame-src')
        ->and($policy['default-src'])->toBe("'self'");
});

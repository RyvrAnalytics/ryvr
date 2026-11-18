<?php

use App\Analytics\ClickHouse\ClickHouseException;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\EmbedTokens;

beforeEach(function () {
    Http::preventStrayRequests();
    $org = Organization::create(['name' => 'Example', 'slug' => 'example']);
    $site = Project::create(['organization_id' => $org->id, 'name' => 'Example', 'domain' => 'example.test']);
    [$this->key] = EmbedTokens::key($org);
    $this->token = EmbedTokens::token($this->key, [$site->id]);
});

it('passes embed limits on every widget query', function (string $path, ?array $limits) {
    if ($limits !== null) {
        config(['ryvr.embed.max_execution_time' => $limits[0], 'ryvr.embed.max_rows_to_read' => $limits[1]]);
    }
    Http::fake(fn () => Http::response(''));

    $this->withToken($this->token)->getJson("/embed/v1/{$this->key->public_id}/{$path}")->assertOk();

    expect(Http::recorded())->not->toBeEmpty();
    foreach (Http::recorded() as [$request]) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $settings);
        expect($settings['max_execution_time'])->toBe((string) ($limits[0] ?? 5))
            ->and($settings['max_rows_to_read'])->toBe((string) ($limits[1] ?? 200_000_000))
            ->and($settings['timeout_overflow_mode'])->toBe('throw')
            ->and($settings['read_overflow_mode'])->toBe('throw');
    }
    if ($path === 'summary') {
        Http::assertSentCount(2);
    }
})->with(['summary', 'trend', 'top/pages', 'top/sources', 'realtime'])
    ->with(['defaults' => [null], 'configured' => [[2, 12345]]]);

it('does not carry embed limits into other requests on the same client', function () {
    Http::fake(fn () => Http::response(''));
    $client = app('clickhouse.read');
    $client->select('SELECT 1');
    $this->withToken($this->token)->getJson("/embed/v1/{$this->key->public_id}/summary")->assertOk();
    $this->get('/login')->assertOk();
    $client->select('SELECT 1');

    $requests = Http::recorded(fn (Request $request) => str_starts_with($request->body(), 'SELECT 1'));
    expect($requests)->toHaveCount(2);
    foreach ($requests as [$request]) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $settings);
        expect($settings['max_execution_time'])->toBe((string) config('ryvr.clickhouse.query_max_execution_time', 20))
            ->and($settings)->not->toHaveKeys(['max_rows_to_read', 'read_overflow_mode', 'timeout_overflow_mode']);
    }
});

it('returns a safe 503 for query limits including streamed errors', function (string $path, int $code, int $status) {
    $body = "Code: {$code}. DB::Exception: Query limit exceeded. Private query details.";
    Http::fake(fn () => Http::response(($status === 200 ? "{\"visitors\":1}\n" : '').$body, $status));

    $this->withToken($this->token)
        ->getJson("/embed/v1/{$this->key->public_id}/{$path}", ['Origin' => 'https://app.example.com'])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'query_limit_exceeded')
        ->assertJsonStructure(['error' => ['code', 'message']])
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com')
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertDontSee('Private query details')
        ->assertJsonMissingPath('data');
})->with(['summary', 'trend', 'top/pages', 'top/sources', 'realtime'])
    ->with([158, 159, 396])->with([500, 200]);

it('recognizes query limits from the exception header', function () {
    Http::fake(fn () => Http::response('Private query details.', 500, ['X-ClickHouse-Exception-Code' => '159']));

    $this->withToken($this->token)->getJson("/embed/v1/{$this->key->public_id}/summary")
        ->assertStatus(503)->assertJsonPath('error.code', 'query_limit_exceeded')->assertDontSee('Private query details');
});

it('returns 503 if the previous period query exceeds its limit', function () {
    Http::fakeSequence()->push('')->push('Code: 159. DB::Exception: Timeout exceeded.', 500);

    $this->withToken($this->token)->getJson("/embed/v1/{$this->key->public_id}/summary")
        ->assertStatus(503)->assertJsonPath('error.code', 'query_limit_exceeded');
    Http::assertSentCount(2);
});

it('preserves unexpected ClickHouse failures', function () {
    $this->withoutExceptionHandling();
    Http::fake(fn () => Http::response('Code: 60. DB::Exception: Unknown table.', 500));

    expect(fn () => $this->withToken($this->token)->getJson("/embed/v1/{$this->key->public_id}/summary"))
        ->toThrow(ClickHouseException::class, 'Unknown table.');
});

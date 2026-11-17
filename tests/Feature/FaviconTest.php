<?php

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    Organization::create(['name' => 'Acme', 'slug' => 'acme'])->addMember($this->user, Role::Owner);
});

it('requires a signed-in member', function () {
    $this->get('/icons/example.com')->assertRedirect('/login');
});

it('refuses anything that is not a plain public hostname', function (string $domain) {
    $this->actingAs($this->user)->get('/icons/'.$domain)->assertNotFound();
})->with([
    'no tld' => ['localhost'],
    'ip address' => ['127.0.0.1'],
    'internal name' => ['metadata'],
]);

it('never contacts hosts that do not resolve to a public address', function () {
    // .invalid never resolves, so no request may be attempted (stray requests would throw).
    $this->actingAs($this->user)->get('/icons/nothing-here.invalid')->assertNotFound();

    Storage::disk('local')->assertExists('favicons/'.sha1('nothing-here.invalid').'.json');
});

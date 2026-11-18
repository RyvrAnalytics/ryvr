<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectGrant;
use App\Models\User;
use App\Tenancy\Registration;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeClickHouse;

beforeEach(function () {
    config(['ryvr.registration' => false]);
    Mail::fake();
    $this->app->instance(ClickHouse::class, new FakeClickHouse);
    $this->organization = Organization::create(['name' => 'Example team', 'slug' => 'example-team']);
    $this->inviter = User::factory()->create(['name' => 'Team owner']);
    $this->organization->addMember($this->inviter, Role::Owner);
    $this->recipient = User::factory()->create(['email' => 'recipient@example.com']);
    $this->project = Project::create([
        'organization_id' => $this->organization->id, 'name' => 'Example site', 'domain' => 'example.com',
    ]);
    [$this->invitation, $this->token] = Invitation::issue([
        'organization_id' => $this->organization->id,
        'email' => $this->recipient->email,
        'role' => Role::Viewer,
        'project_access' => 'granted_only',
        'project_ids' => [$this->project->id],
        'invited_by' => $this->inviter->id,
    ]);
});

it('shows the confirmation to the recipient without accepting or switching organizations', function () {
    $other = Organization::create(['name' => 'Existing team', 'slug' => 'existing-team']);
    $other->addMember($this->recipient, Role::Owner);
    $this->recipient->switchOrganization($other);

    $this->actingAs($this->recipient)->get(route('invitations.show', $this->token))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('invitations/Show')
            ->where('organization', 'Example team')->where('role', 'Viewer')
            ->where('inviter', 'Team owner')->where('email', $this->recipient->email)
            ->where('token', $this->token)->where('signedIn', true)->where('mismatch', null));

    expect($this->invitation->fresh()->accepted_at)->toBeNull()
        ->and($this->recipient->fresh()->current_organization_id)->toBe($other->id)
        ->and(Membership::where('organization_id', $this->organization->id)->count())->toBe(1)
        ->and(ProjectGrant::count())->toBe(0)
        ->and(AuditLog::where('action', 'member.joined')->count())->toBe(0);
});

it('accepts only after the recipient posts and applies the invited role and grants once', function () {
    $this->actingAs($this->recipient)->withSession([Registration::SESSION_KEY => $this->token])
        ->post(route('invitations.accept', $this->token))->assertRedirect(route('sites.index'))
        ->assertSessionMissing(Registration::SESSION_KEY);

    $membership = Membership::where('user_id', $this->recipient->id)->sole();
    $grant = ProjectGrant::where('user_id', $this->recipient->id)->sole();
    expect($membership->role)->toBe(Role::Viewer)
        ->and($membership->project_access)->toBe('granted_only')
        ->and($grant->project_id)->toBe($this->project->id)
        ->and($grant->role)->toBe(Role::Viewer)
        ->and($this->invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($this->recipient->fresh()->current_organization_id)->toBe($this->organization->id);

    $this->post(route('invitations.accept', $this->token))->assertRedirect(route('invitations.show', $this->token));
    expect(Membership::where('user_id', $this->recipient->id)->count())->toBe(1)
        ->and(ProjectGrant::where('user_id', $this->recipient->id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'member.joined')->count())->toBe(1);
});

it('requires authentication before accepting or declining', function (string $action) {
    $this->post(route("invitations.{$action}", $this->token))->assertRedirect(route('login'));

    expect($this->invitation->fresh()->accepted_at)->toBeNull()
        ->and(Membership::where('user_id', $this->recipient->id)->exists())->toBeFalse();
})->with(['accept', 'decline']);

it('returns guests to confirmation after signing in without accepting', function () {
    $url = route('invitations.show', $this->token);
    $this->get($url)->assertOk()->assertSessionHas('url.intended', $url)
        ->assertSessionHas(Registration::SESSION_KEY, $this->token)
        ->assertInertia(fn (Assert $page) => $page->component('invitations/Show')
            ->where('signedIn', false)->where('mismatch', null));

    $this->post(route('login.store'), ['email' => $this->recipient->email, 'password' => 'password'])
        ->assertRedirect($url);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('invitations/Show')
        ->where('signedIn', true)->where('mismatch', null));

    expect($this->invitation->fresh()->accepted_at)->toBeNull()
        ->and(Membership::where('user_id', $this->recipient->id)->exists())->toBeFalse();
});

it('returns new accounts to confirmation before joining', function () {
    $this->recipient->delete();
    $url = route('invitations.show', $this->token);
    $this->get($url)->assertOk();
    $this->get(route('register'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/Register')
        ->where('registrationOpen', true)->where('invitedEmail', $this->invitation->email));
    $this->post(route('register.store'), [
        'name' => 'Recipient', 'email' => $this->invitation->email,
        'password' => 'a-Long-passw0rd!', 'password_confirmation' => 'a-Long-passw0rd!',
    ])->assertRedirect($url);

    $recipient = User::where('email', $this->invitation->email)->sole();
    expect($recipient->email_verified_at)->not->toBeNull()
        ->and($recipient->current_organization_id)->toBeNull()
        ->and(Membership::where('user_id', $recipient->id)->exists())->toBeFalse()
        ->and($this->invitation->fresh()->accepted_at)->toBeNull();

    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('invitations/Show')
        ->where('signedIn', true)->where('mismatch', null));
    $this->post(route('invitations.accept', $this->token))->assertRedirect(route('sites.index'));
    expect(Membership::where('user_id', $recipient->id)->sole()->role)->toBe(Role::Viewer);
});

it('shows an email mismatch and refuses acceptance or decline by another user', function (string $action) {
    $this->actingAs($this->inviter)->get(route('invitations.show', $this->token))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('invitations/Show')
            ->where('mismatch', $this->inviter->email)->where('email', $this->recipient->email));
    $this->post(route("invitations.{$action}", $this->token))->assertRedirect(route('invitations.show', $this->token));

    expect($this->invitation->fresh()->accepted_at)->toBeNull()
        ->and(Membership::where('user_id', $this->inviter->id)->sole()->role)->toBe(Role::Owner)
        ->and(Membership::where('user_id', $this->recipient->id)->exists())->toBeFalse();
})->with(['accept', 'decline']);

it('matches invitation email addresses without case sensitivity', function () {
    $this->recipient->update(['email' => 'Recipient@Example.com']);
    $this->actingAs($this->recipient)->get(route('invitations.show', $this->token))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('mismatch', null));
    $this->post(route('invitations.accept', $this->token))->assertRedirect(route('sites.index'));

    expect($this->invitation->fresh()->accepted_at)->not->toBeNull();
});

it('declines a pending invitation without joining', function () {
    $this->actingAs($this->recipient)->withSession([Registration::SESSION_KEY => $this->token])
        ->post(route('invitations.decline', $this->token))->assertRedirect(route('home'))
        ->assertSessionMissing(Registration::SESSION_KEY);

    expect($this->invitation->fresh())->toBeNull()
        ->and(Membership::where('user_id', $this->recipient->id)->exists())->toBeFalse()
        ->and(ProjectGrant::count())->toBe(0);
    $this->get(route('invitations.show', $this->token))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('invitations/Invalid'));
});

it('shows unavailable invitations and rechecks their status before accepting or declining', function (string $state) {
    $url = route('invitations.show', $this->token);
    $this->actingAs($this->recipient)->get($url)->assertOk();
    match ($state) {
        'expired' => $this->invitation->forceFill(['expires_at' => now()->subSecond()])->save(),
        'used' => $this->invitation->forceFill(['accepted_at' => now()])->save(),
        'revoked' => $this->invitation->delete(),
    };

    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('invitations/Invalid'));
    $this->post(route('invitations.accept', $this->token))->assertRedirect($url);
    $this->post(route('invitations.decline', $this->token))->assertRedirect($url);

    expect(Membership::where('user_id', $this->recipient->id)->exists())->toBeFalse()
        ->and(ProjectGrant::count())->toBe(0)
        ->and(AuditLog::where('action', 'member.joined')->count())->toBe(0);
})->with(['expired', 'used', 'revoked']);

it('shows unknown invitations as unavailable', function () {
    $this->get(route('invitations.show', 'unknown-token'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('invitations/Invalid'));
});

it('handles invitations whose inviter no longer has an account', function () {
    $this->inviter->delete();
    $this->get(route('invitations.show', $this->token))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('invitations/Show')->where('inviter', 'A teammate'));
});

it('requires a valid csrf token for acceptance and decline', function (string $action) {
    $this->app->instance('env', 'local');
    $this->actingAs($this->recipient)->withSession(['_token' => 'session-token']);
    $url = route("invitations.{$action}", $this->token);

    $this->post($url)->assertStatus(419);
    $this->post($url, ['_token' => 'wrong-token'])->assertStatus(419);
    expect($this->invitation->fresh()->accepted_at)->toBeNull()
        ->and(Membership::where('user_id', $this->recipient->id)->exists())->toBeFalse();

    $this->post($url, ['_token' => 'session-token'])->assertRedirect();
    if ($action === 'accept') {
        expect($this->invitation->fresh()->accepted_at)->not->toBeNull();
    } else {
        expect($this->invitation->fresh())->toBeNull();
    }
})->with(['accept', 'decline']);

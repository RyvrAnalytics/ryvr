<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Goal;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectGrant;
use App\Models\ProjectGroup;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Tests\Fakes\FakeClickHouse;
use Tests\Support\EmbedTokens;

beforeEach(function () {
    $this->app->instance(ClickHouse::class, new FakeClickHouse);
    $this->org = Organization::create(['name' => 'Team', 'slug' => 'team']);
    $this->other = Organization::create(['name' => 'Other', 'slug' => 'other']);
    $this->user = User::factory()->create(['name' => 'Member', 'email' => 'member@example.test']);
    $this->org->addMember($this->user, Role::Owner);
    $this->site = Project::create(['organization_id' => $this->org->id, 'name' => 'Main site', 'domain' => 'example.test']);
});

it('records sign in and sign out in every membership with the correct user after logout', function () {
    $this->other->addMember($this->user, Role::Viewer);
    $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])->assertRedirect();
    $this->post('/logout')->assertRedirect();

    foreach (['auth.signed_in', 'auth.signed_out'] as $action) {
        $logs = AuditLog::where('action', $action)->get();
        expect($logs)->toHaveCount(2)
            ->and($logs->pluck('organization_id')->all())->toEqualCanonicalizing([$this->org->id, $this->other->id]);
        foreach ($logs as $log) {
            expect($log->actor_type)->toBe('user')
                ->and($log->actor_id)->toBe((string) $this->user->id)
                ->and($log->metadata['actor_email'])->toBe($this->user->email);
        }
    }
});

it('throttles failed sign ins across IP addresses and records only existing members without credentials', function () {
    $this->other->addMember($this->user, Role::Viewer);
    $this->post('/login', ['email' => strtoupper($this->user->email), 'password' => 'attempted-secret'])->assertSessionHasErrors('email');
    foreach (range(1, 10) as $attempt) {
        request()->server->set('REMOTE_ADDR', '192.0.2.'.$attempt);
        event(new Failed('web', null, ['email' => $this->user->email, 'password' => 'another-secret']));
    }
    $orphan = User::factory()->create();
    foreach (['unknown@example.test', $orphan->email] as $email) {
        event(new Failed('web', null, ['email' => $email, 'password' => 'attempted-secret']));
    }

    $logs = AuditLog::where('action', 'auth.sign_in_failed')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('organization_id')->all())->toEqualCanonicalizing([$this->org->id, $this->other->id])
        ->and($logs->toJson())->not->toContain('attempted-secret', 'another-secret', 'password', $orphan->email, 'unknown@example.test');
    expect($logs->first()->actor_type)->toBe('system')->and($logs->first()->actor_id)->toBeNull();

    $this->travel(61)->seconds();
    event(new Failed('web', $this->user, ['email' => $this->user->email, 'password' => 'attempted-secret']));
    expect(AuditLog::where('action', 'auth.sign_in_failed')->count())->toBe(4);
});

it('records two factor only after confirmation and records disabling without secrets', function () {
    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()]);
    $this->postJson('/user/two-factor-authentication')->assertSuccessful();
    expect(AuditLog::where('action', 'two_factor.enabled')->count())->toBe(0);

    $this->mock(TwoFactorAuthenticationProvider::class)->shouldReceive('verify')->once()->andReturnTrue();
    $this->postJson('/user/confirmed-two-factor-authentication', ['code' => '123456'])->assertSuccessful();
    $this->deleteJson('/user/two-factor-authentication')->assertSuccessful();

    foreach (['two_factor.enabled', 'two_factor.disabled'] as $action) {
        $log = AuditLog::where('action', $action)->sole();
        expect($log->actor_id)->toBe((string) $this->user->id)
            ->and($log->metadata)->toHaveKeys(['actor_name', 'actor_email', 'subject_name'])
            ->and($log->metadata)->toHaveCount(3);
    }
});

it('records a password change only when validation succeeds', function () {
    $this->actingAs($this->user)->put('/settings/password', [
        'current_password' => 'wrong', 'password' => 'new-secret-password', 'password_confirmation' => 'new-secret-password',
    ])->assertSessionHasErrors('current_password');
    expect(AuditLog::where('action', 'password.changed')->count())->toBe(0);

    $this->put('/settings/password', [
        'current_password' => 'password', 'password' => 'new-secret-password', 'password_confirmation' => 'new-secret-password',
    ])->assertSessionHasNoErrors();
    $log = AuditLog::where('action', 'password.changed')->sole();
    expect($log->actor_id)->toBe((string) $this->user->id)
        ->and(json_encode($log->metadata))->not->toContain('new-secret-password', 'current_password');
});

it('records password reset for all memberships without the reset token', function () {
    $this->other->addMember($this->user, Role::Viewer);
    $token = Password::broker()->createToken($this->user);
    $this->post('/reset-password', [
        'email' => $this->user->email, 'token' => $token,
        'password' => 'new-secret-password', 'password_confirmation' => 'new-secret-password',
    ])->assertSessionHasNoErrors();
    $logs = AuditLog::where('action', 'password.changed')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->toJson())->not->toContain($token, 'new-secret-password');
});

it('records passkey registration and removal without credential material', function () {
    $passkey = $this->user->passkeys()->create([
        'name' => 'Security key', 'credential_id' => 'credential-identifier', 'credential' => ['private' => 'credential-material'],
    ]);
    PasskeyRegistered::dispatch($this->user, $passkey);
    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson('/user/passkeys/'.$passkey->id)->assertSuccessful();
    foreach (['passkey.added', 'passkey.removed'] as $action) {
        $log = AuditLog::where('action', $action)->sole();
        expect($log->actor_id)->toBe((string) $this->user->id)
            ->and($log->metadata['name'])->toBe('Security key')
            ->and(json_encode($log->metadata))->not->toContain('credential-identifier', 'credential-material');
    }
});

it('records invitation acceptance and decline in the invited organization only', function (string $decision, string $action) {
    $recipient = User::factory()->create();
    [$invitation, $token] = Invitation::issue([
        'organization_id' => $this->other->id, 'email' => $recipient->email,
        'role' => Role::Viewer, 'project_access' => 'all',
    ]);
    $this->actingAs($recipient)->post("/invitations/{$token}/{$decision}")->assertRedirect();
    $this->post("/invitations/{$token}/{$decision}")->assertRedirect();
    $log = AuditLog::where('action', $action)->sole();
    expect($log->organization_id)->toBe($this->other->id)
        ->and($log->subject_id)->toBe($invitation->id)
        ->and($log->actor_id)->toBe((string) $recipient->id)
        ->and(json_encode($log->metadata))->not->toContain($token);
})->with([['accept', 'invitation.accepted'], ['decline', 'invitation.declined']]);

it('records goal creation updates and deletion without storing matching values', function () {
    $this->actingAs($this->user)->post("/sites/{$this->site->id}/goals", [
        'name' => 'Signup', 'type' => 'event', 'value' => 'sensitive-match-value',
    ])->assertRedirect();
    $goal = Goal::sole();
    $goal->update(['name' => 'New signup']);
    $goal->save();
    $this->delete("/sites/{$this->site->id}/goals/{$goal->id}")->assertRedirect();
    foreach (['goal.created', 'goal.updated', 'goal.deleted'] as $action) {
        $log = AuditLog::where('action', $action)->sole();
        expect($log->organization_id)->toBe($this->org->id)
            ->and($log->actor_id)->toBe((string) $this->user->id)
            ->and($log->subject_id)->toBe($goal->id)
            ->and(json_encode($log->metadata))->not->toContain('sensitive-match-value');
    }
});

it('records site and group grants including revocations through member settings', function (bool $groupGrant) {
    $member = User::factory()->create();
    $membership = $this->org->addMember($member, Role::Viewer, 'granted_only');
    $group = ProjectGroup::create(['organization_id' => $this->org->id, 'name' => 'Editors']);
    $this->actingAs($this->user)->put('/organization/members/'.$membership->id, [
        'role' => 'viewer', 'project_access' => 'granted_only',
        'grants' => [['project_id' => $groupGrant ? null : $this->site->id, 'group_id' => $groupGrant ? $group->id : null, 'role' => 'viewer']],
    ])->assertSessionHasNoErrors();
    $grant = ProjectGrant::where('user_id', $member->id)->sole();
    $grant->update(['role' => Role::Analyst]);
    $this->delete('/organization/members/'.$membership->id)->assertRedirect();
    foreach (['project_grant.created', 'project_grant.updated', 'project_grant.deleted'] as $action) {
        $log = AuditLog::where('action', $action)->sole();
        expect($log->organization_id)->toBe($this->org->id)
            ->and($log->actor_id)->toBe((string) $this->user->id)
            ->and($log->metadata['user_id'])->toBe($member->id)
            ->and($log->metadata['group_id'])->toBe($groupGrant ? $group->id : null)
            ->and($log->metadata['project_id'])->toBe($groupGrant ? null : $this->site->id);
    }
})->with([true, false]);

it('records owner promotion and demotion through member settings', function () {
    $member = User::factory()->create();
    $membership = $this->org->addMember($member, Role::Admin);
    $this->actingAs($this->user);
    foreach (['owner' => 'owner.granted', 'admin' => 'owner.revoked'] as $role => $action) {
        $this->put('/organization/members/'.$membership->id, ['role' => $role, 'project_access' => 'all'])->assertSessionHasNoErrors();
        $log = AuditLog::where('action', $action)->where('subject_id', $membership->id)->sole();
        expect($log->actor_id)->toBe((string) $this->user->id)
            ->and($log->metadata['to'])->toBe($role);
    }
});

it('records embed setting changes without logging secrets or usage writes', function () {
    [$key, $secret] = EmbedTokens::key($this->org);
    $this->actingAs($this->user);
    $key->forceFill(['last_used_at' => now()])->save();
    expect(AuditLog::where('action', 'embed.key.updated')->count())->toBe(0);
    $key->update(['allowed_origins' => ['https://new.example.test'], 'widgets' => ['kpi'], 'max_ttl_seconds' => 300]);
    $key->save();
    $log = AuditLog::where('action', 'embed.key.updated')->sole();
    expect($log->actor_id)->toBe((string) $this->user->id)
        ->and($log->metadata['changes'])->toEqualCanonicalizing(['allowed_origins', 'widgets', 'max_ttl_seconds'])
        ->and(json_encode($log->metadata))->not->toContain($secret, 'secret');
});

it('attributes API and MCP requests to the key even with a signed in session', function (string $surface, string $type) {
    [$key, $token] = ApiKey::issue([
        'organization_id' => $this->org->id, 'user_id' => $type === 'pat' ? $this->user->id : null,
        'name' => 'Integration', 'type' => $type, 'scopes' => ['analytics:read'],
    ]);
    $this->actingAs($this->user);
    if ($surface === 'api') {
        $this->getJson('/api/v1/sites', ['Authorization' => 'Bearer '.$token])->assertOk();
    } else {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_sites', 'arguments' => []]], [
            'Authorization' => 'Bearer '.$token, 'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18',
        ])->assertOk();
    }
    $log = AuditLog::where('action', $surface.'.used')->sole();
    expect($log->actor_type)->toBe('api_key')->and($log->actor_id)->toBe($key->id)
        ->and($log->metadata['api_key_name'])->toBe('Integration')
        ->and(json_encode($log->metadata))->not->toContain($token, $key->token_hash);
})->with([['api', 'pat'], ['api', 'org'], ['mcp', 'pat'], ['mcp', 'org']]);

it('attributes embed mint and revoke events to an API key even after usage was recorded', function () {
    [$key, $token] = ApiKey::issue(['organization_id' => $this->org->id, 'name' => 'Issuer', 'type' => 'org', 'scopes' => ['embed:mint'], 'last_used_at' => now()]);
    [$embedKey, $secret] = EmbedTokens::key($this->org);
    $headers = ['Authorization' => 'Bearer '.$token];
    $response = $this->postJson('/api/v1/embed/tokens', ['key_id' => $embedKey->public_id, 'sites' => [$this->site->id]], $headers)->assertOk();
    $this->postJson('/api/v1/embed/tokens/'.$response->json('jti').'/revoke', [], $headers)->assertOk();
    foreach (['embed.token_minted', 'embed.token_revoked'] as $action) {
        $log = AuditLog::where('action', $action)->sole();
        expect($log->actor_type)->toBe('api_key')->and($log->actor_id)->toBe($key->id)
            ->and($log->metadata['api_key_name'])->toBe('Issuer')
            ->and(json_encode($log->metadata))->not->toContain($secret, $token, $response->json('token'));
    }
});

it('attributes commands to Console with no session user or IP', function () {
    $kernel = app(Kernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->setArtisan(null);
    $this->actingAs($this->user);
    $this->artisan('ryvr:account:create', ['email' => 'new@example.test', 'name' => 'New member', 'organization' => 'New team'])->assertSuccessful();
    $log = AuditLog::where('action', 'organization.created')->sole();
    expect($log->actor_type)->toBe('console')->and($log->actor_id)->toBeNull()->and($log->ip_address)->toBeNull();
    expect(AuditLog::record('test.after_command')->actor_type)->toBe('user');
});

class RecordAuditTestJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $organizationId, public bool $fail = false) {}

    public function handle(): void
    {
        AuditLog::record('test.queued', $this->organizationId);
        if ($this->fail) {
            throw new RuntimeException('Job failed');
        }
    }
}

it('attributes queued work to System and resets attribution after completion or failure', function (bool $fail) {
    $this->actingAs($this->user);
    try {
        Bus::dispatchSync(new RecordAuditTestJob($this->org->id, $fail));
    } catch (RuntimeException $exception) {
        expect($fail)->toBeTrue();
    }
    $log = AuditLog::where('action', 'test.queued')->sole();
    expect($log->actor_type)->toBe('system')->and($log->actor_id)->toBeNull()->and($log->ip_address)->toBeNull();
    expect(AuditLog::record('test.after_job')->actor_type)->toBe('user');
    Auth::logout();
    expect(AuditLog::record('test.background')->actor_type)->toBe('system');
})->with([true, false]);

it('records failed two factor sign ins and waits for a successful challenge before signing in', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->org->addMember($user, Role::Viewer);
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    expect(AuditLog::where('action', 'auth.signed_in')->count())->toBe(0);
    $this->mock(TwoFactorAuthenticationProvider::class)->shouldReceive('verify')->twice()->andReturn(false, true);
    $this->postJson('/two-factor-challenge', ['code' => '111111'])->assertUnprocessable();
    $this->postJson('/two-factor-challenge', ['code' => '222222'])->assertSuccessful();
    expect(AuditLog::where('action', 'auth.sign_in_failed')->sole()->subject_id)->toBe((string) $user->id)
        ->and(AuditLog::where('action', 'auth.signed_in')->sole()->actor_id)->toBe((string) $user->id);
});

it('records two factor enabling immediately when confirmation is disabled', function () {
    config(['fortify-options.two-factor-authentication.confirm' => false]);
    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/user/two-factor-authentication')->assertSuccessful();
    expect(AuditLog::where('action', 'two_factor.enabled')->sole()->actor_id)->toBe((string) $this->user->id);
});

it('records API and MCP usage separately and throttles repeated reads on each surface', function () {
    [$key, $token] = ApiKey::issue(['organization_id' => $this->org->id, 'name' => 'Reader', 'type' => 'org', 'scopes' => ['analytics:read']]);
    $headers = ['Authorization' => 'Bearer '.$token];
    foreach (range(1, 3) as $request) {
        $this->getJson('/api/v1/sites', $headers)->assertOk();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $headers + [
            'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18',
        ])->assertOk();
    }
    foreach (['api.used', 'mcp.used'] as $action) {
        expect(AuditLog::where('action', $action)->sole()->actor_id)->toBe($key->id);
    }
});

it('does not repeat grant events when unchanged access is saved and records role changes as updates', function () {
    $member = User::factory()->create();
    $membership = $this->org->addMember($member, Role::Viewer, 'granted_only');
    $group = ProjectGroup::create(['organization_id' => $this->org->id, 'name' => 'Editors']);
    $data = ['role' => 'viewer', 'project_access' => 'granted_only', 'grants' => [['group_id' => $group->id, 'role' => 'viewer']]];
    $this->actingAs($this->user);
    foreach (range(1, 2) as $request) {
        $this->put('/organization/members/'.$membership->id, $data)->assertSessionHasNoErrors();
    }
    expect(AuditLog::where('action', 'project_grant.created')->count())->toBe(1)
        ->and(AuditLog::whereIn('action', ['project_grant.updated', 'project_grant.deleted'])->count())->toBe(0);
    $data['grants'][0]['role'] = 'analyst';
    $this->put('/organization/members/'.$membership->id, $data)->assertSessionHasNoErrors();
    expect(AuditLog::where('action', 'project_grant.updated')->sole()->metadata['role'])->toBe('analyst')
        ->and(AuditLog::where('action', 'project_grant.deleted')->count())->toBe(0);
    $this->put('/organization/members/'.$membership->id, ['role' => 'viewer', 'project_access' => 'all'])->assertSessionHasNoErrors();
    expect(AuditLog::where('action', 'project_grant.deleted')->count())->toBe(1);
});

it('records grant revocations when a group or site is deleted', function (bool $groupGrant) {
    $member = User::factory()->create();
    $this->org->addMember($member, Role::Viewer, 'granted_only');
    $group = ProjectGroup::create(['organization_id' => $this->org->id, 'name' => 'Editors']);
    ProjectGrant::create([
        'organization_id' => $this->org->id, 'user_id' => $member->id, 'role' => Role::Viewer,
        'project_id' => $groupGrant ? null : $this->site->id, 'project_group_id' => $groupGrant ? $group->id : null,
    ]);
    $this->actingAs($this->user);
    if ($groupGrant) {
        $this->delete('/organization/groups/'.$group->id)->assertRedirect();
    } else {
        $this->delete('/sites/'.$this->site->id, ['confirm' => $this->site->domain])->assertRedirect();
    }
    expect(AuditLog::where('action', 'project_grant.deleted')->sole()->actor_id)->toBe((string) $this->user->id);
})->with([true, false]);

it('records owner removal when an owner leaves or deletes their account', function (bool $deleteAccount) {
    $otherOwner = User::factory()->create();
    $this->org->addMember($otherOwner, Role::Owner);
    $membership = Membership::where('organization_id', $this->org->id)->where('user_id', $this->user->id)->sole();
    $this->actingAs($this->user);
    if ($deleteAccount) {
        $this->delete('/settings/profile', ['password' => 'password'])->assertRedirect();
    } else {
        $this->delete('/organization/members/'.$membership->id)->assertRedirect();
    }
    $log = AuditLog::where('action', 'owner.revoked')->where('subject_id', $membership->id)->sole();
    expect($log->actor_id)->toBe((string) $this->user->id)
        ->and($log->metadata['subject_name'])->toContain($this->user->email);
})->with([true, false]);

it('records site group assignment changes without repeating unchanged or foreign assignments', function () {
    $group = ProjectGroup::create(['organization_id' => $this->org->id, 'name' => 'Editors']);
    $foreign = ProjectGroup::create(['organization_id' => $this->other->id, 'name' => 'Other group']);
    $data = [
        'name' => $this->site->name, 'domain' => $this->site->domain, 'timezone' => 'UTC', 'environment' => 'production',
        'groups' => [$group->id, $foreign->id],
    ];
    $this->actingAs($this->user);
    foreach (range(1, 2) as $request) {
        $this->put('/sites/'.$this->site->id.'/settings', $data)->assertSessionHasNoErrors();
    }
    $log = AuditLog::where('action', 'project.groups_updated')->sole();
    expect($log->actor_id)->toBe((string) $this->user->id)
        ->and($log->organization_id)->toBe($this->org->id)
        ->and($log->metadata['added'])->toBe([$group->id])
        ->and($log->metadata['removed'])->toBe([]);
    $data['groups'] = [];
    $this->put('/sites/'.$this->site->id.'/settings', $data)->assertSessionHasNoErrors();
    $logs = AuditLog::where('action', 'project.groups_updated')->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->last()->metadata['removed'])->toBe([$group->id])
        ->and($logs->last()->metadata['added'])->toBe([])
        ->and($logs->toJson())->not->toContain($foreign->id);
});

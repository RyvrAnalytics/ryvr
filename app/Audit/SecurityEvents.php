<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

final class SecurityEvents
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, $this->login(...));
        $events->listen(Logout::class, $this->logout(...));
        $events->listen(Failed::class, $this->failed(...));
        $events->listen(TwoFactorAuthenticationEnabled::class, $this->twoFactorEnabled(...));
        $events->listen(TwoFactorAuthenticationConfirmed::class, $this->twoFactorConfirmed(...));
        $events->listen(TwoFactorAuthenticationDisabled::class, $this->twoFactorDisabled(...));
        $events->listen(TwoFactorAuthenticationFailed::class, $this->twoFactorFailed(...));
        $events->listen(PasskeyRegistered::class, $this->passkeyAdded(...));
        $events->listen(PasskeyDeleted::class, $this->passkeyRemoved(...));
    }

    public function login(Login $event): void
    {
        if ($event->user instanceof User) {
            self::record('auth.signed_in', $event->user);
        }
    }

    public function logout(Logout $event): void
    {
        if ($event->user instanceof User) {
            self::record('auth.signed_out', $event->user);
        }
    }

    public function failed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;
        if (! is_string($email)) {
            return;
        }

        $user = User::whereRaw('lower(email) = ?', [strtolower($email)])->whereHas('memberships')->first();
        if ($user) {
            $this->failedFor($user);
        }
    }

    private function failedFor(User $user): void
    {
        // One entry per account per minute across all source addresses.
        if (Cache::add('audit:failed-login:'.$user->id, true, 60)) {
            self::record('auth.sign_in_failed', $user, authenticated: false);
        }
    }

    public function twoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->failedFor($event->user);
    }

    public function twoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        if (! Fortify::confirmsTwoFactorAuthentication()) {
            self::record('two_factor.enabled', $event->user);
        }
    }

    public function twoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        self::record('two_factor.enabled', $event->user);
    }

    public function twoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        self::record('two_factor.disabled', $event->user);
    }

    public function passkeyAdded(PasskeyRegistered $event): void
    {
        if ($event->user instanceof User) {
            self::record('passkey.added', $event->user, ['passkey_id' => $event->passkey->id, 'name' => $event->passkey->name]);
        }
    }

    public function passkeyRemoved(PasskeyDeleted $event): void
    {
        if ($event->user instanceof User) {
            self::record('passkey.removed', $event->user, ['passkey_id' => $event->passkey->id, 'name' => $event->passkey->name]);
        }
    }

    /** @param array<string, mixed> $metadata */
    public static function record(string $action, User $user, array $metadata = [], bool $authenticated = true): void
    {
        foreach ($user->memberships()->pluck('organization_id') as $organizationId) {
            AuditLog::record($action, $organizationId, $user, $metadata, $authenticated ? 'user' : 'system', $authenticated ? (string) $user->id : null);
        }
    }
}

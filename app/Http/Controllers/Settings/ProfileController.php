<?php

namespace App\Http\Controllers\Settings;

use App\Analytics\ClickHouse\ClickHouse;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, ClickHouse $clickhouse): RedirectResponse
    {
        $user = $request->user();

        // Never leave an organization without an owner. Where the user is the only owner:
        // others still belong -> stop and ask for a new owner; nobody else -> remove it too.
        $soleOwned = Membership::where('user_id', $user->id)->where('role', Role::Owner->value)->get()
            ->filter(fn (Membership $m) => Membership::where('organization_id', $m->organization_id)->where('role', Role::Owner->value)->count() === 1);

        $blocking = $soleOwned->filter(fn (Membership $m) => Membership::where('organization_id', $m->organization_id)->count() > 1);
        if ($blocking->isNotEmpty()) {
            $names = Organization::whereIn('id', $blocking->pluck('organization_id'))->pluck('name')->join(', ');

            throw ValidationException::withMessages([
                'password' => "You are the only owner of {$names}. Make another member an owner first.",
            ]);
        }

        foreach (Organization::whereIn('id', $soleOwned->pluck('organization_id'))->get() as $organization) {
            $clickhouse->statement('DELETE FROM events WHERE organization_id = {o:UUID}', ['o' => $organization->id]);
            $projectIds = $organization->projects()->pluck('id')->all();
            if ($projectIds !== []) {
                $clickhouse->statement('DELETE FROM ingest_drops WHERE project_id IN {p:Array(UUID)}', ['p' => $projectIds]);
            }
            AuditLog::record('organization.deleted', null, $organization, ['name' => $organization->name, 'reason' => 'owner deleted account']);
            $organization->delete();
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}

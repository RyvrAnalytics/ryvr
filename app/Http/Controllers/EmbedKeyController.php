<?php

namespace App\Http\Controllers;

use App\Embed\Origins;
use App\Enums\Capability;
use App\Models\AuditLog;
use App\Models\EmbedKey;
use App\Models\Project;
use App\Tenancy\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organization settings: embed keys. Owners and admins create, rotate and revoke keys; the
 * secret is shown once, right after creation.
 */
class EmbedKeyController extends Controller
{
    public function index(Access $access): Response
    {
        abort_unless($access->can(Capability::ManageEmbeds), 403);

        $keys = EmbedKey::with('creator:id,name')
            ->where('organization_id', $access->organization->id)
            ->active()
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('organization/Embedding', [
            'keys' => $keys->map(fn (EmbedKey $k) => [
                'id' => $k->id,
                'public_id' => $k->public_id,
                'name' => $k->name,
                'preview' => EmbedKey::SECRET_PREFIX.'…'.$k->last_four,
                'allowed_origins' => $k->allowed_origins,
                'projects' => $k->project_ids,
                'widgets' => $k->widgets,
                'max_ttl_seconds' => $k->max_ttl_seconds,
                'creator' => $k->creator?->name,
                'last_used_at' => $k->last_used_at?->toIso8601String(),
                'created_at' => $k->created_at?->toIso8601String(),
                'manageable' => $access->covers($k->project_ids),
            ]),
            'projects' => $access->projects()->map->only(['id', 'name']),
            'canCreateUnlimited' => $access->isOrgWide(),
            'widgets' => EmbedKey::WIDGETS,
            'ttlChoices' => EmbedKey::TTL_CHOICES,
            'embedUrl' => url('/embed/v1'),
            'mintUrl' => url('/api/v1/embed/tokens'),
            'scriptUrl' => url('/embed.js'),
        ]);
    }

    public function store(Request $request, Access $access): RedirectResponse
    {
        abort_unless($access->can(Capability::ManageEmbeds), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'allowed_origins' => ['required', 'array', 'min:1', 'max:20'],
            'allowed_origins.*' => ['required', 'string', 'max:255'],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['uuid'],
            'widgets' => ['required', 'array', 'min:1'],
            'widgets.*' => [Rule::in(EmbedKey::WIDGETS)],
            'max_ttl_seconds' => ['required', 'integer', Rule::in(EmbedKey::TTL_CHOICES)],
        ]);

        $origins = [];
        foreach ($data['allowed_origins'] as $origin) {
            $normalized = Origins::normalize((string) $origin);
            if ($normalized === null) {
                throw ValidationException::withMessages([
                    'allowed_origins' => "\"{$origin}\" is not an allowed origin. Use https://host, https://*.host, or http://localhost:port for development.",
                ]);
            }
            $origins[] = $normalized;
        }

        $active = EmbedKey::where('organization_id', $access->organization->id)->active()->count();
        if ($active >= (int) config('ryvr.embed.max_active_keys', 20)) {
            throw ValidationException::withMessages(['name' => 'This organization has too many active embed keys. Revoke one first.']);
        }

        // The key's site ceiling never exceeds the creator's sites, and "no ceiling" (every site,
        // now and later) is only for members who can read every site.
        $requested = isset($data['project_ids']) && $data['project_ids'] !== [] ? array_values(array_unique($data['project_ids'])) : null;
        if (! $access->covers($requested)) {
            abort(403, $requested === null
                ? 'Only members with access to all sites can create an embed key without a site limit. Choose sites.'
                : 'You can only limit an embed key to sites you can access.');
        }

        $projectIds = $requested !== null
            ? Project::where('organization_id', $access->organization->id)->whereIn('id', $requested)->pluck('id')->all()
            : null;

        [$key, $plain] = EmbedKey::issue([
            'organization_id' => $access->organization->id,
            'name' => $data['name'],
            'allowed_origins' => array_values(array_unique($origins)),
            'project_ids' => $projectIds,
            'widgets' => array_values(array_intersect(EmbedKey::WIDGETS, $data['widgets'])),
            'max_ttl_seconds' => (int) $data['max_ttl_seconds'],
            'created_by' => $access->user?->id,
        ]);

        AuditLog::record('embed.key.created', $access->organization->id, $key, ['kid' => $key->public_id, 'origins' => $key->allowed_origins]);

        return back()
            ->with('newEmbedKey', ['id' => $key->public_id, 'secret' => $plain])
            ->with('toast', ['type' => 'success', 'message' => 'Embed key created. Copy the secret now: it is shown only once.']);
    }

    /**
     * Create a copy of a key with a new secret. The old key keeps working until it is revoked,
     * so deployed servers can switch over without downtime.
     */
    public function rotate(Access $access, string $key): RedirectResponse
    {
        abort_unless($access->can(Capability::ManageEmbeds), 403);
        $old = EmbedKey::where('organization_id', $access->organization->id)->active()->findOrFail($key);
        // Rotating hands out a new signing secret with the same ceiling.
        abort_unless($access->covers($old->project_ids), 403, 'This key reaches sites you cannot access.');

        [$new, $plain] = EmbedKey::issue([
            'organization_id' => $old->organization_id,
            'name' => $old->name,
            'allowed_origins' => $old->allowed_origins,
            'project_ids' => $old->project_ids,
            'widgets' => $old->widgets,
            'max_ttl_seconds' => $old->max_ttl_seconds,
            'max_period_days' => $old->max_period_days,
            'rate_limit_per_minute' => $old->rate_limit_per_minute,
            'created_by' => $access->user?->id,
        ]);

        AuditLog::record('embed.key.rotated', $access->organization->id, $new, ['from' => $old->public_id, 'kid' => $new->public_id]);

        return back()
            ->with('newEmbedKey', ['id' => $new->public_id, 'secret' => $plain])
            ->with('toast', ['type' => 'success', 'message' => 'New key created. Deploy it, then revoke the old one.']);
    }

    public function destroy(Access $access, string $key): RedirectResponse
    {
        abort_unless($access->can(Capability::ManageEmbeds), 403);
        $key = EmbedKey::where('organization_id', $access->organization->id)->active()->findOrFail($key);
        abort_unless($access->covers($key->project_ids), 403, 'This key reaches sites you cannot access.');

        $key->forceFill(['revoked_at' => now()])->save();
        AuditLog::record('embed.key.revoked', $access->organization->id, $key, ['kid' => $key->public_id]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Embed key revoked. Its tokens stop working now.']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\Capability;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Project;
use App\Tenancy\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    public function index(Access $access): Response
    {
        $organization = $access->organization;
        $canManage = $access->can(Capability::ManageApiKeys);

        $keys = ApiKey::with('user:id,name')
            ->where('organization_id', $organization->id)
            ->whereNull('revoked_at')
            // Members see their own personal tokens; admins also see organization keys and everyone's tokens.
            ->when(! $canManage, fn ($q) => $q->where('user_id', $access->user?->id))
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('organization/ApiKeys', [
            'keys' => $keys->map(fn (ApiKey $k) => [
                'id' => $k->id,
                'name' => $k->name,
                'type' => $k->type,
                'owner' => $k->user?->name,
                'preview' => ($k->type === 'org' ? 'ryvr_org_' : 'ryvr_pat_').'…'.$k->last_four,
                'scopes' => $k->scopes,
                'projects' => $k->project_ids,
                'last_used_at' => $k->last_used_at?->toIso8601String(),
                'expires_at' => $k->expires_at?->toIso8601String(),
                'created_at' => $k->created_at?->toIso8601String(),
                'mine' => $k->user_id === $access->user?->id,
                'revocable' => $k->user_id === $access->user?->id || ($canManage && $access->covers($k->project_ids)),
            ]),
            'projects' => $access->projects()->map->only(['id', 'name']),
            'scopes' => ApiKey::SCOPES,
            'canUseApi' => $access->can(Capability::UseApi),
            'canManage' => $canManage,
            'canCreateOrgWide' => $access->isOrgWide(),
            'mcpUrl' => url('/mcp'),
            'apiUrl' => url('/api/v1'),
        ]);
    }

    public function store(Request $request, Access $access): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['pat', 'org'])],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(ApiKey::SCOPES)],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['uuid'],
            'expires_in_days' => ['nullable', 'integer', Rule::in([30, 90, 365])],
        ]);

        abort_unless($data['type'] === 'org' ? $access->can(Capability::ManageApiKeys) : $access->can(Capability::UseApi), 403);

        $requested = isset($data['project_ids']) && $data['project_ids'] !== [] ? array_values(array_unique($data['project_ids'])) : null;

        // A key never reaches further than its creator. A personal token is also bounded by its
        // owner at request time; an organization key is not, so a site-limited admin must list
        // sites, all of them their own.
        if ($requested !== null) {
            abort_unless($access->covers($requested), 403, 'You can only limit a key to sites you can access.');
        } elseif ($data['type'] === 'org' && ! $access->isOrgWide()) {
            abort(403, 'Only members with access to all sites can create an organization key for every site. Choose sites.');
        }

        $projectIds = $requested !== null
            ? Project::where('organization_id', $access->organization->id)->whereIn('id', $requested)->pluck('id')->all()
            : null;

        [$key, $plain] = ApiKey::issue([
            'organization_id' => $access->organization->id,
            'user_id' => $data['type'] === 'pat' ? $access->user?->id : null,
            'name' => $data['name'],
            'type' => $data['type'],
            'scopes' => array_values(array_unique($data['scopes'])),
            'project_ids' => $projectIds,
            'expires_at' => isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
        ]);

        AuditLog::record('api_key.created', $access->organization->id, $key, ['type' => $key->type, 'scopes' => $key->scopes]);

        return back()->with('newToken', $plain)->with('toast', ['type' => 'success', 'message' => 'Key created. Copy it now: it is shown only once.']);
    }

    public function destroy(Access $access, string $key): RedirectResponse
    {
        $key = ApiKey::where('organization_id', $access->organization->id)->findOrFail($key);
        abort_unless($key->user_id === $access->user?->id || ($access->can(Capability::ManageApiKeys) && $access->covers($key->project_ids)), 403);

        $key->forceFill(['revoked_at' => now()])->save();
        AuditLog::record('api_key.revoked', $access->organization->id, $key);

        return back()->with('toast', ['type' => 'success', 'message' => 'Key revoked. Requests using it now fail.']);
    }
}

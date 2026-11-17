<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First run for someone without an organization: name it and add the first site.
 * Also used to create additional organizations.
 */
class OnboardingController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user()->currentOrganization() && ! $request->boolean('new')) {
            return redirect()->route('sites.index');
        }

        return Inertia::render('onboarding/Start', [
            'timezones' => \DateTimeZone::listIdentifiers(),
            'additional' => $request->user()->organizations()->exists(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization' => ['required', 'string', 'max:100'],
            'timezone' => ['required', 'timezone:all'],
            'site_name' => ['nullable', 'required_with:domain', 'string', 'max:100'],
            'domain' => ['nullable', 'string', 'max:255', 'regex:/^(https?:\/\/)?[a-z0-9.-]+\.[a-z]{2,}(:\d+)?\/?$/i'],
        ]);

        $user = $request->user();

        $project = DB::transaction(function () use ($data, $user) {
            $organization = Organization::create([
                'name' => $data['organization'],
                'slug' => Organization::uniqueSlug($data['organization']),
                'timezone' => $data['timezone'],
            ]);
            $organization->addMember($user, Role::Owner);
            $user->switchOrganization($organization);
            AuditLog::record('organization.created', $organization->id, $organization);

            if (empty($data['domain'])) {
                return null;
            }

            $project = Project::create([
                'organization_id' => $organization->id,
                'name' => $data['site_name'],
                'domain' => Project::normalizeHost($data['domain']),
                'timezone' => $data['timezone'],
            ]);
            AuditLog::record('project.created', $organization->id, $project, ['domain' => $project->domain]);

            return $project;
        });

        return $project
            ? redirect()->route('sites.show', $project)
            : redirect()->route('sites.index');
    }
}

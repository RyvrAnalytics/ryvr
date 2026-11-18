<?php

namespace App\Http\Controllers;

use App\Analytics\UsageReport;
use App\Enums\Capability;
use App\Tenancy\Access;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class UsageController extends Controller
{
    public function index(Access $access, UsageReport $report): Response
    {
        abort_unless($access->can(Capability::ViewUsage), 403);

        return Inertia::render('organization/Usage', ['usage' => $report->forOrganization($access->organization)]);
    }

    public function api(Access $access, UsageReport $report): JsonResponse
    {
        $key = $access->apiKey();
        abort_unless($key?->type === 'org', 403, 'An organization key is required.');

        return response()->json(['data' => $report->forOrganization($access->organization, $key->project_ids)]);
    }
}

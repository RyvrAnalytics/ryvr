<?php

namespace App\Http\Controllers;

use App\Audit\ActionLabels;
use App\Audit\LogPresenter;
use App\Enums\Capability;
use App\Models\AuditLog;
use App\Tenancy\Access;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __invoke(Request $request, Access $access): Response
    {
        abort_unless($access->can(Capability::ViewAuditLog), 403);

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'actor' => ['nullable', 'string', 'regex:/^(user(:[0-9]+)?|(api_key|embed_key)(:[a-fA-F0-9-]+)?|console|system)$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
        ]);
        $organizationId = $access->organization->id;
        $timezone = $access->organization->timezone;
        $query = AuditLog::where('organization_id', $organizationId);
        $actions = (clone $query)->distinct()->orderBy('action')->pluck('action');
        $actorIds = (clone $query)->selectRaw('MAX(id) as id')->groupBy('actor_type', 'actor_id');
        $actors = AuditLog::where('organization_id', $organizationId)->whereIn('id', $actorIds)->get();

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (! empty($filters['actor'])) {
            [$type, $id] = array_pad(explode(':', $filters['actor'], 2), 2, null);
            $query->whereIn('actor_type', $type === 'api_key' ? ['api_key', 'mcp_client'] : [$type]);
            if ($id !== null) {
                $query->where('actor_id', $id);
            } elseif (! in_array($type, ['system', 'console'], true)) {
                $query->whereNull('actor_id');
            }
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['to'], $timezone)->addDay()->startOfDay()->utc());
        }

        $logs = $query->orderByDesc('id')->paginate(50)->withQueryString();
        $presenter = new LogPresenter($organizationId, collect($logs->items())->merge($actors));

        return Inertia::render('organization/Audit', [
            'logs' => $logs->through(fn (AuditLog $log) => $presenter->entry($log, $timezone)),
            'filters' => collect(['action', 'actor', 'from', 'to'])->mapWithKeys(fn ($key) => [$key => $filters[$key] ?? ''])->all(),
            'actions' => $actions->map(fn (string $action) => ['value' => $action, 'label' => ActionLabels::for($action)]),
            'actors' => $actors->map(function (AuditLog $log) use ($presenter) {
                $actor = $presenter->actor($log);

                return [
                    'value' => $actor['type'].($log->actor_id !== null && ! in_array($actor['type'], ['system', 'console'], true) ? ':'.$log->actor_id : ''),
                    'label' => $actor['name'].($actor['email'] ? ' ('.$actor['email'].')' : '').($actor['type'] === 'api_key' ? ' (API key)' : ''),
                ];
            })->unique('value')->sortBy('label')->values(),
            'timezone' => $timezone,
        ]);
    }
}

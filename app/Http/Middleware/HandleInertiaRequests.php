<?php

namespace App\Http\Middleware;

use App\Enums\Capability;
use App\Tenancy\Access;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'email', 'email_verified_at', 'is_instance_admin']),
            ],
            // Resolved lazily: the organization middleware runs after this one.
            'organization' => fn () => ($access = $request->attributes->get('access')) instanceof Access ? [
                'id' => $access->organization->id,
                'name' => $access->organization->name,
                'slug' => $access->organization->slug,
                'timezone' => $access->organization->timezone,
                'role' => $access->role()->value,
                'can' => collect(Capability::cases())
                    ->mapWithKeys(fn (Capability $c) => [$c->value => $access->can($c)])
                    ->all(),
            ] : null,
            'organizations' => fn () => $request->user()?->organizations->map->only(['id', 'name', 'slug'])->values() ?? [],
        ];
    }
}

<?php

namespace App\Http\Middleware;

use App\Analytics\Analytics;
use App\Enums\Capability;
use App\Support\Promo;
use App\Tenancy\Access;
use Illuminate\Http\Request;
use Inertia\Inertia;
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
        // Controllers flash with redirect()->with(); hand those to Inertia's flash channel.
        if ($request->hasSession() && ($toast = $request->session()->get('toast'))) {
            Inertia::flash('toast', $toast);
        }

        // Optional frame in the right column, shared only when configured with a valid URL.
        $promo = Promo::frame();

        return [
            ...parent::share($request),
            ...($promo ? ['promo' => ['url' => $promo['url'], 'height' => $promo['height']]] : []),
            // One-time secrets (new API token, invitation link) shown right after creation.
            'once' => fn () => $request->hasSession() ? array_filter([
                'newToken' => $request->session()->get('newToken'),
                'invitationLink' => $request->session()->get('invitationLink'),
                'newEmbedKey' => $request->session()->get('newEmbedKey'),
            ]) : [],
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
            // Every site the member can read, for the navigation rail, with visitors online now.
            'rail' => function () use ($request) {
                $access = $request->attributes->get('access');
                if (! $access instanceof Access) {
                    return [];
                }
                $projects = $access->projects();
                try {
                    $live = app(Analytics::class)->realtime(array_values(array_map(fn ($p) => (string) $p->id, $projects->all())));
                } catch (\Throwable) {
                    $live = [];
                }

                return $projects->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'domain' => $p->domain, 'live' => $live[$p->id] ?? 0])->values()->all();
            },
            'organizations' => fn () => $request->user()?->organizations->map->only(['id', 'name', 'slug'])->values() ?? [],
        ];
    }
}

<?php

namespace App\Providers;

use App\Analytics\ClickHouse\ClickHouse;
use App\Ingestion\ConfigSnapshot;
use App\Ingestion\Enrichment\Geo;
use App\Ingestion\Queue\IngestQueue;
use App\Ingestion\Queue\RedisStreamQueue;
use App\Models\ExclusionRule;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClickHouse::class, fn () => new ClickHouse(config('ryvr.clickhouse')));
        $this->app->singleton(IngestQueue::class, RedisStreamQueue::class);
        $this->app->singleton(Geo::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Behind a reverse proxy (the bundled Caddy, a load balancer), trust its forwarded
        // headers so visitor IPs and HTTPS are seen correctly.
        $proxies = config('ryvr.trusted_proxies');
        if (is_string($proxies) && $proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // External collectors LISTEN for this to refetch the ingestion config right away.
        $notify = fn () => DB::afterCommit(fn () => ConfigSnapshot::notifyChanged());
        foreach ([Project::class, ExclusionRule::class] as $model) {
            $model::saved($notify);
            $model::deleted($notify);
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

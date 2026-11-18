<?php

namespace App\Providers;

use App\Audit\ActorResolver;
use App\Audit\ModelEvents;
use App\Audit\SecurityEvents;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActorResolver::class);
    }

    public function boot(): void
    {
        Event::listen(CommandStarting::class, fn () => app(ActorResolver::class)->startingCommand());
        Event::listen(CommandFinished::class, fn () => app(ActorResolver::class)->finishedCommand());
        Event::listen(JobProcessing::class, fn () => app(ActorResolver::class)->startingJob());
        Event::listen(JobProcessed::class, fn () => app(ActorResolver::class)->finishedJob());
        Event::listen(JobExceptionOccurred::class, fn () => app(ActorResolver::class)->finishedJob());
        Event::subscribe(SecurityEvents::class);
        ModelEvents::register();
    }
}

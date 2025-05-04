<?php

namespace Exxtensio\LimiterExtension;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            LimiterService::class,
            fn($app) => new LimiterService($app->make(\Illuminate\Contracts\Cache\Repository::class))
        );
    }

    public function boot(): void
    {
        //
    }
}

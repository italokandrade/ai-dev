<?php

namespace App\Providers;

use App\Ai\Providers\FailoverProvider;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\Prism\PrismGateway;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Ai::extend('failover', function ($app, array $config) {
            return new FailoverProvider(
                new PrismGateway($app['events']),
                $config,
                $app['events']
            );
        });
    }
}

<?php

namespace App\Providers;

use App\Ai\Providers\FailoverProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\Prism\PrismGateway;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Ai::extend('failover', function ($app, array $config) {
            return new FailoverProvider(new PrismGateway($app['events']), $config, $app['events']);
        });
        
        // Auditores globais removidos para estabilizar o sistema e o chat
    }
}

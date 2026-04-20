<?php

namespace App\Providers;

use App\Ai\Providers\FailoverProvider;
use App\Models\ToolCallLog;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\ToolInvoked;
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

        // Log tool calls for audit
        Event::listen(ToolInvoked::class, function (ToolInvoked $event) {
            ToolCallLog::create([
                'invocation_id' => $event->invocationId,
                'agent_class' => get_class($event->agent),
                'tool_class' => get_class($event->tool),
                'arguments' => $event->arguments,
                'result' => is_string($event->result) ? $event->result : json_encode($event->result),
            ]);
        });
    }
}

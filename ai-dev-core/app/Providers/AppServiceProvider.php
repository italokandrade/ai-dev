<?php

namespace App\Providers;

use App\Services\LLMGateway;
use App\Tools\FileTool;
use App\Tools\GitTool;
use App\Tools\ShellTool;
use App\Tools\ToolRouter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ToolRouter::class, function () {
            $router = new ToolRouter();
            $router->register(new ShellTool());
            $router->register(new FileTool());
            $router->register(new GitTool());

            return $router;
        });

        $this->app->singleton(LLMGateway::class);
    }

    public function boot(): void
    {
        //
    }
}

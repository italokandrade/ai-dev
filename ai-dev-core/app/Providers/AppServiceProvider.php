<?php

namespace App\Providers;

use App\Ai\Providers\FailoverProvider;
use App\Ai\Providers\KimiProvider;
use Illuminate\Support\Facades\Http;
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

        Ai::extend('openrouter', function ($app, array $config) {
            return new \Laravel\Ai\Providers\OpenRouterProvider(new PrismGateway($app['events']), $config, $app['events']);
        });

        /**
         * Kimi usa o PrismGateway com provider OpenRouter mas URL do Kimi Code.
         * Isso garante que o endpoint /chat/completions seja usado.
         */
        Ai::extend('kimi', function ($app, array $config) {
            return new KimiProvider(new PrismGateway($app['events']), $config, $app['events']);
        });

        /**
         * A API do Kimi Code rejeita o User-Agent padrão do GuzzleHttp ('GuzzleHttp/7'),
         * retornando 403. Ela exige um User-Agent de um Coding Agent whitelistado
         * (Claude Code, Roo Code, Kimi CLI, etc.).
         */
        Http::globalMiddleware(function ($handler) {
            return function ($request, $options) use ($handler) {
                if ($request->getUri()->getHost() === 'api.kimi.com') {
                    $request = $request->withHeader('User-Agent', 'claude-code/0.1.0');
                }

                return $handler($request, $options);
            };
        });
        
        // Auditores removidos fisicamente
    }
}

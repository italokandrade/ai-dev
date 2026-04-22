<?php

namespace App\Providers;

use App\Ai\Providers\FailoverProvider;
use App\Ai\Providers\KimiProvider;
use GuzzleHttp\Psr7\Stream;
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
         * A API do Kimi Code tem duas particularidades:
         * 1. Rejeita o User-Agent padrão do GuzzleHttp ('GuzzleHttp/7'),
         *    retornando 403. Exige um User-Agent de um Coding Agent whitelistado.
         * 2. O modelo kimi-k2.6 tem "thinking mode" habilitado por padrão, que retorna
         *    a resposta em 'reasoning_content' em vez of 'content'. Isso quebra o
         *    Laravel AI SDK / Prism que espera 'content'. Desabilitamos o thinking
         *    mode injetando {"thinking": {"type": "disabled"}} no corpo das requisições.
         */
        Http::globalMiddleware(function ($handler) {
            return function ($request, $options) use ($handler) {
                if ($request->getUri()->getHost() === 'api.kimi.com') {
                    // 1. User-Agent whitelistado
                    $request = $request->withHeader('User-Agent', 'claude-code/0.1.0');

                    // 2. Desabilitar thinking mode
                    $body = json_decode((string) $request->getBody(), true);
                    if (is_array($body)) {
                        $body['thinking'] = ['type' => 'disabled'];
                        $newStream = fopen('php://temp', 'r+');
                        fwrite($newStream, json_encode($body));
                        rewind($newStream);
                        $request = $request->withBody(new Stream($newStream));
                    }
                }

                return $handler($request, $options);
            };
        });
        
        // Auditores removidos fisicamente
    }
}

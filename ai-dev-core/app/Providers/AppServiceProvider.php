<?php

namespace App\Providers;

use App\Ai\Providers\FailoverProvider;
use App\Ai\Providers\KimiProvider;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\Prism\PrismGateway;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Cache estático para armazenar reasoning_content por tool_call_id.
     * O Kimi K2.6 exige que o reasoning_content original seja reenviado
     * nas mensagens de assistant que contêm tool_calls.
     */
    private static array $reasoningCache = [];

    public function register(): void {}

    public function boot(): void
    {
        Ai::extend('failover', function ($app, array $config) {
            return new FailoverProvider(new PrismGateway($app['events']), $config, $app['events']);
        });

        Ai::extend('openrouter', function ($app, array $config) {
            return new \Laravel\Ai\Providers\OpenRouterProvider(new PrismGateway($app['events']), $config, $app['events']);
        });

        Ai::extend('kimi', function ($app, array $config) {
            return new KimiProvider(new PrismGateway($app['events']), $config, $app['events']);
        });

        Http::globalMiddleware(function ($handler) {
            return function ($request, $options) use ($handler) {
                if ($request->getUri()->getHost() === 'api.kimi.com') {
                    // 1. User-Agent whitelistado (exigido pela API do Kimi Code)
                    $request = $request->withHeader('User-Agent', 'claude-code/0.1.0');

                    // 2. Gerenciar reasoning_content para compatibilidade com Kimi K2.6
                    $body = json_decode((string) $request->getBody(), true);
                    if (is_array($body) && isset($body['messages'])) {
                        foreach ($body['messages'] as &$message) {
                            if (($message['role'] ?? '') === 'assistant' && isset($message['tool_calls'])) {
                                foreach ($message['tool_calls'] as $toolCall) {
                                    $toolCallId = $toolCall['id'] ?? null;
                                    if ($toolCallId && isset(self::$reasoningCache[$toolCallId])) {
                                        $message['reasoning_content'] = self::$reasoningCache[$toolCallId];
                                        break;
                                    }
                                }
                                if (! array_key_exists('reasoning_content', $message)) {
                                    $message['reasoning_content'] = '';
                                }
                            }
                        }
                        $newStream = fopen('php://temp', 'r+');
                        fwrite($newStream, json_encode($body));
                        rewind($newStream);
                        $request = $request->withBody(new Stream($newStream));
                    }
                }

                $promise = $handler($request, $options);

                return $promise->then(function ($response) use ($request) {
                    if ($request->getUri()->getHost() === 'api.kimi.com') {
                        $bodyStream = $response->getBody();
                        $bodyCopy = Utils::streamFor((string) $bodyStream);
                        $body = json_decode((string) $bodyCopy, true);
                        
                        if (is_array($body) && isset($body['choices'][0]['message'])) {
                            $msg = $body['choices'][0]['message'];
                            if (isset($msg['tool_calls']) && isset($msg['reasoning_content'])) {
                                foreach ($msg['tool_calls'] as $toolCall) {
                                    $toolCallId = $toolCall['id'] ?? null;
                                    if ($toolCallId) {
                                        self::$reasoningCache[$toolCallId] = $msg['reasoning_content'];
                                    }
                                }
                            }
                        }
                    }
                    return $response;
                });
            };
        });
    }
}

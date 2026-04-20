<?php

namespace App\Ai\Providers;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Throwable;

class FailoverProvider extends Provider implements TextProvider
{
    /**
     * Invoke the given agent, trying each provider in order.
     */
    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        $providers = $this->config['providers'] ?? [];
        $lastException = null;

        foreach ($providers as $providerName) {
            try {
                Log::info("FailoverProvider: Trying provider '{$providerName}'");
                $subProvider = Ai::textProvider($providerName);

                // Create a new AgentPrompt for the sub-provider using its default model
                $newPrompt = new AgentPrompt(
                    $prompt->agent,
                    $prompt->prompt,
                    $prompt->attachments,
                    $subProvider,
                    $subProvider->defaultTextModel(),
                    $prompt->timeout
                );

                $response = $subProvider->prompt($newPrompt);

                Log::info("FailoverProvider: Success with '{$providerName}'");

                return $response;
            } catch (Throwable $e) {
                Log::warning("FailoverProvider: Provider '{$providerName}' failed: ".$e->getMessage());
                $lastException = $e;

                continue; // Try the next provider in the chain
            }
        }

        throw $lastException ?: new \RuntimeException('No providers succeeded in the failover chain.');
    }

    /**
     * Stream the response from the given agent, trying each provider in order.
     */
    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        $providers = $this->config['providers'] ?? [];
        $lastException = null;

        foreach ($providers as $providerName) {
            try {
                $subProvider = Ai::textProvider($providerName);

                // Create a new AgentPrompt for the sub-provider using its default model
                $newPrompt = new AgentPrompt(
                    $prompt->agent,
                    $prompt->prompt,
                    $prompt->attachments,
                    $subProvider,
                    $subProvider->defaultTextModel(),
                    $prompt->timeout
                );

                return $subProvider->stream($newPrompt);
            } catch (Throwable $e) {
                $lastException = $e;

                continue;
            }
        }

        throw $lastException ?: new \RuntimeException('No providers succeeded in the failover chain.');
    }

    /**
     * Get the provider's text gateway (required by interface, but we delegate to sub-providers).
     */
    public function textGateway(): TextGateway
    {
        $firstProvider = $this->config['providers'][0] ?? 'openai';

        return Ai::textProvider($firstProvider)->textGateway();
    }

    /**
     * Set the provider's text gateway.
     */
    public function useTextGateway(TextGateway $gateway): self
    {
        return $this;
    }

    public function defaultTextModel(): string
    {
        $firstProvider = $this->config['providers'][0] ?? 'openai';

        return Ai::textProvider($firstProvider)->defaultTextModel();
    }

    public function cheapestTextModel(): string
    {
        $firstProvider = $this->config['providers'][0] ?? 'openai';

        return Ai::textProvider($firstProvider)->cheapestTextModel();
    }

    public function smartestTextModel(): string
    {
        $firstProvider = $this->config['providers'][0] ?? 'openai';

        return Ai::textProvider($firstProvider)->smartestTextModel();
    }
}

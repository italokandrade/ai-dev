<?php

namespace App\Ai\Providers;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\StreamsText;

/**
 * Provider compatível com OpenAI para a API da Moonshot (Kimi).
 *
 * Usa o PrismGateway em vez do OpenAiGateway nativo do Laravel AI SDK,
 * garantindo que chamadas vão para /chat/completions (endpoint compatível
 * com OpenAI) em vez de /responses (endpoint novo da OpenAI que a Moonshot
 * não suporta).
 */
class KimiProvider extends Provider implements TextProvider
{
    use GeneratesText;
    use HasTextGateway;
    use StreamsText;

    /**
     * Força o driver a ser 'openrouter' para que o PrismGateway mapeie
     * corretamente para PrismProvider::OpenRouter.
     *
     * O provider OpenAI do Prism v0.100+ usa o endpoint /responses (API nova
     * da OpenAI) que a Moonshot não suporta. O OpenRouter usa /chat/completions,
     * que é compatível com a Moonshot.
     */
    public function driver(): string
    {
        return 'openrouter';
    }

    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'kimi-for-coding';
    }

    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'kimi-for-coding';
    }

    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'kimi-for-coding';
    }
}

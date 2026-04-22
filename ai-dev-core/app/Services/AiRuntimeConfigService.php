<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Ai;

/**
 * Resolve as configurações de IA (provider, model, key) a partir do SystemSetting
 * e aplica-as em runtime no Laravel AI SDK.
 *
 * O usuário configura os providers pela UI (SystemSettingsPage) e este service
 * garante que os Jobs e Agentes usem EXATAMENTE essas configurações,
 * eliminando qualquer hardcoding de provider/model/key.
 */
class AiRuntimeConfigService
{
    /**
     * Níveis de IA configuráveis via SystemSettingsPage.
     */
    public const LEVEL_PREMIUM = 'premium';
    public const LEVEL_HIGH    = 'high';
    public const LEVEL_FAST    = 'fast';
    public const LEVEL_SYSTEM  = 'system';

    /**
     * Retorna o provider, model e key configurados no banco para o nível dado.
     *
     * @return array{provider: string, model: string, key: string|null}
     */
    public static function resolve(string $level): array
    {
        $provider = SystemSetting::get("ai_{$level}_provider", 'openrouter');
        $model    = SystemSetting::get("ai_{$level}_model", self::defaultModelFor($provider));
        $key      = SystemSetting::get("ai_{$level}_key");

        return [
            'provider' => $provider,
            'model'    => $model,
            'key'      => $key,
        ];
    }

    /**
     * Aplica a API key do SystemSetting na configuração runtime do Laravel AI SDK.
     * Deve ser chamado ANTES de instanciar qualquer agente que vá fazer uma chamada LLM.
     */
    public static function apply(string $level): array
    {
        $config = self::resolve($level);

        // Injeta a key no config runtime para que o Laravel AI SDK a encontre
        if ($config['key']) {
            Config::set("ai.providers.{$config['provider']}.key", $config['key']);
        }

        // Limpa o cache do provider no AiManager para garantir que a nova key
        // seja usada na próxima instanciação
        Ai::forgetInstance($config['provider']);

        return $config;
    }

    /**
     * Retorna o model default quando não há configuração salva no banco.
     */
    private static function defaultModelFor(string $provider): string
    {
        return match ($provider) {
            'openrouter' => 'anthropic/claude-sonnet-4-6',
            'anthropic'  => 'claude-sonnet-4-6',
            'openai'     => 'gpt-4o',
            'kimi'       => 'kimi-k2.6',
            'ollama'     => 'qwen2.5:0.5b',
            default      => 'anthropic/claude-sonnet-4-6',
        };
    }
}

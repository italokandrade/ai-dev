<?php

namespace App\Filament\Widgets;

use App\Ai\Agents\SystemAssistantAgent;
use App\Models\SystemSetting;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Log;

class DashboardChat extends Widget
{
    protected string $view = 'filament.widgets.dashboard-chat';
    protected int|string|array $columnSpan = 'full';
    public string $message = '';
    public array $history = [];
    public bool $isProcessing = false;

    public function mount()
    {
        $this->history[] = [
            'role' => 'assistant',
            'content' => 'Olá! Eu sou o Assistente Inteligente do AI-Dev. Estou configurado com as credenciais de "IA do Sistema". Como posso ajudar?'
        ];
    }

    public function sendMessage()
    {
        if (empty(trim($this->message))) return;

        $userMessage = $this->message;
        $this->history[] = ['role' => 'user', 'content' => $userMessage];
        $this->message = '';
        $this->isProcessing = true;

        try {
            // Puxa EXCLUSIVAMENTE as configurações de "IA DO SISTEMA" salvas no banco
            $provider = SystemSetting::get(SystemSetting::AI_SYSTEM_PROVIDER, 'openrouter');
            $model = SystemSetting::get(SystemSetting::AI_SYSTEM_MODEL, 'anthropic/claude-3.5-sonnet');
            $apiKey = SystemSetting::get(SystemSetting::AI_SYSTEM_KEY);

            // Se não houver chave no banco para este nível, usa o .env como último recurso
            if (empty($apiKey)) {
                $apiKey = env('OPENROUTER_API_KEY');
            }

            // Criar instância do agente passando o path do projeto para o Booster
            $agent = new SystemAssistantAgent(base_path());
            
            // Executar o prompt com os parâmetros dinâmicos do banco
            $response = $agent->prompt($userMessage, [
                'provider' => $provider,
                'model' => $model,
                'api_key' => $apiKey,
            ]);

            $this->history[] = [
                'role' => 'assistant',
                'content' => (string) $response
            ];
        } catch (\Throwable $e) {
            Log::error("DashboardChat Error: " . $e->getMessage());
            
            $errorMsg = $e->getMessage();
            if (str_contains($errorMsg, '404')) {
                $errorMsg = "Erro 404: O modelo '" . ($model ?? 'vazio') . "' não foi encontrado no provider '{$provider}'. Verifique o nome técnico em Configurações.";
            }

            $this->history[] = [
                'role' => 'assistant',
                'content' => 'Lamento, tive um problema técnico: ' . $errorMsg
            ];
        }

        $this->isProcessing = false;
        $this->dispatch('scroll-chat');
    }

    public function clearChat()
    {
        $this->history = [];
        $this->mount();
        $this->dispatch('scroll-chat');
    }
}

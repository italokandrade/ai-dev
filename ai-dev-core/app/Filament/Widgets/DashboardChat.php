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
            'content' => 'Olá! Eu sou o Assistente Inteligente do AI-Dev. Tenho acesso ao código e à documentação deste sistema para te ajudar. Como posso ser útil?'
        ];
    }

    public function sendMessage()
    {
        if (empty(trim($this->message))) return;

        $userMessage = $this->message;
        $this->history[] = ['role' => 'user', 'content' => $userMessage];
        $this->message = '';
        $this->isProcessing = true;

        $this->dispatch('scroll-chat');

        try {
            // Pegar configurações da "IA do Sistema"
            $provider = SystemSetting::get(SystemSetting::AI_SYSTEM_PROVIDER, 'openrouter');
            $model = SystemSetting::get(SystemSetting::AI_SYSTEM_MODEL, 'anthropic/claude-sonnet-4-6');
            $apiKey = SystemSetting::get(SystemSetting::AI_SYSTEM_KEY);

            // Criar instância do agente
            $agent = new SystemAssistantAgent(base_path());
            
            // Realizar o prompt passando as opções de conexão
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
            $this->history[] = [
                'role' => 'assistant',
                'content' => 'Lamento, tive um problema técnico ao investigar sua pergunta. Erro: ' . $e->getMessage()
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

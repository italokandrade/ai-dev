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
            // Pegar configurações da "IA do Sistema" do banco de dados
            $provider = SystemSetting::get(SystemSetting::AI_SYSTEM_PROVIDER, 'openrouter');
            $model = SystemSetting::get(SystemSetting::AI_SYSTEM_MODEL, 'anthropic/claude-3.5-sonnet');
            $apiKey = SystemSetting::get(SystemSetting::AI_SYSTEM_KEY);

            // Se não houver chave no banco, tenta pegar do .env como fallback
            if (empty($apiKey)) {
                $apiKey = env('OPENROUTER_API_KEY');
            }

            // Criar instância do agente
            $agent = new SystemAssistantAgent(base_path());
            
            // Realizar o prompt passando as opções de conexão completas
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
            
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, '400')) {
                $errorMessage = "Erro 400 (Bad Request): Verifique se o modelo '{$model}' é válido no provider '{$provider}' e se a API Key está correta.";
            }

            $this->history[] = [
                'role' => 'assistant',
                'content' => 'Lamento, tive um problema técnico: ' . $errorMessage
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

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
            'content' => 'Olá! Sistema pronto. Como posso ajudar?'
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
            $apiKey = SystemSetting::get(SystemSetting::AI_SYSTEM_KEY) ?: env('OPENROUTER_API_KEY');
            $model = SystemSetting::get(SystemSetting::AI_SYSTEM_MODEL, 'anthropic/claude-3.5-sonnet');
            
            $agent = new SystemAssistantAgent();
            
            // Força o provider 'openrouter' com as credenciais diretas
            $response = $agent->prompt($userMessage, [
                'provider' => 'openrouter',
                'model' => $model,
                'api_key' => $apiKey,
            ]);

            $this->history[] = [
                'role' => 'assistant',
                'content' => (string) $response
            ];
        } catch (\Throwable $e) {
            $this->history[] = [
                'role' => 'assistant',
                'content' => 'Erro de conexão: ' . $e->getMessage()
            ];
        }

        $this->isProcessing = false;
        $this->dispatch('scroll-chat');
    }

    public function clearChat()
    {
        $this->history = [];
        $this->mount();
    }
}

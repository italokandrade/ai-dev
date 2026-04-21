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
            'content' => 'Olá! Chat em modo simplificado para diagnóstico. Como posso ajudar?'
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
            $provider = SystemSetting::get(SystemSetting::AI_SYSTEM_PROVIDER, 'openrouter');
            $model = SystemSetting::get(SystemSetting::AI_SYSTEM_MODEL, 'anthropic/claude-3.5-sonnet');
            $apiKey = SystemSetting::get(SystemSetting::AI_SYSTEM_KEY);

            if (empty($apiKey)) {
                $apiKey = env('OPENROUTER_API_KEY');
            }

            // Agente sem ferramentas e sem path para isolamento
            $agent = new SystemAssistantAgent();
            
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
            $this->history[] = [
                'role' => 'assistant',
                'content' => 'Lamento, erro: ' . $e->getMessage()
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

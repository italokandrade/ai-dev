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
            'role'    => 'assistant',
            'content' => 'Olá! Sou o Assistente do AI-Dev. Posso te ajudar com informações sobre projetos, tarefas, módulos e como usar o sistema. Como posso ser útil?'
        ];
    }

    public function sendMessage()
    {
        if (empty(trim($this->message))) return;

        $userMessage = $this->message;
        $this->history[] = ['role' => 'user', 'content' => $userMessage];
        $this->message    = '';
        $this->isProcessing = true;

        try {
            $provider = SystemSetting::get(SystemSetting::AI_SYSTEM_PROVIDER, 'openrouter');
            $model    = SystemSetting::get(SystemSetting::AI_SYSTEM_MODEL, 'anthropic/claude-haiku-4-5-20251001');

            $agent = new SystemAssistantAgent();

            // Laravel AI SDK: prompt(string $prompt, ?string $provider = null, ?string $model = null)
            $response = $agent->prompt(
                prompt: $userMessage,
                provider: $provider,
                model: $model,
            );

            $this->history[] = [
                'role'    => 'assistant',
                'content' => (string) $response,
            ];
        } catch (\Throwable $e) {
            Log::error('DashboardChat Error: ' . $e->getMessage());
            $this->history[] = [
                'role'    => 'assistant',
                'content' => 'Desculpe, ocorreu um erro ao processar sua mensagem. Tente novamente em instantes.',
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

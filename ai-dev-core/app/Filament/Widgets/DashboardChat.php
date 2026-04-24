<?php

namespace App\Filament\Widgets;

use App\Ai\Agents\SystemAssistantAgent;
use App\Services\AiRuntimeConfigService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class DashboardChat extends Widget
{
    protected string $view = 'filament.widgets.dashboard-chat';

    protected int|string|array $columnSpan = 'full';

    public string $message = '';

    public array $history = [];

    public bool $isProcessing = false;

    /**
     * Chave de sessão usada para persistir o histórico do chat
     * entre navegações de página.
     */
    private const SESSION_KEY = 'dashboard_chat_history';

    /**
     * Limita o histórico mantido na sessão para não inflar
     * o payload do agente indefinidamente (últimas N trocas).
     */
    private const MAX_HISTORY = 40; // 20 pares pergunta/resposta

    private const MAX_MESSAGE_LENGTH = 4000;

    public static function canView(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isAdmin() || $user?->can('View:DashboardChat'));
    }

    public function mount(): void
    {
        // Recupera o histórico da sessão se existir
        $saved = Session::get(self::SESSION_KEY, []);

        if (! empty($saved)) {
            $this->history = $saved;
        } else {
            $this->history[] = [
                'role' => 'assistant',
                'content' => 'Olá! Sou o Assistente do AI-Dev. Posso te ajudar com informações sobre projetos, tarefas, módulos e como usar o sistema. Como posso ser útil?',
            ];
            $this->saveHistory();
        }
    }

    public function sendMessage(string $messageText = ''): void
    {
        // Recebe a mensagem diretamente do Alpine (x-model local, sem wire:model)
        $userMessage = trim($messageText ?: $this->message);

        if (empty($userMessage)) {
            return;
        }

        if (mb_strlen($userMessage) > self::MAX_MESSAGE_LENGTH) {
            $this->history[] = [
                'role' => 'assistant',
                'content' => 'Sua mensagem está muito longa. Envie uma pergunta mais objetiva para eu processar com segurança.',
            ];
            $this->message = '';
            $this->saveHistory();
            $this->dispatch('scroll-chat');

            return;
        }

        $this->history[] = ['role' => 'user', 'content' => $userMessage];
        $this->message = '';
        $this->isProcessing = true;

        try {
            $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_SYSTEM);

            $agent = new SystemAssistantAgent(base_path());

            $response = $agent->prompt(
                prompt: $userMessage,
                provider: $aiConfig['provider'],
                model: $aiConfig['model'],
            );

            $responseText = (string) $response;

            $this->history[] = [
                'role' => 'assistant',
                'content' => $responseText,
            ];

            activity()
                ->event('dashboard_chat_message')
                ->causedBy(auth()->user())
                ->withProperties(['widget' => static::class])
                ->log('Chat do dashboard utilizado');
        } catch (\Throwable $e) {
            Log::error('DashboardChat Error: '.$e->getMessage());
            $this->history[] = [
                'role' => 'assistant',
                'content' => 'Desculpe, ocorreu um erro ao processar sua mensagem. Tente novamente em instantes.',
            ];
        }

        // Limita tamanho e persiste
        if (count($this->history) > self::MAX_HISTORY) {
            // Mantém sempre a primeira mensagem (boas-vindas) e as últimas N-1
            $first = array_shift($this->history);
            $this->history = array_slice($this->history, -(self::MAX_HISTORY - 1));
            array_unshift($this->history, $first);
        }

        $this->isProcessing = false;
        $this->saveHistory();
        $this->dispatch('scroll-chat');
    }

    public function clearChat(): void
    {
        Session::forget(self::SESSION_KEY);
        $this->history = [];
        $this->mount();
        activity()
            ->event('dashboard_chat_clear')
            ->causedBy(auth()->user())
            ->withProperties(['widget' => static::class])
            ->log('Histórico do chat do dashboard limpo');
        $this->dispatch('scroll-chat');
    }

    private function saveHistory(): void
    {
        Session::put(self::SESSION_KEY, $this->history);
    }
}

<?php

namespace App\Services;

use App\Models\AgentConfig;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LLMGateway
{
    private const PROXY_URLS = [
        'gemini' => 'http://127.0.0.1:8001',
        'anthropic' => 'http://127.0.0.1:8002',
    ];

    /**
     * Envia uma mensagem para o LLM via proxy.
     *
     * O session_id é OBRIGATORIAMENTE resolvido a partir do projeto:
     * - Provider gemini → usa project.gemini_session_id
     * - Provider anthropic → usa project.claude_session_id
     *
     * Isso garante que TODA chamada mantém o contexto persistente da IA por projeto.
     * Se o proxy retornar um novo session_id, ele é salvo de volta no projeto.
     */
    public function chat(
        AgentConfig $agent,
        string $userMessage,
        ?string $systemPrompt = null,
        ?Project $project = null,
        ?string $taskId = null,
        ?string $subtaskId = null,
    ): LLMResponse {
        $startTime = microtime(true);

        $provider = $agent->provider->value;
        $baseUrl = self::PROXY_URLS[$provider] ?? self::PROXY_URLS['gemini'];

        // Resolver session_id a partir do projeto (OBRIGATÓRIO)
        $sessionId = $this->resolveSessionId($provider, $project);

        $messages = [];
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        try {
            $response = Http::timeout(300)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Session-Id' => $sessionId ?? '',
                ])
                ->post("{$baseUrl}/v1/chat/completions", [
                    'model' => $agent->model,
                    'messages' => $messages,
                    'temperature' => $agent->temperature,
                    'max_tokens' => $agent->max_tokens,
                    'session_id' => $sessionId,
                ]);

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $data = $response->json();

            if (! $response->successful()) {
                return $this->buildErrorResponse(
                    agent: $agent,
                    error: $data['error']['message'] ?? 'HTTP ' . $response->status(),
                    latencyMs: $latencyMs,
                    taskId: $taskId,
                    subtaskId: $subtaskId,
                    sessionId: $sessionId,
                );
            }

            $content = $data['choices'][0]['message']['content'] ?? '';
            $usedModel = $data['model'] ?? $agent->model;
            $usedSessionId = $data['session_id'] ?? $sessionId;

            // Persistir session_id de volta no projeto para manter contexto entre chamadas
            $this->persistSessionId($provider, $project, $usedSessionId);

            $usage = $data['usage'] ?? [];

            return new LLMResponse(
                success: true,
                content: $content,
                model: $usedModel,
                provider: $provider,
                sessionId: $usedSessionId,
                promptTokens: $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0,
                completionTokens: $usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0,
                latencyMs: $latencyMs,
                toolCalls: $this->extractToolCalls($content),
            );
        } catch (\Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            Log::error("LLMGateway error [{$agent->id}]: {$e->getMessage()}");

            return $this->buildErrorResponse(
                agent: $agent,
                error: $e->getMessage(),
                latencyMs: $latencyMs,
                taskId: $taskId,
                subtaskId: $subtaskId,
                sessionId: $sessionId,
            );
        }
    }

    /**
     * Extract tool calls from LLM response content.
     * The LLM returns tool calls as JSON blocks in the text.
     */
    private function extractToolCalls(string $content): array
    {
        $toolCalls = [];

        // Match ```json blocks that contain tool_name/action patterns
        if (preg_match_all('/```json\s*(\{[^`]+\})\s*```/s', $content, $matches)) {
            foreach ($matches[1] as $jsonBlock) {
                $decoded = json_decode($jsonBlock, true);
                if ($decoded && isset($decoded['tool_name'])) {
                    $toolCalls[] = $decoded;
                }
            }
        }

        // Also match inline JSON tool calls
        if (preg_match_all('/\{"tool_name"\s*:\s*"[^"]+".+?\}/s', $content, $matches)) {
            foreach ($matches[0] as $jsonStr) {
                $decoded = json_decode($jsonStr, true);
                if ($decoded && isset($decoded['tool_name'])) {
                    $toolCalls[] = $decoded;
                }
            }
        }

        return $toolCalls;
    }

    /**
     * Resolve o session_id correto a partir do projeto e provider.
     */
    private function resolveSessionId(string $provider, ?Project $project): ?string
    {
        if (! $project) {
            return null;
        }

        return match ($provider) {
            'gemini' => $project->gemini_session_id,
            'anthropic' => $project->claude_session_id,
            default => null,
        };
    }

    /**
     * Persiste o session_id retornado pelo proxy de volta no projeto.
     * Garante que chamadas futuras usem o mesmo contexto da IA.
     */
    private function persistSessionId(string $provider, ?Project $project, ?string $sessionId): void
    {
        if (! $project || ! $sessionId) {
            return;
        }

        $column = match ($provider) {
            'gemini' => 'gemini_session_id',
            'anthropic' => 'claude_session_id',
            default => null,
        };

        if ($column && $project->{$column} !== $sessionId) {
            $project->update([$column => $sessionId]);
        }
    }

    private function buildErrorResponse(
        AgentConfig $agent,
        string $error,
        int $latencyMs,
        ?string $taskId,
        ?string $subtaskId,
        ?string $sessionId,
    ): LLMResponse {
        return new LLMResponse(
            success: false,
            content: '',
            model: $agent->model,
            provider: $agent->provider->value,
            sessionId: $sessionId,
            promptTokens: 0,
            completionTokens: 0,
            latencyMs: $latencyMs,
            toolCalls: [],
            error: $error,
        );
    }
}

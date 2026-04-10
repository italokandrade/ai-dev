<?php

namespace App\Services;

class LLMResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $content,
        public readonly string $model,
        public readonly string $provider,
        public readonly ?string $sessionId,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $latencyMs,
        public readonly array $toolCalls = [],
        public readonly ?string $error = null,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function hasToolCalls(): bool
    {
        return ! empty($this->toolCalls);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'content' => $this->content,
            'model' => $this->model,
            'provider' => $this->provider,
            'session_id' => $this->sessionId,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens(),
            'latency_ms' => $this->latencyMs,
            'tool_calls' => $this->toolCalls,
            'error' => $this->error,
        ];
    }
}

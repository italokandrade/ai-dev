<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AgentProvider: string implements HasLabel
{
    case Gemini = 'gemini';
    case Anthropic = 'anthropic';
    case Kimi = 'kimi';
    case Ollama = 'ollama';

    public function getLabel(): string
    {
        return match ($this) {
            self::Gemini => 'Google Gemini',
            self::Anthropic => 'Anthropic Claude',
            self::Kimi => 'Kimi (Moonshot AI)',
            self::Ollama => 'Ollama (Local)',
        };
    }

    public function defaultModel(): string
    {
        return match ($this) {
            self::Gemini => 'gemini-3.1-flash-lite-preview',
            self::Anthropic => 'claude-sonnet-4-6',
            self::Kimi => 'moonshot-v1-8k',
            self::Ollama => 'qwen2.5:0.5b',
        };
    }
}

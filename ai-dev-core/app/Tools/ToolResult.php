<?php

namespace App\Tools;

class ToolResult
{
    public function __construct(
        public readonly bool $success,
        public readonly mixed $output,
        public readonly ?string $error = null,
        public readonly int $executionTimeMs = 0,
        public readonly bool $securityFlag = false,
    ) {}

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'output' => $this->output,
            'error' => $this->error,
            'execution_time_ms' => $this->executionTimeMs,
            'security_flag' => $this->securityFlag,
        ];
    }

    public static function ok(mixed $output, int $executionTimeMs = 0): self
    {
        return new self(success: true, output: $output, executionTimeMs: $executionTimeMs);
    }

    public static function fail(string $error, int $executionTimeMs = 0, bool $securityFlag = false): self
    {
        return new self(
            success: false,
            output: null,
            error: $error,
            executionTimeMs: $executionTimeMs,
            securityFlag: $securityFlag,
        );
    }
}

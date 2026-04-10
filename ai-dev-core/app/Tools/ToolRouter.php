<?php

namespace App\Tools;

use App\Contracts\ToolInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ToolRouter
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    public function getTool(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Generate the tools manifest for injection into LLM prompts.
     */
    public function getToolsManifest(): array
    {
        return array_map(fn (ToolInterface $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'input_schema' => $tool->inputSchema(),
        ], array_values($this->tools));
    }

    /**
     * Route and execute a tool call from the LLM.
     */
    public function dispatch(array $toolCall): ToolResult
    {
        $toolName = $toolCall['tool_name'] ?? null;
        $params = $toolCall['parameters'] ?? $toolCall['params'] ?? [];

        if (! $toolName) {
            return ToolResult::fail('Missing tool_name in tool call');
        }

        $tool = $this->getTool($toolName);
        if (! $tool) {
            return ToolResult::fail("Unknown tool: {$toolName}. Available: " . implode(', ', array_keys($this->tools)));
        }

        // Validate input params against schema
        $validationError = $this->validateParams($params, $tool->inputSchema());
        if ($validationError) {
            return ToolResult::fail("Validation error for {$toolName}: {$validationError}");
        }

        try {
            $startTime = microtime(true);
            $result = $tool->execute($params);
            $elapsed = (int) ((microtime(true) - $startTime) * 1000);

            $action = $params['action'] ?? 'execute';
            Log::info("ToolRouter: {$toolName}.{$action}", [
                'success' => $result->success,
                'execution_time_ms' => $elapsed,
                'security_flag' => $result->securityFlag,
            ]);

            return new ToolResult(
                success: $result->success,
                output: $result->output,
                error: $result->error,
                executionTimeMs: $elapsed,
                securityFlag: $result->securityFlag,
            );
        } catch (\Throwable $e) {
            Log::error("ToolRouter: {$toolName} threw exception", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::fail("Tool {$toolName} threw exception: {$e->getMessage()}");
        }
    }

    private function validateParams(array $params, array $schema): ?string
    {
        $required = $schema['required'] ?? [];
        foreach ($required as $field) {
            if (! array_key_exists($field, $params)) {
                return "Missing required field: {$field}";
            }
        }

        $properties = $schema['properties'] ?? [];
        foreach ($params as $key => $value) {
            if (isset($properties[$key]['enum']) && ! in_array($value, $properties[$key]['enum'])) {
                return "Invalid value for {$key}: {$value}. Allowed: " . implode(', ', $properties[$key]['enum']);
            }
        }

        return null;
    }
}

<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Boost\Mcp\Tools\BrowserLogs;
use Laravel\Boost\Mcp\Tools\DatabaseQuery;
use Laravel\Boost\Mcp\Tools\DatabaseSchema;
use Laravel\Boost\Mcp\Tools\LastError;
use Laravel\Boost\Mcp\Tools\SearchDocs;
use Laravel\Mcp\Response;
use Stringable;

class BoostTool implements Tool
{
    public function __construct(
        private readonly ?string $workingDirectory = null
    ) {}

    public function description(): Stringable|string
    {
        return 'Access Laravel Boost MCP tools for documentation search, database schema/queries, and logs. This tool provides specialized knowledge about the TALL stack (Tailwind, Alpine, Livewire, Laravel).';
    }

    public function handle(Request $request): Stringable|string
    {
        $toolName = $request['tool'];
        $arguments = $request['arguments'] ?? [];

        if (! $this->workingDirectory || ! is_dir($this->workingDirectory)) {
            return json_encode([
                'success' => false,
                'error' => "Working directory '{$this->workingDirectory}' not found or not provided.",
            ]);
        }

        try {
            // Map tool names to artisan command names
            $command = "boost:{$toolName}";
            
            // Build arguments string
            $argsString = '';
            foreach ($arguments as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $item) {
                        $argsString .= " --{$key}=\"" . addslashes($item) . "\"";
                    }
                } else {
                    $argsString .= " --{$key}=\"" . addslashes($value) . "\"";
                }
            }

            $process = \Illuminate\Support\Facades\Process::path($this->workingDirectory)
                ->timeout(60)
                ->run("php artisan {$command} {$argsString}");

            if ($process->failed()) {
                return json_encode([
                    'success' => false,
                    'error' => "Boost tool '{$toolName}' failed: " . $process->error(),
                    'output' => $process->output(),
                ]);
            }

            return $process->output();
        } catch (\Exception $e) {
            return json_encode([
                'success' => false,
                'error' => "Failed to execute Boost tool '{$toolName}': " . $e->getMessage(),
            ]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool' => $schema->string()
                ->description('The Boost tool to execute. Options: search-docs, database-schema, database-query, browser-logs, last-error.')
                ->required(),
            'arguments' => $schema->object([
                'queries' => $schema->array()->items($schema->string())->description('Queries for search-docs (array of strings)')->required(),
                'table' => $schema->string()->description('Table name for database-schema')->required(),
                'query' => $schema->string()->description('SQL query for database-query')->required(),
                'limit' => $schema->integer()->description('Limit for logs or query results')->required(),
            ])->description('Arguments for the selected tool. For search-docs, use {"queries": ["..."]}. For database-schema, use {"table": "..."}.')
                ->required(),
        ];
    }
}

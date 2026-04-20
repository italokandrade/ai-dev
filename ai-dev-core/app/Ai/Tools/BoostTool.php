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
    /**
     * Map of tool names to their respective Boost MCP tool classes.
     */
    private array $toolMap = [
        'search-docs' => SearchDocs::class,
        'database-schema' => DatabaseSchema::class,
        'database-query' => DatabaseQuery::class,
        'browser-logs' => BrowserLogs::class,
        'last-error' => LastError::class,
    ];

    public function description(): Stringable|string
    {
        return 'Access Laravel Boost MCP tools for documentation search, database schema/queries, and logs. This tool provides specialized knowledge about the TALL stack (Tailwind, Alpine, Livewire, Laravel).';
    }

    public function handle(Request $request): Stringable|string
    {
        $toolName = $request['tool'];
        $arguments = $request['arguments'] ?? [];

        if (! isset($this->toolMap[$toolName])) {
            return json_encode([
                'success' => false,
                'error' => "Tool '{$toolName}' not found. Available tools: ".implode(', ', array_keys($this->toolMap)),
            ]);
        }

        try {
            $toolClass = $this->toolMap[$toolName];
            $toolInstance = app($toolClass);

            // The Boost MCP tools expect a Laravel\Mcp\Request object
            $mcpRequest = new \Laravel\Mcp\Request($arguments);
            $result = $toolInstance->handle($mcpRequest);

            // Handle Response objects
            if ($result instanceof Response) {
                return (string) $result->content();
            }

            // Handle Generators (for streaming results)
            if ($result instanceof \Generator) {
                $output = '';
                foreach ($result as $part) {
                    if ($part instanceof Response) {
                        $output .= (string) $part->content();
                    } else {
                        $output .= (string) $part;
                    }
                }

                return $output;
            }

            return is_string($result) ? $result : json_encode($result);
        } catch (\Exception $e) {
            return json_encode([
                'success' => false,
                'error' => "Failed to execute Boost tool '{$toolName}': ".$e->getMessage(),
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

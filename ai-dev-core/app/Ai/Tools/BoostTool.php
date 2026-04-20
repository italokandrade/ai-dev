<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class BoostTool implements Tool
{
    /**
     * Tables allowed for database-query.
     */
    private const array TABLE_ALLOWLIST = [
        'users', 'projects', 'tasks', 'subtasks', 'project_specifications', 
        'project_modules', 'project_quotations', 'social_accounts', 'system_settings'
    ];

    /**
     * Sensitive suffixes to redact from query results.
     */
    private const array SENSITIVE_SUFFIXES = ['_token', '_secret', '_password', '_key', '_hash'];

    /**
     * Allowed operators for where clauses.
     */
    private const array OPERATOR_ALLOWLIST = ['=', '>', '<', '>=', '<=', 'LIKE', 'ILIKE', 'IN', 'IS NULL', 'IS NOT NULL'];

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

        if ($toolName === 'database-query') {
            return $this->handleDatabaseQuery($arguments);
        }

        return $this->executeArtisanCommand($toolName, $arguments);
    }

    /**
     * Securely handles database queries with allowlists and redaction.
     */
    private function handleDatabaseQuery(array $args): string
    {
        $table = $args['table'] ?? null;
        $columns = $args['columns'] ?? ['*'];
        $limit = min($args['limit'] ?? 50, 100);

        if (! in_array($table, self::TABLE_ALLOWLIST)) {
            return json_encode([
                'success' => false,
                'error' => "Table '{$table}' is not in the allowlist for direct queries.",
            ]);
        }

        // Build a simple secure SQL query
        $colsString = implode(', ', array_map(fn($c) => '"' . str_replace('"', '', $c) . '"', $columns));
        $query = "SELECT {$colsString} FROM \"{$table}\" LIMIT {$limit}";

        // We still execute via artisan to stay within the target project's environment
        $result = $this->executeArtisanCommand('database-query', ['query' => $query]);

        return $this->processResult($result);
    }

    /**
     * Executes the artisan boost command in the target directory.
     */
    private function executeArtisanCommand(string $tool, array $args): string
    {
        $command = "boost:{$tool}";
        $argsString = '';
        foreach ($args as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $argsString .= " --{$key}=\"" . addslashes($item) . "\"";
                }
            } else {
                $argsString .= " --{$key}=\"" . addslashes((string)$value) . "\"";
            }
        }

        $process = \Illuminate\Support\Facades\Process::path($this->workingDirectory)
            ->timeout(60)
            ->run("php artisan {$command} {$argsString}");

        if ($process->failed()) {
            return json_encode([
                'success' => false,
                'error' => "Boost tool '{$tool}' failed: " . $process->error(),
                'output' => $process->output(),
            ]);
        }

        return $process->output();
    }

    /**
     * Processes the result: redacts sensitive data and caps output size.
     */
    private function processResult(string $rawResult): string
    {
        try {
            $data = json_decode($rawResult, true);
            if (! is_array($data)) return mb_substr($rawResult, 0, 5000);

            // Redact sensitive fields
            $redactedData = $this->redactRecursive($data);
            
            $encoded = json_encode($redactedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if (strlen($encoded) > 5000) {
                return mb_substr($encoded, 0, 5000) . "\n... (result truncated to 5000 chars)";
            }

            return $encoded;
        } catch (\Exception $e) {
            return mb_substr($rawResult, 0, 5000);
        }
    }

    /**
     * Recursively redacts sensitive fields from an array.
     */
    private function redactRecursive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redactRecursive($value);
            } elseif (is_string($key)) {
                foreach (self::SENSITIVE_SUFFIXES as $suffix) {
                    if (str_ends_with(strtolower($key), $suffix)) {
                        $data[$key] = '[REDACTED]';
                        break;
                    }
                }
            }
        }
        return $data;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool' => $schema->string()
                ->description('The Boost tool to execute. Options: search-docs, database-schema, database-query, browser-logs, last-error.')
                ->required(),
            'arguments' => $schema->object([
                'queries' => $schema->array()->items($schema->string())->description('Queries for search-docs (array of strings)'),
                'table' => $schema->string()->description('Table name for database-schema or database-query'),
                'columns' => $schema->array()->items($schema->string())->description('Columns to retrieve for database-query (default ["*"])'),
                'limit' => $schema->integer()->description('Limit for logs or query results (default 50)'),
                'query' => $schema->string()->description('Legacy SQL query (discouraged, use structured params)'),
            ])->description('Arguments for the selected tool.')
                ->required(),
        ];
    }
}

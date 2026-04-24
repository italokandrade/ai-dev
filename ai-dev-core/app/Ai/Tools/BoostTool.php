<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class BoostTool implements Tool
{
    /**
     * Sensitive suffixes to redact from query results.
     */
    private const array SENSITIVE_SUFFIXES = ['_token', '_secret', '_password', '_key', '_hash'];

    private const array SENSITIVE_NAMES = [
        'api_key',
        'password',
        'remember_token',
        'secret',
        'token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    private const array BLOCKED_TABLES = [
        'cache',
        'cache_locks',
        'failed_jobs',
        'jobs',
        'job_batches',
        'migrations',
        'password_reset_tokens',
        'sessions',
    ];

    private const array OPERATOR_ALLOWLIST = ['=', '>', '<', '>=', '<=', 'LIKE', 'ILIKE', 'IN', 'IS NULL', 'IS NOT NULL'];

    private const array BOOST_TOOL_CLASSES = [
        'search-docs' => 'Laravel\\Boost\\Mcp\\Tools\\SearchDocs',
        'database-schema' => 'Laravel\\Boost\\Mcp\\Tools\\DatabaseSchema',
        'database-query' => 'Laravel\\Boost\\Mcp\\Tools\\DatabaseQuery',
        'browser-logs' => 'Laravel\\Boost\\Mcp\\Tools\\BrowserLogs',
        'last-error' => 'Laravel\\Boost\\Mcp\\Tools\\LastError',
        'application-info' => 'Laravel\\Boost\\Mcp\\Tools\\ApplicationInfo',
    ];

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

        return $this->executeBoostTool($toolName, $this->normalizeArguments($toolName, $arguments));
    }

    /**
     * Securely handles database queries with allowlists and redaction.
     */
    private function handleDatabaseQuery(array $args): string
    {
        $table = $args['table'] ?? null;
        $columns = $args['columns'] ?? ['*'];
        $limit = min($args['limit'] ?? 50, 100);
        $where = is_array($args['where'] ?? null) ? $args['where'] : [];
        $database = isset($args['database']) && is_string($args['database']) && $args['database'] !== ''
            ? $args['database']
            : null;

        if (! is_string($table) || ! $this->isSafeIdentifier($table)) {
            return json_encode([
                'success' => false,
                'error' => "Invalid table identifier '{$table}'.",
            ]);
        }

        if (in_array($table, self::BLOCKED_TABLES, true)) {
            return json_encode([
                'success' => false,
                'error' => "Table '{$table}' is blocked for direct queries.",
            ]);
        }

        $schema = $this->loadDatabaseSchema($database);
        if (isset($schema['success']) && $schema['success'] === false) {
            return json_encode($schema);
        }

        $availableColumns = $schema['tables'][$table] ?? null;
        if (! is_array($availableColumns)) {
            return json_encode([
                'success' => false,
                'error' => "Table '{$table}' is not present in the target database schema.",
            ]);
        }

        $engine = (string) ($schema['engine'] ?? 'pgsql');
        $availableColumnNames = array_keys($availableColumns);
        $selectedColumns = $this->resolveSelectedColumns($columns, $availableColumnNames);

        if (isset($selectedColumns['success']) && $selectedColumns['success'] === false) {
            return json_encode($selectedColumns);
        }

        $colsString = implode(', ', array_map(
            fn (string $column): string => $this->quoteIdentifier($column, $engine),
            $selectedColumns
        ));

        $query = "SELECT {$colsString} FROM ".$this->quoteIdentifier($table, $engine);
        $whereSql = [];

        foreach ($where as $index => $condition) {
            if (! is_array($condition)) {
                return json_encode([
                    'success' => false,
                    'error' => "Invalid where condition at index {$index}.",
                ]);
            }

            $column = $condition['column'] ?? null;
            $operator = strtoupper((string) ($condition['operator'] ?? '='));
            $value = $condition['value'] ?? null;

            if (! is_string($column) || $column === '') {
                return json_encode([
                    'success' => false,
                    'error' => "Invalid column in where condition at index {$index}.",
                ]);
            }

            if (! in_array($column, $availableColumnNames, true) || $this->isSensitiveIdentifier($column)) {
                return json_encode([
                    'success' => false,
                    'error' => "Column '{$column}' is not allowed in where condition at index {$index}.",
                ]);
            }

            if (! in_array($operator, self::OPERATOR_ALLOWLIST, true)) {
                return json_encode([
                    'success' => false,
                    'error' => "Operator '{$operator}' is not allowed.",
                ]);
            }

            if ($operator === 'ILIKE' && ! in_array($engine, ['pgsql', 'postgres', 'postgresql'], true)) {
                return json_encode([
                    'success' => false,
                    'error' => 'Operator ILIKE is only supported by PostgreSQL connections.',
                ]);
            }

            $quotedColumn = $this->quoteIdentifier($column, $engine);

            if (in_array($operator, ['IS NULL', 'IS NOT NULL'], true)) {
                $whereSql[] = "{$quotedColumn} {$operator}";

                continue;
            }

            if ($operator === 'IN') {
                if (! is_array($value) || $value === []) {
                    return json_encode([
                        'success' => false,
                        'error' => "Operator IN requires a non-empty array in where condition at index {$index}.",
                    ]);
                }

                $placeholders = [];
                foreach ($value as $inValue) {
                    $placeholders[] = $this->quoteLiteral($inValue);
                }
                $whereSql[] = "{$quotedColumn} IN (".implode(', ', $placeholders).')';

                continue;
            }

            if ($value === null) {
                return json_encode([
                    'success' => false,
                    'error' => "Operator '{$operator}' requires a non-null value in where condition at index {$index}. Use IS NULL / IS NOT NULL for null checks.",
                ]);
            }

            $whereSql[] = "{$quotedColumn} {$operator} ".$this->quoteLiteral($value);
        }

        if ($whereSql !== []) {
            $query .= ' WHERE '.implode(' AND ', $whereSql);
        }

        $query .= ' LIMIT '.max(1, (int) $limit);

        return $this->processResult($this->executeBoostTool('database-query', array_filter([
            'query' => $query,
            'database' => $database,
        ], fn ($value) => $value !== null)));
    }

    /**
     * Executes the Boost MCP tool in the target Laravel application.
     */
    private function executeBoostTool(string $tool, array $args): string
    {
        $toolClass = self::BOOST_TOOL_CLASSES[$tool] ?? null;
        if ($toolClass === null) {
            return json_encode([
                'success' => false,
                'error' => "Unknown Boost tool '{$tool}'.",
            ]);
        }

        $encodedArguments = base64_encode(json_encode($args, JSON_UNESCAPED_UNICODE));
        $timeout = max(1, min(600, (int) ($args['timeout'] ?? 180)));

        $process = Process::path($this->workingDirectory)
            ->timeout($timeout)
            ->run([PHP_BINARY, 'artisan', 'boost:execute-tool', $toolClass, $encodedArguments]);

        if ($process->failed()) {
            return json_encode([
                'success' => false,
                'error' => "Boost tool '{$tool}' failed: ".$process->errorOutput(),
                'output' => $process->output(),
            ]);
        }

        $decoded = json_decode($process->output(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $process->output();
        }

        $content = $decoded['content'][0]['text'] ?? '';

        if ($decoded['isError'] ?? false) {
            return json_encode([
                'success' => false,
                'error' => (string) $content,
            ]);
        }

        return is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE);
    }

    private function normalizeArguments(string $tool, array $args): array
    {
        if ($tool === 'database-schema' && isset($args['table']) && ! isset($args['filter'])) {
            $args['filter'] = $args['table'];
        }

        if ($tool === 'browser-logs' && isset($args['limit']) && ! isset($args['entries'])) {
            $args['entries'] = (int) $args['limit'];
        }

        if ($tool === 'browser-logs' && ! isset($args['entries'])) {
            $args['entries'] = 50;
        }

        return $args;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDatabaseSchema(?string $database): array
    {
        $schema = $this->executeBoostTool('database-schema', array_filter([
            'summary' => true,
            'database' => $database,
        ], fn ($value) => $value !== null));

        $decoded = json_decode($schema, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return [
                'success' => false,
                'error' => 'Unable to read target database schema before running database-query.',
            ];
        }

        if (! isset($decoded['tables']) || ! is_array($decoded['tables'])) {
            return [
                'success' => false,
                'error' => 'Target database schema response did not include a tables map.',
            ];
        }

        return $decoded;
    }

    /**
     * @param  array<int, string>  $availableColumns
     * @return array<int, string>|array{success: false, error: string}
     */
    private function resolveSelectedColumns(mixed $columns, array $availableColumns): array
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }

        if (! is_array($columns) || $columns === [] || in_array('*', $columns, true)) {
            $columns = array_values(array_filter(
                $availableColumns,
                fn (string $column): bool => ! $this->isSensitiveIdentifier($column)
            ));
        }

        $selected = [];
        foreach ($columns as $column) {
            if (! is_string($column) || ! $this->isSafeIdentifier($column)) {
                return [
                    'success' => false,
                    'error' => "Invalid column identifier '{$column}'.",
                ];
            }

            if (! in_array($column, $availableColumns, true) || $this->isSensitiveIdentifier($column)) {
                return [
                    'success' => false,
                    'error' => "Column '{$column}' is not allowed for direct queries.",
                ];
            }

            $selected[] = $column;
        }

        return array_values(array_unique($selected));
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) === 1;
    }

    private function quoteIdentifier(string $identifier, string $engine): string
    {
        $quote = in_array($engine, ['mysql', 'mariadb'], true) ? '`' : '"';

        return $quote.str_replace($quote, '', $identifier).$quote;
    }

    private function quoteLiteral(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return "'".str_replace("'", "''", (string) $value)."'";
    }

    private function isSensitiveIdentifier(string $identifier): bool
    {
        if (in_array(strtolower($identifier), self::SENSITIVE_NAMES, true)) {
            return true;
        }

        foreach (self::SENSITIVE_SUFFIXES as $suffix) {
            if (str_ends_with(strtolower($identifier), $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Processes the result: redacts sensitive data and caps output size.
     */
    private function processResult(string $rawResult): string
    {
        try {
            $data = json_decode($rawResult, true);
            if (! is_array($data)) {
                return mb_substr($rawResult, 0, 5000);
            }

            $redactedData = $this->redactRecursive($data);

            $encoded = json_encode($redactedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if (strlen($encoded) > 5000) {
                return mb_substr($encoded, 0, 5000)."\n... (result truncated to 5000 chars)";
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
                'database' => $schema->string()->description('Optional database connection name in the target application. Use "readonly" only when the target project has that connection configured.'),
                'summary' => $schema->boolean()->description('Whether database-schema should return summary mode.'),
                'filter' => $schema->string()->description('Table filter for database-schema.'),
                'entries' => $schema->integer()->description('Number of browser log entries to read.'),
                'where' => $schema->array()->items(
                    $schema->object([
                        'column' => $schema->string()->description('Column name for WHERE clause')->required(),
                        'operator' => $schema->string()->description('Operator (=, >, <, >=, <=, LIKE, ILIKE, IN, IS NULL, IS NOT NULL)')->required(),
                        'value' => $schema->string()->description('Value for comparison. Omit for IS NULL / IS NOT NULL'),
                    ])
                )->description('Optional WHERE conditions combined with AND.'),
                'limit' => $schema->integer()->description('Limit for logs or query results (default 50)'),
            ])->description('Arguments for the selected tool.')
                ->required(),
        ];
    }
}

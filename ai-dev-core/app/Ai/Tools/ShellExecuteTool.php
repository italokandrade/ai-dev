<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ShellExecuteTool implements Tool
{
    private const array ALLOWED_VENDOR_BINARIES = [
        'enlightn',
        'pest',
        'phpstan',
        'phpunit',
        'pint',
    ];

    private const array ALLOWED_COMPOSER_COMMANDS = [
        'audit',
        'dump-autoload',
        'install',
        'remove',
        'require',
        'run-script',
        'test',
        'update',
    ];

    private const array ALLOWED_NPM_COMMANDS = [
        'audit',
        'ci',
        'exec',
        'install',
        'run',
    ];

    private const array ALLOWED_ENV_VARS = [
        'APP_ENV',
        'CACHE_STORE',
        'DB_CONNECTION',
        'DB_DATABASE',
        'DB_HOST',
        'DB_PASSWORD',
        'DB_PORT',
        'DB_USERNAME',
        'QUEUE_CONNECTION',
        'SESSION_DRIVER',
    ];

    private const BLOCKED_PATTERNS = [
        'rm -rf /',
        'shutdown',
        'reboot',
        'chmod 777',
        'chown root',
        'mkfs',
        'dd if=',
        '> /dev/sda',
        'format c:',
    ];

    private const array DISALLOWED_SHELL_TOKENS = [
        '&&',
        '||',
        ';',
        '|',
        '`',
        '$(',
        '>',
        '<',
        "\n",
        "\r",
    ];

    public function __construct(
        private readonly string $workingDirectory,
    ) {}

    public function description(): Stringable|string
    {
        return 'Executes allowlisted development commands in the project directory. Supports php artisan, composer, npm, npx, and selected vendor/bin tools. Returns stdout, stderr, exit_code and execution time.';
    }

    public function handle(Request $request): Stringable|string
    {
        $command = $request['command'];
        $timeout = max(1, min(600, (int) ($request['timeout'] ?? 120)));
        $environment = $this->validatedEnvironment($request['environment'] ?? []);

        if (isset($environment['success']) && $environment['success'] === false) {
            return json_encode($environment);
        }

        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (str_contains(strtolower($command), strtolower($pattern))) {
                return json_encode(['success' => false, 'error' => "Blocked command pattern: {$pattern}"]);
            }
        }

        foreach (self::DISALLOWED_SHELL_TOKENS as $token) {
            if (str_contains($command, $token)) {
                return json_encode(['success' => false, 'error' => "Shell control token '{$token}' is not allowed. Run one command per tool call."]);
            }
        }

        $tokens = $this->tokenizeCommand($command);
        if ($tokens === [] || ! $this->isAllowedCommand($tokens)) {
            return json_encode([
                'success' => false,
                'error' => 'Command is not allowlisted. Allowed families: php artisan, composer, npm/npx, and selected vendor/bin QA tools.',
            ]);
        }

        $start = microtime(true);

        $result = Process::path($this->workingDirectory)
            ->env($environment)
            ->timeout($timeout)
            ->run($tokens);

        $elapsed = (int) ((microtime(true) - $start) * 1000);

        return json_encode([
            'success' => $result->successful(),
            'exit_code' => $result->exitCode(),
            'stdout' => mb_substr($result->output(), 0, 50000),
            'stderr' => mb_substr($result->errorOutput(), 0, 10000),
            'execution_time_ms' => $elapsed,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()
                ->description('The shell command to execute. Use absolute paths.')
                ->required(),
            'timeout' => $schema->integer()
                ->description('Timeout in seconds (default: 120, max: 600)')
                ->min(1)
                ->max(600),
            'environment' => $schema->object()
                ->description('Optional environment overrides for a single command. Only safe development variables are allowed, such as DB_CONNECTION and DB_DATABASE.'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function tokenizeCommand(string $command): array
    {
        $tokens = str_getcsv($command, ' ', '"', '\\');

        return array_values(array_filter(
            array_map('trim', $tokens),
            fn (string $token): bool => $token !== ''
        ));
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function isAllowedCommand(array $tokens): bool
    {
        $binary = $tokens[0] ?? '';
        $subcommand = $tokens[1] ?? null;

        if ($binary === 'php') {
            return $subcommand === 'artisan';
        }

        if ($binary === 'composer') {
            return is_string($subcommand) && in_array($subcommand, self::ALLOWED_COMPOSER_COMMANDS, true);
        }

        if ($binary === 'npm') {
            return is_string($subcommand) && in_array($subcommand, self::ALLOWED_NPM_COMMANDS, true);
        }

        if ($binary === 'npx') {
            return is_string($subcommand) && in_array($subcommand, self::ALLOWED_VENDOR_BINARIES, true);
        }

        $normalizedBinary = ltrim($binary, './');
        if (str_starts_with($normalizedBinary, 'vendor/bin/')) {
            return in_array(basename($normalizedBinary), self::ALLOWED_VENDOR_BINARIES, true);
        }

        return in_array($binary, ['enlightn', 'nikto', 'pest', 'phpstan', 'phpunit', 'pint', 'sqlmap'], true);
    }

    /**
     * @return array<string, string>|array{success: false, error: string}
     */
    private function validatedEnvironment(mixed $environment): array
    {
        if ($environment === null || $environment === []) {
            return [];
        }

        if (! is_array($environment)) {
            return ['success' => false, 'error' => 'Environment must be an object of string key/value pairs.'];
        }

        $validated = [];

        foreach ($environment as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_ENV_VARS, true)) {
                return ['success' => false, 'error' => "Environment variable '{$key}' is not allowlisted."];
            }

            if (! is_scalar($value) && $value !== null) {
                return ['success' => false, 'error' => "Environment variable '{$key}' must be scalar."];
            }

            $stringValue = (string) $value;
            if (strlen($stringValue) > 500 || str_contains($stringValue, "\n") || str_contains($stringValue, "\r")) {
                return ['success' => false, 'error' => "Environment variable '{$key}' has an invalid value."];
            }

            $validated[$key] = $stringValue;
        }

        return $validated;
    }
}

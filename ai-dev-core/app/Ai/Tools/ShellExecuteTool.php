<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ShellExecuteTool implements Tool
{
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

    public function __construct(
        private readonly string $workingDirectory,
    ) {}

    public function description(): Stringable|string
    {
        return 'Executes shell commands in the project directory. Supports artisan, composer, npm, and general commands. Returns stdout, stderr, exit_code and execution time. Use absolute paths when possible.';
    }

    public function handle(Request $request): Stringable|string
    {
        $command = $request['command'];
        $timeout = (int) ($request['timeout'] ?? 120);

        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (str_contains(strtolower($command), strtolower($pattern))) {
                return json_encode(['success' => false, 'error' => "Blocked command pattern: {$pattern}"]);
            }
        }

        $start = microtime(true);

        $result = Process::path($this->workingDirectory)
            ->timeout($timeout)
            ->run($command);

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
        ];
    }
}

<?php

namespace App\Tools;

use App\Contracts\ToolInterface;
use Illuminate\Support\Facades\Process;

class ShellTool implements ToolInterface
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

    public function name(): string
    {
        return 'ShellTool';
    }

    public function description(): string
    {
        return 'Executa comandos no terminal do servidor de forma controlada. Suporta artisan, composer, npm e comandos genéricos com timeout e segurança.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['action', 'command'],
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['execute', 'execute_background', 'kill'],
                    'description' => 'Qual ação executar.',
                ],
                'command' => [
                    'type' => 'string',
                    'description' => 'O comando completo a ser executado. DEVE usar caminhos absolutos.',
                ],
                'working_directory' => [
                    'type' => 'string',
                    'description' => 'Diretório de trabalho. Se omitido, usa o local_path do projeto ativo.',
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'description' => 'Timeout máximo em segundos.',
                    'default' => 120,
                ],
                'pid' => [
                    'type' => 'integer',
                    'description' => 'PID do processo a matar. Obrigatório para action kill.',
                ],
            ],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'exit_code' => ['type' => 'integer'],
                'stdout' => ['type' => 'string'],
                'stderr' => ['type' => 'string'],
                'pid' => ['type' => 'integer'],
                'execution_time_ms' => ['type' => 'integer'],
            ],
        ];
    }

    public function execute(array $params): ToolResult
    {
        $action = $params['action'];
        $command = $params['command'] ?? '';
        $workingDir = $params['working_directory'] ?? null;
        $timeout = $params['timeout_seconds'] ?? 120;

        // Security check
        $securityCheck = $this->checkSecurity($command);
        if ($securityCheck) {
            return ToolResult::fail("BLOCKED: {$securityCheck}", securityFlag: true);
        }

        return match ($action) {
            'execute' => $this->executeCommand($command, $workingDir, $timeout),
            'execute_background' => $this->executeBackground($command, $workingDir),
            'kill' => $this->killProcess($params['pid'] ?? 0),
            default => ToolResult::fail("Unknown action: {$action}"),
        };
    }

    private function executeCommand(string $command, ?string $workingDir, int $timeout): ToolResult
    {
        $start = microtime(true);

        $process = Process::timeout($timeout);
        if ($workingDir) {
            $process = $process->path($workingDir);
        }

        $result = $process->run($command);
        $elapsed = (int) ((microtime(true) - $start) * 1000);

        return ToolResult::ok([
            'success' => $result->successful(),
            'exit_code' => $result->exitCode(),
            'stdout' => mb_substr($result->output(), 0, 50000),
            'stderr' => mb_substr($result->errorOutput(), 0, 10000),
            'execution_time_ms' => $elapsed,
        ], $elapsed);
    }

    private function executeBackground(string $command, ?string $workingDir): ToolResult
    {
        $cdPrefix = $workingDir ? "cd {$workingDir} && " : '';
        $fullCommand = "{$cdPrefix}{$command} > /dev/null 2>&1 & echo \$!";

        $output = shell_exec($fullCommand);
        $pid = (int) trim($output ?? '0');

        return ToolResult::ok([
            'success' => $pid > 0,
            'pid' => $pid,
            'message' => $pid > 0 ? "Process started with PID {$pid}" : 'Failed to start background process',
        ]);
    }

    private function killProcess(int $pid): ToolResult
    {
        if ($pid <= 0) {
            return ToolResult::fail('Invalid PID');
        }

        $result = Process::run("kill {$pid} 2>&1");

        return ToolResult::ok([
            'success' => $result->successful(),
            'message' => $result->successful() ? "Process {$pid} killed" : "Failed to kill process {$pid}: {$result->output()}",
        ]);
    }

    private function checkSecurity(string $command): ?string
    {
        $lower = strtolower($command);
        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (str_contains($lower, strtolower($pattern))) {
                return "Command contains blocked pattern: {$pattern}";
            }
        }

        return null;
    }
}

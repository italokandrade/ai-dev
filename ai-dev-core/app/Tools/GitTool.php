<?php

namespace App\Tools;

use App\Contracts\ToolInterface;
use Illuminate\Support\Facades\Process;

class GitTool implements ToolInterface
{
    public function name(): string
    {
        return 'GitTool';
    }

    public function description(): string
    {
        return 'Operações Git: status, diff, commit, push, branch, merge, log. Permite controle de versionamento isolado por task.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['action', 'working_directory'],
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['status', 'diff', 'commit', 'push', 'branch_create', 'branch_checkout', 'merge', 'log', 'add', 'stash', 'stash_pop'],
                    'description' => 'Qual operação Git executar.',
                ],
                'working_directory' => [
                    'type' => 'string',
                    'description' => 'Caminho absoluto do repositório Git.',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Mensagem de commit (para action commit).',
                ],
                'branch' => [
                    'type' => 'string',
                    'description' => 'Nome do branch (para branch_create, branch_checkout, merge).',
                ],
                'files' => [
                    'type' => 'array',
                    'description' => 'Array de caminhos de arquivos (para add). Se vazio, usa "." (tudo).',
                    'items' => ['type' => 'string'],
                ],
                'max_entries' => [
                    'type' => 'integer',
                    'description' => 'Número máximo de entradas no log. Padrão: 10.',
                    'default' => 10,
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
                'output' => ['type' => 'string'],
            ],
        ];
    }

    public function execute(array $params): ToolResult
    {
        $action = $params['action'];
        $workDir = $params['working_directory'];

        if (! is_dir("{$workDir}/.git")) {
            return ToolResult::fail("Not a git repository: {$workDir}");
        }

        return match ($action) {
            'status' => $this->git($workDir, 'status --short'),
            'diff' => $this->git($workDir, 'diff'),
            'log' => $this->git($workDir, 'log --oneline -' . ($params['max_entries'] ?? 10)),
            'add' => $this->gitAdd($workDir, $params['files'] ?? ['.']),
            'commit' => $this->gitCommit($workDir, $params['message'] ?? 'auto-commit by AI-Dev'),
            'push' => $this->git($workDir, 'push'),
            'branch_create' => $this->git($workDir, 'checkout -b ' . ($params['branch'] ?? '')),
            'branch_checkout' => $this->git($workDir, 'checkout ' . ($params['branch'] ?? '')),
            'merge' => $this->gitMerge($workDir, $params['branch'] ?? ''),
            'stash' => $this->git($workDir, 'stash'),
            'stash_pop' => $this->git($workDir, 'stash pop'),
            default => ToolResult::fail("Unknown action: {$action}"),
        };
    }

    private function git(string $workDir, string $args): ToolResult
    {
        $result = Process::path($workDir)->timeout(60)->run("git {$args}");

        $output = $result->output() ?: $result->errorOutput();

        if ($result->successful()) {
            return ToolResult::ok([
                'output' => mb_substr(trim($output), 0, 50000),
            ]);
        }

        return ToolResult::fail("Git error: " . mb_substr(trim($output), 0, 5000));
    }

    private function gitAdd(string $workDir, array $files): ToolResult
    {
        $fileList = implode(' ', array_map('escapeshellarg', $files));

        return $this->git($workDir, "add {$fileList}");
    }

    private function gitCommit(string $workDir, string $message): ToolResult
    {
        $escapedMessage = escapeshellarg($message);

        return $this->git($workDir, "commit -m {$escapedMessage}");
    }

    private function gitMerge(string $workDir, string $branch): ToolResult
    {
        if (! $branch) {
            return ToolResult::fail('Branch name is required for merge');
        }

        return $this->git($workDir, "merge {$branch} --no-edit");
    }
}

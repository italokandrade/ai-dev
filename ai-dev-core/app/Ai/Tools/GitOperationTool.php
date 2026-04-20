<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GitOperationTool implements Tool
{
    public function __construct(
        private readonly string $workingDirectory,
    ) {}

    public function description(): Stringable|string
    {
        return 'Performs git operations on the project repository: status, diff, log, branch creation/checkout, staging files, committing changes, and pushing to remote. Always check status before committing.';
    }

    public function handle(Request $request): Stringable|string
    {
        $action = $request['action'];

        $git = Process::path($this->workingDirectory)->timeout(60);

        $result = match ($action) {
            'status' => $git->run('git status --short'),
            'diff' => $git->run('git diff'),
            'log' => $git->run('git log --oneline -20'),

            'branch_create' => $git->run('git checkout -b '.escapeshellarg($request['branch'] ?? '')),
            'branch_checkout' => $git->run('git checkout '.escapeshellarg($request['branch'] ?? '')),
            'branch_list' => $git->run('git branch -a'),

            'add' => $git->run('git add '.($request['path'] ? escapeshellarg($request['path']) : '-A')),
            'commit' => $git->run('git commit -m '.escapeshellarg($request['message'] ?? 'feat: AI-Dev agent commit')),
            'push' => $git->run('git push origin HEAD'),

            'reset_hard' => $git->run('git reset --hard HEAD'),
            'stash' => $git->run('git stash'),

            default => null,
        };

        if ($result === null) {
            return json_encode(['success' => false, 'error' => "Unknown git action: {$action}"]);
        }

        return json_encode([
            'success' => $result->successful(),
            'output' => mb_substr($result->output(), 0, 20000),
            'error' => mb_substr($result->errorOutput(), 0, 5000),
            'exit_code' => $result->exitCode(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Git action: status, diff, log, branch_create, branch_checkout, branch_list, add, commit, push, reset_hard, stash.')
                ->enum(['status', 'diff', 'log', 'branch_create', 'branch_checkout', 'branch_list', 'add', 'commit', 'push', 'reset_hard', 'stash'])
                ->required(),
            'branch' => $schema->string()
                ->description('Branch name for branch_create or branch_checkout actions.')
                ->required(),
            'path' => $schema->string()
                ->description('File path for git add. If omitted, stages all changed files (-A).')
                ->required(),
            'message' => $schema->string()
                ->description('Commit message for the commit action.')
                ->required(),
        ];
    }
}

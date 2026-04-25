<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
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

        $path = $request['path'] ?? null;
        $branch = $request['branch'] ?? null;
        $message = $request['message'] ?? 'feat: AI-Dev agent commit';

        $result = match ($action) {
            'status' => $this->git(['git', 'status', '--short']),
            'diff' => $this->git(['git', 'diff']),
            'log' => $this->git(['git', 'log', '--oneline', '-20']),

            'branch_create' => is_string($branch) && $branch !== ''
                ? $this->git(['git', 'checkout', '-b', $branch])
                : null,
            'branch_checkout' => is_string($branch) && $branch !== ''
                ? $this->git(['git', 'checkout', $branch])
                : null,
            'branch_list' => $this->git(['git', 'branch', '-a']),

            'add' => is_string($path) && $path !== ''
                ? $this->git(['git', 'add', $path], clearStaleLock: true)
                : $this->git(['git', 'add', '-A'], clearStaleLock: true),
            'commit' => $this->git(['git', 'commit', '-m', $message], clearStaleLock: true),
            'push' => $this->git(['git', 'push', 'origin', 'HEAD']),

            'reset_hard' => null,
            'stash' => $this->git(['git', 'stash', 'push', '-u']),

            default => null,
        };

        if ($result === null) {
            return json_encode(['success' => false, 'error' => "Unknown, incomplete, or disabled git action: {$action}"]);
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
                ->description('Branch name for branch_create or branch_checkout actions.'),
            'path' => $schema->string()
                ->description('File path for git add. If omitted, stages all changed files (-A).'),
            'message' => $schema->string()
                ->description('Commit message for the commit action.'),
        ];
    }

    /**
     * @param  array<int, string>  $command
     */
    private function git(array $command, bool $clearStaleLock = false)
    {
        if ($clearStaleLock) {
            $this->clearStaleIndexLock();
        }

        if (($command[0] ?? null) === 'git') {
            array_splice($command, 1, 0, ['-c', "safe.directory={$this->workingDirectory}"]);
        }

        return Process::path($this->workingDirectory)->timeout(60)->run($command);
    }

    private function clearStaleIndexLock(): void
    {
        $lockFile = "{$this->workingDirectory}/.git/index.lock";

        if (! file_exists($lockFile)) {
            return;
        }

        $age = time() - (int) filemtime($lockFile);

        if ($age < 120) {
            return;
        }

        Log::warning('GitOperationTool: removendo index.lock obsoleto', [
            'work_dir' => $this->workingDirectory,
            'age_seconds' => $age,
        ]);

        @unlink($lockFile);
    }
}

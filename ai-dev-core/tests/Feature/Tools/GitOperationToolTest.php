<?php

use App\Ai\Tools\GitOperationTool;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

test('git operation tool clears stale index lock and uses safe directory', function () {
    $target = sys_get_temp_dir().'/git-tool-test-'.Str::uuid();
    File::ensureDirectoryExists("{$target}/.git");
    File::put("{$target}/.git/index.lock", 'stale');
    touch("{$target}/.git/index.lock", time() - 180);

    Process::fake(fn () => Process::result('added'));

    $result = json_decode((new GitOperationTool($target))->handle(new Request([
        'action' => 'add',
    ])), true);

    expect($result['success'])->toBeTrue()
        ->and(File::exists("{$target}/.git/index.lock"))->toBeFalse();

    Process::assertRan(fn ($process) => $process->path === $target
        && $process->command === ['git', '-c', "safe.directory={$target}", 'add', '-A']);

    File::deleteDirectory($target);
});

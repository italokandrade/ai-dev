<?php

use App\Ai\Tools\ShellExecuteTool;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

function makeShellToolTargetDirectory(): string
{
    $dir = sys_get_temp_dir().'/shell-tool-test-'.Str::uuid();
    mkdir($dir, 0755, true);

    return $dir;
}

test('shell tool runs allowlisted artisan commands without shell expansion', function () {
    $target = makeShellToolTargetDirectory();

    Process::fake(fn () => Process::result('ok'));

    $result = json_decode((new ShellExecuteTool($target))->handle(new Request([
        'command' => 'php artisan test --compact',
    ])), true);

    expect($result['success'])->toBeTrue()
        ->and(trim($result['stdout']))->toBe('ok');

    Process::assertRan(fn ($process) => $process->path === $target
        && $process->command === ['php', 'artisan', 'test', '--compact']);
});

test('shell tool blocks non allowlisted binaries', function () {
    $target = makeShellToolTargetDirectory();

    Process::fake();

    $result = json_decode((new ShellExecuteTool($target))->handle(new Request([
        'command' => 'cat /etc/passwd',
    ])), true);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('not allowlisted');

    Process::assertNothingRan();
});

test('shell tool blocks shell control operators', function () {
    $target = makeShellToolTargetDirectory();

    Process::fake();

    $result = json_decode((new ShellExecuteTool($target))->handle(new Request([
        'command' => 'php artisan test && cat .env',
    ])), true);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('not allowed');

    Process::assertNothingRan();
});

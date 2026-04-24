<?php

use App\Ai\Tools\FileReadTool;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

function makeFileReadToolTargetDirectory(): string
{
    $dir = sys_get_temp_dir().'/file-read-tool-test-'.Str::uuid();
    mkdir($dir, 0755, true);

    return $dir;
}

test('file read tool blocks env files even when path is relative', function () {
    $target = makeFileReadToolTargetDirectory();
    file_put_contents($target.'/.env', 'APP_KEY=base64:secret');

    $result = json_decode((new FileReadTool($target))->handle(new Request([
        'path' => '.env',
    ])), true);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Acesso negado');
});

test('file read tool blocks storage logs', function () {
    $target = makeFileReadToolTargetDirectory();
    mkdir($target.'/storage/logs', 0755, true);
    file_put_contents($target.'/storage/logs/laravel.log', 'token=secret');

    $result = json_decode((new FileReadTool($target))->handle(new Request([
        'path' => 'storage/logs/laravel.log',
    ])), true);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Acesso negado');
});

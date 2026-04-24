<?php

use App\Ai\Tools\BoostTool;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

function makeBoostToolTargetDirectory(): string
{
    $dir = sys_get_temp_dir().'/boost-tool-test-'.Str::uuid();
    mkdir($dir, 0755, true);

    return $dir;
}

function boostToolResponse(string $text, bool $isError = false): string
{
    return json_encode([
        'isError' => $isError,
        'content' => [
            ['type' => 'text', 'text' => $text],
        ],
    ]);
}

test('boost tool executes the real boost execute-tool command in the target project', function () {
    $target = makeBoostToolTargetDirectory();

    Process::fake(fn () => Process::result(boostToolResponse('Filament docs')));

    $result = (new BoostTool($target))->handle(new Request([
        'tool' => 'search-docs',
        'arguments' => ['queries' => ['Filament 5 table filters']],
    ]));

    expect($result)->toBe('Filament docs');

    Process::assertRan(fn ($process) => $process->path === $target
        && is_array($process->command)
        && $process->command[2] === 'boost:execute-tool'
        && $process->command[3] === 'Laravel\\Boost\\Mcp\\Tools\\SearchDocs');
});

test('database query uses target schema allowlist and redacts sensitive result fields', function () {
    $target = makeBoostToolTargetDirectory();
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;
        $toolClass = $process->command[3];

        if ($toolClass === 'Laravel\\Boost\\Mcp\\Tools\\DatabaseSchema') {
            return Process::result(boostToolResponse(json_encode([
                'engine' => 'pgsql',
                'tables' => [
                    'clients' => [
                        'id' => 'uuid',
                        'name' => 'varchar',
                        'password_hash' => 'varchar',
                    ],
                ],
            ])));
        }

        return Process::result(boostToolResponse(json_encode([
            ['id' => '1', 'name' => 'Ana', 'api_key' => 'secret-value'],
        ])));
    });

    $result = (new BoostTool($target))->handle(new Request([
        'tool' => 'database-query',
        'arguments' => [
            'table' => 'clients',
            'columns' => ['*'],
            'where' => [
                ['column' => 'name', 'operator' => '=', 'value' => 'Ana'],
            ],
            'limit' => 5,
        ],
    ]));

    $queryArguments = json_decode(base64_decode($commands[1][4], true), true);

    expect($queryArguments['query'])
        ->toContain('SELECT "id", "name" FROM "clients"')
        ->toContain('"name" = \'Ana\'')
        ->not->toContain('password_hash')
        ->and($result)->toContain('[REDACTED]')
        ->and($result)->not->toContain('secret-value');
});

test('database query rejects explicit sensitive columns', function () {
    $target = makeBoostToolTargetDirectory();

    Process::fake(fn () => Process::result(boostToolResponse(json_encode([
        'engine' => 'pgsql',
        'tables' => [
            'clients' => [
                'id' => 'uuid',
                'password_hash' => 'varchar',
            ],
        ],
    ]))));

    $result = (new BoostTool($target))->handle(new Request([
        'tool' => 'database-query',
        'arguments' => [
            'table' => 'clients',
            'columns' => ['password_hash'],
        ],
    ]));

    expect($result)->toContain('not allowed');
});

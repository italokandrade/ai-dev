<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class FileReadTool implements Tool
{
    public function __construct(
        private readonly string $workingDirectory,
    ) {}

    public function description(): Stringable|string
    {
        return 'Reads a file from the project. Returns file content with line numbers. Can also list directory contents. Supports pagination via offset/limit for large files.';
    }

    public function handle(Request $request): Stringable|string
    {
        $path = $request['path'];

        if (! str_starts_with($path, '/')) {
            $path = rtrim($this->workingDirectory, '/').'/'.$path;
        }

        if (! file_exists($path)) {
            return json_encode(['success' => false, 'error' => "Path not found: {$path}"]);
        }

        if (is_dir($path)) {
            $items = @scandir($path);
            if ($items === false) {
                return json_encode(['success' => false, 'error' => "Cannot read directory: {$path}"]);
            }

            $entries = collect($items)
                ->filter(fn ($item) => $item !== '.' && $item !== '..')
                ->map(fn ($item) => [
                    'name' => $item,
                    'type' => is_dir("{$path}/{$item}") ? 'dir' : 'file',
                ])
                ->values()
                ->all();

            return json_encode(['success' => true, 'type' => 'directory', 'path' => $path, 'entries' => $entries]);
        }

        $offset = (int) ($request['offset'] ?? 0);
        $limit = min((int) ($request['limit'] ?? 500), 2000);

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return json_encode(['success' => false, 'error' => "Cannot read file: {$path}"]);
        }

        $totalLines = count($lines);
        $slice = array_slice($lines, $offset, $limit);

        return json_encode([
            'success' => true,
            'path' => $path,
            'total_lines' => $totalLines,
            'offset' => $offset,
            'content' => implode("\n", $slice),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('Absolute or project-relative file/directory path to read.')
                ->required(),
            'offset' => $schema->integer()
                ->description('Line number to start reading from (default: 0).')
                ->min(0),
            'limit' => $schema->integer()
                ->description('Maximum number of lines to return (default: 500, max: 2000).')
                ->min(1)
                ->max(2000),
        ];
    }
}

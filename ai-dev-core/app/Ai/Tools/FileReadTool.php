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

        // Hard block for sensitive files
        $blockedPatterns = [
            '.env',
            '/.env',
            '.key',
            '.pem',
            'bootstrap/cache',
            'config/database.php',
            'config/services.php',
            'auth.json',
            'storage/framework/sessions',
            'storage/logs',
            'storage/oauth',
        ];

        foreach ($blockedPatterns as $pattern) {
            if (str_contains(strtolower($path), strtolower($pattern))) {
                return json_encode([
                    'success' => false,
                    'error' => 'Acesso negado: Este arquivo contém informações sensíveis e não pode ser lido pela IA.',
                ]);
            }
        }

        $resolvedPath = $this->resolvePath($path);
        if ($resolvedPath === null) {
            return json_encode([
                'success' => false,
                'error' => 'Invalid path: access outside of project working directory is not allowed.',
            ]);
        }

        if (! file_exists($resolvedPath)) {
            return json_encode(['success' => false, 'error' => "Path not found: {$resolvedPath}"]);
        }

        if (is_dir($resolvedPath)) {
            $items = @scandir($resolvedPath);
            if ($items === false) {
                return json_encode(['success' => false, 'error' => "Cannot read directory: {$resolvedPath}"]);
            }

            $entries = collect($items)
                ->filter(fn ($item) => $item !== '.' && $item !== '..')
                ->map(fn ($item) => [
                    'name' => $item,
                    'type' => is_dir("{$resolvedPath}/{$item}") ? 'dir' : 'file',
                ])
                ->values()
                ->all();

            return json_encode(['success' => true, 'type' => 'directory', 'path' => $resolvedPath, 'entries' => $entries]);
        }

        $offset = (int) ($request['offset'] ?? 0);
        $limit = min((int) ($request['limit'] ?? 500), 2000);

        $lines = @file($resolvedPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return json_encode(['success' => false, 'error' => "Cannot read file: {$resolvedPath}"]);
        }

        $totalLines = count($lines);
        $slice = array_slice($lines, $offset, $limit);

        return json_encode([
            'success' => true,
            'path' => $resolvedPath,
            'total_lines' => $totalLines,
            'offset' => $offset,
            'content' => implode("\n", $slice),
        ]);
    }

    private function resolvePath(string $inputPath): ?string
    {
        $base = rtrim($this->workingDirectory, '/');
        $candidate = str_starts_with($inputPath, '/') ? $inputPath : "{$base}/{$inputPath}";
        $normalized = preg_replace('#/+#', '/', $candidate);
        if ($normalized === null) {
            return null;
        }

        $segments = explode('/', ltrim($normalized, '/'));
        $safeSegments = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($safeSegments);

                continue;
            }

            $safeSegments[] = $segment;
        }

        $resolved = '/'.implode('/', $safeSegments);

        return str_starts_with($resolved.'/', $base.'/') || $resolved === $base
            ? $resolved
            : null;
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

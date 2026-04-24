<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class FileWriteTool implements Tool
{
    public function __construct(
        private readonly string $workingDirectory,
    ) {}

    public function description(): Stringable|string
    {
        return 'Creates or overwrites a file in the project with the given content. Can also apply a targeted string replacement (find & replace) inside an existing file without rewriting the whole thing. Use the replace action for small edits, use write for new files or full rewrites.';
    }

    public function handle(Request $request): Stringable|string
    {
        $action = $request['action'] ?? 'write';
        $path = $request['path'];

        $resolvedPath = $this->resolvePath($path);
        if ($resolvedPath === null) {
            return json_encode([
                'success' => false,
                'error' => 'Invalid path: access outside of project working directory is not allowed.',
            ]);
        }

        return match ($action) {
            'write' => $this->writeFile($resolvedPath, $request['content'] ?? ''),
            'replace' => $this->replaceInFile($resolvedPath, $request['old_string'] ?? '', $request['new_string'] ?? ''),
            'mkdir' => $this->makeDirectory($resolvedPath),
            default => json_encode(['success' => false, 'error' => "Unknown action: {$action}"]),
        };
    }

    private function writeFile(string $path, string $content): string
    {
        Log::info("FileWriteTool: Writing to '{$path}'");
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return json_encode(['success' => false, 'error' => "Cannot create directory: {$dir}"]);
        }

        $bytes = @file_put_contents($path, $content);
        if ($bytes === false) {
            Log::error("FileWriteTool: Failed to write to '{$path}'");

            return json_encode(['success' => false, 'error' => "Cannot write file: {$path}"]);
        }

        Log::info("FileWriteTool: Successfully wrote {$bytes} bytes to '{$path}'");

        return json_encode(['success' => true, 'path' => $path, 'bytes_written' => $bytes]);
    }

    private function replaceInFile(string $path, string $oldString, string $newString): string
    {
        Log::info("FileWriteTool: Replacing in '{$path}'");
        if (! file_exists($path)) {
            return json_encode(['success' => false, 'error' => "File not found: {$path}"]);
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return json_encode(['success' => false, 'error' => "Cannot read file: {$path}"]);
        }

        $count = substr_count($content, $oldString);
        if ($count === 0) {
            Log::warning("FileWriteTool: old_string not found in '{$path}'");

            return json_encode(['success' => false, 'error' => 'old_string not found in file. Check exact content including whitespace.']);
        }

        if ($count > 1) {
            Log::warning("FileWriteTool: old_string found {$count} times in '{$path}'");

            return json_encode(['success' => false, 'error' => "old_string found {$count} times — provide more context to make it unique."]);
        }

        $newContent = str_replace($oldString, $newString, $content);
        @file_put_contents($path, $newContent);

        Log::info("FileWriteTool: Successfully applied 1 replacement in '{$path}'");

        return json_encode(['success' => true, 'path' => $path, 'replacements' => 1]);
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

    private function makeDirectory(string $path): string
    {
        if (is_dir($path)) {
            return json_encode(['success' => true, 'path' => $path, 'message' => 'Directory already exists']);
        }

        if (! mkdir($path, 0755, true) && ! is_dir($path)) {
            return json_encode(['success' => false, 'error' => "Cannot create directory: {$path}"]);
        }

        return json_encode(['success' => true, 'path' => $path]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: "write" (create/overwrite), "replace" (find & replace), "mkdir" (create directory).')
                ->enum(['write', 'replace', 'mkdir'])
                ->required(),
            'path' => $schema->string()
                ->description('Absolute or project-relative path to the target file or directory.')
                ->required(),
            'content' => $schema->string()
                ->description('Full file content for the "write" action.'),
            'old_string' => $schema->string()
                ->description('Exact string to find for the "replace" action. Must be unique in the file.'),
            'new_string' => $schema->string()
                ->description('Replacement string for the "replace" action.'),
        ];
    }
}

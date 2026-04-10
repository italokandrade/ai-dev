<?php

namespace App\Tools;

use App\Contracts\ToolInterface;
use Illuminate\Support\Facades\File;

class FileTool implements ToolInterface
{
    public function name(): string
    {
        return 'FileTool';
    }

    public function description(): string
    {
        return 'Manipula arquivos no servidor: ler, escrever, patch (edição cirúrgica), inserir linhas, deletar, renomear e listar árvore de diretórios.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['action', 'path'],
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['read', 'write', 'patch', 'insert', 'delete', 'rename', 'tree'],
                    'description' => 'Qual ação executar no arquivo.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Caminho absoluto do arquivo ou diretório.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Conteúdo a escrever (para write) ou inserir (para insert).',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Texto a buscar no arquivo (para patch).',
                ],
                'replace' => [
                    'type' => 'string',
                    'description' => 'Texto substituto (para patch).',
                ],
                'line_number' => [
                    'type' => 'integer',
                    'description' => 'Número da linha (para insert).',
                ],
                'start_line' => [
                    'type' => 'integer',
                    'description' => 'Linha inicial para leitura parcial (para read).',
                ],
                'end_line' => [
                    'type' => 'integer',
                    'description' => 'Linha final para leitura parcial (para read).',
                ],
                'new_path' => [
                    'type' => 'string',
                    'description' => 'Novo caminho (para rename).',
                ],
                'max_depth' => [
                    'type' => 'integer',
                    'description' => 'Profundidade máxima da árvore (para tree). Padrão: 3.',
                    'default' => 3,
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
                'content' => ['type' => 'string'],
                'lines_count' => ['type' => 'integer'],
                'size_bytes' => ['type' => 'integer'],
            ],
        ];
    }

    public function execute(array $params): ToolResult
    {
        $action = $params['action'];
        $path = $params['path'];

        return match ($action) {
            'read' => $this->read($path, $params['start_line'] ?? null, $params['end_line'] ?? null),
            'write' => $this->write($path, $params['content'] ?? ''),
            'patch' => $this->patch($path, $params['search'] ?? '', $params['replace'] ?? ''),
            'insert' => $this->insert($path, $params['line_number'] ?? 1, $params['content'] ?? ''),
            'delete' => $this->delete($path),
            'rename' => $this->rename($path, $params['new_path'] ?? ''),
            'tree' => $this->tree($path, $params['max_depth'] ?? 3),
            default => ToolResult::fail("Unknown action: {$action}"),
        };
    }

    private function read(string $path, ?int $startLine, ?int $endLine): ToolResult
    {
        if (! File::exists($path)) {
            return ToolResult::fail("File not found: {$path}");
        }

        $content = File::get($path);
        $lines = explode("\n", $content);
        $totalLines = count($lines);

        if ($startLine !== null || $endLine !== null) {
            $start = max(1, $startLine ?? 1) - 1;
            $end = min($totalLines, $endLine ?? $totalLines);
            $lines = array_slice($lines, $start, $end - $start);
            $content = implode("\n", $lines);
        }

        return ToolResult::ok([
            'content' => mb_substr($content, 0, 100000),
            'lines_count' => $totalLines,
            'size_bytes' => File::size($path),
            'read_lines' => count($lines),
        ]);
    }

    private function write(string $path, string $content): ToolResult
    {
        $dir = dirname($path);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($path, $content);

        return ToolResult::ok([
            'message' => "File written: {$path}",
            'size_bytes' => File::size($path),
            'lines_count' => substr_count($content, "\n") + 1,
        ]);
    }

    private function patch(string $path, string $search, string $replace): ToolResult
    {
        if (! File::exists($path)) {
            return ToolResult::fail("File not found: {$path}");
        }

        $content = File::get($path);

        if (! str_contains($content, $search)) {
            return ToolResult::fail("Search string not found in {$path}. Use read action to check the current content.");
        }

        $newContent = str_replace($search, $replace, $content);
        $occurrences = substr_count($content, $search);
        File::put($path, $newContent);

        return ToolResult::ok([
            'message' => "Patched {$occurrences} occurrence(s) in {$path}",
            'occurrences' => $occurrences,
        ]);
    }

    private function insert(string $path, int $lineNumber, string $content): ToolResult
    {
        if (! File::exists($path)) {
            return ToolResult::fail("File not found: {$path}");
        }

        $lines = explode("\n", File::get($path));
        $insertAt = max(0, min($lineNumber - 1, count($lines)));
        $newLines = explode("\n", $content);

        array_splice($lines, $insertAt, 0, $newLines);
        File::put($path, implode("\n", $lines));

        return ToolResult::ok([
            'message' => "Inserted " . count($newLines) . " line(s) at line {$lineNumber} in {$path}",
        ]);
    }

    private function delete(string $path): ToolResult
    {
        if (! File::exists($path)) {
            return ToolResult::fail("File not found: {$path}");
        }

        if (File::isDirectory($path)) {
            return ToolResult::fail("Cannot delete directory with FileTool. Use ShellTool for directory operations.");
        }

        File::delete($path);

        return ToolResult::ok(['message' => "Deleted: {$path}"]);
    }

    private function rename(string $path, string $newPath): ToolResult
    {
        if (! File::exists($path)) {
            return ToolResult::fail("File not found: {$path}");
        }

        if (! $newPath) {
            return ToolResult::fail('new_path is required for rename action');
        }

        File::move($path, $newPath);

        return ToolResult::ok(['message' => "Renamed: {$path} → {$newPath}"]);
    }

    private function tree(string $path, int $maxDepth): ToolResult
    {
        if (! File::isDirectory($path)) {
            return ToolResult::fail("Directory not found: {$path}");
        }

        $tree = $this->buildTree($path, $maxDepth, 0);

        return ToolResult::ok([
            'tree' => $tree,
            'path' => $path,
        ]);
    }

    private function buildTree(string $path, int $maxDepth, int $currentDepth): array
    {
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        $items = [];
        $entries = File::files($path);
        $dirs = File::directories($path);

        foreach ($dirs as $dir) {
            $name = basename($dir);
            if (in_array($name, ['vendor', 'node_modules', '.git', '.idea'])) {
                continue;
            }
            $items[] = [
                'name' => $name . '/',
                'type' => 'directory',
                'children' => $this->buildTree($dir, $maxDepth, $currentDepth + 1),
            ];
        }

        foreach ($entries as $file) {
            $items[] = [
                'name' => $file->getFilename(),
                'type' => 'file',
                'size' => $file->getSize(),
            ];
        }

        return $items;
    }
}

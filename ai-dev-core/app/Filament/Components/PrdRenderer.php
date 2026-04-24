<?php

namespace App\Filament\Components;

use Illuminate\Support\HtmlString;

class PrdRenderer
{
    public static function render(array $prd): HtmlString
    {
        $html = '<div class="space-y-6 text-sm text-gray-800 dark:text-gray-200">';

        // Objective
        if ($v = $prd['objective'] ?? null) {
            $html .= self::section('Objetivo', '<p class="leading-relaxed">'.e($v).'</p>');
        }

        // Scope summary (project PRD)
        if ($v = $prd['scope_summary'] ?? null) {
            $html .= self::section('Escopo', '<p class="leading-relaxed">'.e($v).'</p>');
        }

        // Target audience (project PRD)
        if ($v = $prd['target_audience'] ?? null) {
            $html .= self::section('Público-alvo', '<p class="leading-relaxed">'.e($v).'</p>');
        }

        // Complexity + needs_submodules badges
        $badges = '';
        if ($v = $prd['estimated_complexity'] ?? null) {
            $badges .= self::badge(e($v), 'blue');
        }
        if (isset($prd['needs_submodules'])) {
            $label = $prd['needs_submodules'] ? 'Tem submódulos' : 'Nó folha (tasks)';
            $color = $prd['needs_submodules'] ? 'purple' : 'green';
            $badges .= self::badge($label, $color);
        }
        if ($badges) {
            $html .= '<div class="flex flex-wrap gap-2">'.$badges.'</div>';
        }

        // Modules (project PRD)
        if ($modules = $prd['modules'] ?? []) {
            $rows = '';
            foreach ($modules as $m) {
                $name = e($m['name'] ?? '');
                $desc = e($m['description'] ?? '');
                $priority = e($m['priority'] ?? '');
                $deps = implode(', ', array_map('e', (array) ($m['dependencies'] ?? [])));
                $priorityBadge = self::priorityBadge($priority);
                $rows .= "<tr class=\"border-t border-gray-200 dark:border-gray-700\">
                    <td class=\"py-2 pr-3 font-medium align-top\">{$name}</td>
                    <td class=\"py-2 pr-3 text-gray-600 dark:text-gray-400 align-top\">{$desc}</td>
                    <td class=\"py-2 pr-3 align-top\">{$priorityBadge}</td>
                    <td class=\"py-2 align-top text-gray-500 dark:text-gray-400 text-xs\">{$deps}</td>
                </tr>";
            }
            $table = '<div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <thead><tr class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <th class="pb-2 pr-3">Módulo</th>
                    <th class="pb-2 pr-3">Descrição</th>
                    <th class="pb-2 pr-3">Prioridade</th>
                    <th class="pb-2">Dependências</th>
                </tr></thead>
                <tbody>'.$rows.'</tbody>
            </table></div>';
            $html .= self::section('Módulos ('.count($modules).')', $table);
        }

        // Submodules (module PRD)
        if ($submodules = $prd['submodules'] ?? []) {
            $rows = '';
            foreach ($submodules as $s) {
                $name = e($s['name'] ?? '');
                $desc = e($s['description'] ?? '');
                $priority = e($s['priority'] ?? '');
                $priorityBadge = self::priorityBadge($priority);
                $rows .= "<tr class=\"border-t border-gray-200 dark:border-gray-700\">
                    <td class=\"py-2 pr-3 font-medium align-top\">{$name}</td>
                    <td class=\"py-2 pr-3 text-gray-600 dark:text-gray-400 align-top\">{$desc}</td>
                    <td class=\"py-2 align-top\">{$priorityBadge}</td>
                </tr>";
            }
            $table = '<div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <thead><tr class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <th class="pb-2 pr-3">Submódulo</th>
                    <th class="pb-2 pr-3">Descrição</th>
                    <th class="pb-2">Prioridade</th>
                </tr></thead>
                <tbody>'.$rows.'</tbody>
            </table></div>';
            $html .= self::section('Submódulos ('.count($submodules).')', $table);
        }

        // Components (module PRD)
        if ($components = $prd['components'] ?? []) {
            $items = '';
            foreach ($components as $c) {
                $type = e($c['type'] ?? '');
                $name = e($c['name'] ?? '');
                $desc = e($c['description'] ?? '');
                $items .= "<li class=\"border-t border-gray-200 dark:border-gray-700 py-2\">
                    <span class=\"font-medium\">{$name}</span>
                    <span class=\"ml-2 text-xs text-gray-400\">[{$type}]</span>
                    ".($desc ? "<p class=\"mt-1 text-gray-600 dark:text-gray-400\">{$desc}</p>" : '').'
                </li>';
            }
            $html .= self::section('Componentes ('.count($components).')', '<ul class="divide-y divide-transparent">'.$items.'</ul>');
        }

        // Workflows (module PRD)
        if ($workflows = $prd['workflows'] ?? []) {
            $items = '';
            foreach ($workflows as $w) {
                $name = e($w['name'] ?? '');
                $steps = collect($w['steps'] ?? [])
                    ->map(fn ($s) => e(is_array($s) ? ($s['name'] ?? json_encode($s)) : $s))
                    ->implode(' <span class="text-gray-400 mx-1">→</span> ');
                $items .= "<li class=\"border-t border-gray-200 dark:border-gray-700 py-2\">
                    <span class=\"font-medium\">{$name}</span>
                    ".($steps ? "<p class=\"mt-1 text-gray-500 dark:text-gray-400 text-xs\">{$steps}</p>" : '').'
                </li>';
            }
            $html .= self::section('Workflows ('.count($workflows).')', '<ul>'.$items.'</ul>');
        }

        // API Endpoints (module PRD)
        if ($endpoints = $prd['api_endpoints'] ?? []) {
            $rows = '';
            foreach ($endpoints as $e2) {
                $method = strtoupper(e($e2['method'] ?? ''));
                $uri = e($e2['uri'] ?? '');
                $desc = e($e2['description'] ?? '');
                $methodColor = match ($method) {
                    'GET' => 'text-green-600 dark:text-green-400',
                    'POST' => 'text-blue-600 dark:text-blue-400',
                    'PUT', 'PATCH' => 'text-yellow-600 dark:text-yellow-400',
                    'DELETE' => 'text-red-600 dark:text-red-400',
                    default => 'text-gray-500',
                };
                $rows .= "<tr class=\"border-t border-gray-200 dark:border-gray-700\">
                    <td class=\"py-2 pr-3 font-mono font-bold text-xs {$methodColor}\">{$method}</td>
                    <td class=\"py-2 pr-3 font-mono text-xs\">{$uri}</td>
                    <td class=\"py-2 text-gray-600 dark:text-gray-400\">{$desc}</td>
                </tr>";
            }
            $table = '<div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <thead><tr class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <th class="pb-2 pr-3">Método</th><th class="pb-2 pr-3">URI</th><th class="pb-2">Descrição</th>
                </tr></thead>
                <tbody>'.$rows.'</tbody>
            </table></div>';
            $html .= self::section('API Endpoints ('.count($endpoints).')', $table);
        }

        // Database schema (module PRD)
        if ($tables = $prd['database_schema']['tables'] ?? []) {
            $items = '';
            foreach ($tables as $t) {
                $name = e($t['name'] ?? '');
                $desc = e($t['description'] ?? '');
                $cols = $t['columns'] ?? [];
                $colList = '';
                if ($cols) {
                    $colList = '<ul class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">';
                    foreach ($cols as $col) {
                        $colName = e(is_array($col) ? ($col['name'] ?? '') : $col);
                        $colType = is_array($col) ? e($col['type'] ?? '') : '';
                        $colList .= "<li><span class=\"font-mono\">{$colName}</span>".($colType ? " <span class=\"text-gray-400\">{$colType}</span>" : '').'</li>';
                    }
                    $colList .= '</ul>';
                }
                $items .= "<li class=\"border-t border-gray-200 dark:border-gray-700 py-2\">
                    <span class=\"font-mono font-medium\">{$name}</span>
                    ".($desc ? "<span class=\"ml-2 text-gray-500 dark:text-gray-400\">{$desc}</span>" : '')."
                    {$colList}
                </li>";
            }
            $html .= self::section('Banco de Dados — '.count($tables).' tabela(s)', '<ul>'.$items.'</ul>');
        }

        // Acceptance criteria (module PRD)
        if ($criteria = $prd['acceptance_criteria'] ?? []) {
            $items = '';
            foreach ($criteria as $c) {
                $text = e(is_array($c) ? json_encode($c, JSON_UNESCAPED_UNICODE) : $c);
                $items .= "<li class=\"flex gap-2 py-1\"><span class=\"text-green-500 mt-0.5\">✓</span><span>{$text}</span></li>";
            }
            $html .= self::section('Critérios de Aceitação ('.count($criteria).')', '<ul class="space-y-1">'.$items.'</ul>');
        }

        // Non-functional requirements (project PRD)
        if ($nfrs = $prd['non_functional_requirements'] ?? []) {
            $items = '';
            foreach ($nfrs as $nfr) {
                $text = e(is_array($nfr) ? json_encode($nfr, JSON_UNESCAPED_UNICODE) : $nfr);
                $items .= "<li class=\"flex gap-2 py-1\"><span class=\"text-blue-400\">◆</span><span>{$text}</span></li>";
            }
            $html .= self::section('Requisitos Não-Funcionais', '<ul class="space-y-1">'.$items.'</ul>');
        }

        $html .= '</div>';

        return new HtmlString($html);
    }

    private static function section(string $title, string $content): string
    {
        return '<div>
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">'
            .e($title).
            '</h3>
            <div>'.$content.'</div>
        </div>';
    }

    private static function badge(string $label, string $color): string
    {
        $colors = [
            'blue' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
            'green' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
            'purple' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
            'red' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
            'yellow' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
            'gray' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
        ];
        $cls = $colors[$color] ?? $colors['gray'];

        return '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium '.$cls.'">'.$label.'</span>';
    }

    private static function priorityBadge(string $priority): string
    {
        return match ($priority) {
            'high' => self::badge('Alta', 'red'),
            'medium' => self::badge('Média', 'yellow'),
            'low' => self::badge('Baixa', 'gray'),
            default => self::badge($priority ?: '—', 'gray'),
        };
    }
}

<?php

namespace App\Filament\Components;

use App\Services\ProjectArchitectureArtifactService;
use Illuminate\Support\HtmlString;

class BlueprintRenderer
{
    public static function render(array $bp): HtmlString
    {
        $html = '<div class="space-y-6 text-sm text-gray-800 dark:text-gray-200">';

        // Summary
        if ($v = $bp['summary'] ?? null) {
            $html .= self::section('Resumo', '<p class="leading-relaxed">'.e($v).'</p>');
        }

        // Domain model — entities
        $entities = [];
        $domainModel = $bp['domain_model'] ?? [];
        // Pode vir como array de objetos com 'entities' ou como array direto
        if (isset($domainModel['entities'])) {
            $entities = $domainModel['entities'];
        } elseif (is_array($domainModel) && isset($domainModel[0])) {
            foreach ($domainModel as $item) {
                if (isset($item['entities'])) {
                    $entities = array_merge($entities, $item['entities']);
                }
            }
        }

        if ($entities) {
            $rows = '';
            foreach ($entities as $e) {
                $name = e($e['name'] ?? '');
                $desc = e($e['description'] ?? '');
                $modules = implode(', ', array_map('e', (array) ($e['modules'] ?? [])));
                $rows .= '<tr class="border-t border-gray-200 dark:border-gray-700">
                    <td class="py-2 pr-3 font-mono font-medium align-top text-purple-700 dark:text-purple-300">'.$name.'</td>
                    <td class="py-2 pr-3 text-gray-600 dark:text-gray-400 align-top">'.$desc.'</td>
                    <td class="py-2 text-xs text-gray-500 dark:text-gray-400 align-top">'.$modules.'</td>
                </tr>';
            }
            $table = '<div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <thead><tr class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <th class="pb-2 pr-3">Entidade</th>
                    <th class="pb-2 pr-3">Descrição</th>
                    <th class="pb-2">Módulos</th>
                </tr></thead>
                <tbody>'.$rows.'</tbody>
            </table></div>';
            $html .= self::section('Modelo de Domínio — '.count($entities).' entidades', $table);
        }

        $relationships = [];
        if (isset($domainModel['relationships']) && is_array($domainModel['relationships'])) {
            $relationships = $domainModel['relationships'];
        }

        if ($relationships) {
            $rows = '';
            foreach ($relationships as $r) {
                $source = e($r['source'] ?? '');
                $type = e($r['type'] ?? '');
                $target = e($r['target'] ?? '');
                $foreignKey = e($r['foreign_key'] ?? '');
                $description = e($r['description'] ?? '');
                $rows .= '<tr class="border-t border-gray-200 dark:border-gray-700">
                    <td class="py-2 pr-3 font-mono align-top text-purple-700 dark:text-purple-300">'.$source.'</td>
                    <td class="py-2 pr-3 font-mono text-xs align-top text-gray-500 dark:text-gray-400">'.$type.'</td>
                    <td class="py-2 pr-3 font-mono align-top text-purple-700 dark:text-purple-300">'.$target.'</td>
                    <td class="py-2 pr-3 font-mono text-xs align-top text-gray-500 dark:text-gray-400">'.$foreignKey.'</td>
                    <td class="py-2 text-gray-600 dark:text-gray-400 align-top">'.$description.'</td>
                </tr>';
            }

            $table = '<div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <thead><tr class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <th class="pb-2 pr-3">Origem</th>
                    <th class="pb-2 pr-3">Tipo</th>
                    <th class="pb-2 pr-3">Destino</th>
                    <th class="pb-2 pr-3">FK</th>
                    <th class="pb-2">Descrição</th>
                </tr></thead>
                <tbody>'.$rows.'</tbody>
            </table></div>';
            $html .= self::section('Relacionamentos — '.count($relationships), $table);
        }

        if ($entities || $relationships) {
            $mermaid = app(ProjectArchitectureArtifactService::class)->mermaidFromBlueprint($bp);
            $html .= self::section(
                'Mermaid ERD',
                '<pre class="overflow-x-auto rounded-md bg-gray-950 p-3 text-xs leading-relaxed text-gray-100"><code>'.e($mermaid).'</code></pre>',
            );
        }

        // Use cases
        if ($useCases = $bp['use_cases'] ?? []) {
            $rows = '';
            foreach ($useCases as $uc) {
                $name = e($uc['name'] ?? '');
                $actor = e($uc['actor'] ?? '');
                $goal = e($uc['goal'] ?? '');
                $modules = implode(', ', array_map('e', (array) ($uc['modules'] ?? [])));
                $rows .= '<tr class="border-t border-gray-200 dark:border-gray-700">
                    <td class="py-2 pr-3 font-medium align-top">'.$name.'</td>
                    <td class="py-2 pr-3 align-top text-blue-600 dark:text-blue-400 whitespace-nowrap">'.$actor.'</td>
                    <td class="py-2 pr-3 text-gray-600 dark:text-gray-400 align-top">'.$goal.'</td>
                    <td class="py-2 text-xs text-gray-500 dark:text-gray-400 align-top">'.$modules.'</td>
                </tr>';
            }
            $table = '<div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <thead><tr class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <th class="pb-2 pr-3">Caso de Uso</th>
                    <th class="pb-2 pr-3">Ator</th>
                    <th class="pb-2 pr-3">Objetivo</th>
                    <th class="pb-2">Módulos</th>
                </tr></thead>
                <tbody>'.$rows.'</tbody>
            </table></div>';
            $html .= self::section('Casos de Uso ('.count($useCases).')', $table);
        }

        // Workflows
        if ($workflows = $bp['workflows'] ?? []) {
            $items = '';
            foreach ($workflows as $w) {
                $name = e($w['name'] ?? '');
                $desc = e($w['description'] ?? '');
                $modules = implode(', ', array_map('e', (array) ($w['modules'] ?? [])));
                $steps = collect($w['steps'] ?? [])
                    ->map(fn ($s) => e(is_array($s) ? ($s['name'] ?? json_encode($s)) : $s))
                    ->map(fn ($s, $i) => '<span class="inline-flex items-center gap-1"><span class="text-xs font-mono text-gray-400">'.($i + 1).'.</span>'.$s.'</span>')
                    ->implode('<span class="text-gray-400 mx-1">→</span>');
                $items .= '<li class="border-t border-gray-200 dark:border-gray-700 py-3">
                    <div class="font-medium">'.$name.'</div>
                    '.($desc ? '<div class="mt-1 text-gray-600 dark:text-gray-400">'.$desc.'</div>' : '').'
                    '.($modules ? '<div class="mt-1 text-xs text-gray-500">'.$modules.'</div>' : '').'
                    '.($steps ? '<div class="mt-2 text-xs leading-relaxed flex flex-wrap gap-y-1">'.$steps.'</div>' : '').'
                </li>';
            }
            $html .= self::section('Workflows ('.count($workflows).')', '<ul>'.$items.'</ul>');
        }

        // API Surface
        if ($apis = $bp['api_surface'] ?? []) {
            $items = '';
            foreach ($apis as $a) {
                $name = e($a['name'] ?? '');
                $purpose = e($a['purpose'] ?? '');
                $consumers = implode(', ', array_map('e', (array) ($a['consumers'] ?? [])));
                $modules = implode(', ', array_map('e', (array) ($a['modules'] ?? [])));
                $items .= '<li class="border-t border-gray-200 dark:border-gray-700 py-2">
                    <span class="font-medium">'.$name.'</span>
                    '.($purpose ? '<p class="mt-1 text-gray-600 dark:text-gray-400">'.$purpose.'</p>' : '').'
                    '.($consumers ? '<p class="mt-1 text-xs text-gray-500">Consumidores: '.$consumers.'</p>' : '').'
                    '.($modules ? '<p class="text-xs text-gray-500">Módulos: '.$modules.'</p>' : '').'
                </li>';
            }
            $html .= self::section('Superfície de API ('.count($apis).')', '<ul>'.$items.'</ul>');
        }

        // Non-functional decisions
        if ($nfds = $bp['non_functional_decisions'] ?? []) {
            $items = '';
            foreach ($nfds as $nfd) {
                $text = is_array($nfd)
                    ? e($nfd['decision'] ?? $nfd['name'] ?? json_encode($nfd, JSON_UNESCAPED_UNICODE))
                    : e($nfd);
                $items .= '<li class="flex gap-2 py-1"><span class="text-blue-400 mt-0.5">◆</span><span>'.$text.'</span></li>';
            }
            $html .= self::section('Decisões Não-Funcionais ('.count($nfds).')', '<ul class="space-y-1">'.$items.'</ul>');
        }

        // Open questions
        if ($oqs = $bp['open_questions'] ?? []) {
            $items = '';
            foreach ($oqs as $oq) {
                $text = is_array($oq)
                    ? e($oq['question'] ?? $oq['name'] ?? json_encode($oq, JSON_UNESCAPED_UNICODE))
                    : e($oq);
                $items .= '<li class="flex gap-2 py-1"><span class="text-yellow-500 mt-0.5">?</span><span>'.$text.'</span></li>';
            }
            $html .= self::section('Questões em Aberto', '<ul class="space-y-1">'.$items.'</ul>');
        }

        // Module notes
        if ($notes = $bp['module_notes'] ?? []) {
            $items = '';
            foreach ($notes as $note) {
                $text = is_array($note) ? e(json_encode($note, JSON_UNESCAPED_UNICODE)) : e($note);
                $items .= '<li class="py-1 border-t border-gray-200 dark:border-gray-700">'.$text.'</li>';
            }
            $html .= self::section('Notas de Módulo', '<ul>'.$items.'</ul>');
        }

        $html .= '</div>';

        return new HtmlString($html);
    }

    private static function section(string $title, string $content): string
    {
        return '<div>
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">'.e($title).'</h3>
            <div>'.$content.'</div>
        </div>';
    }
}

<div class="space-y-6">
    @if(!empty($prd['title']))
        <div>
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ $prd['title'] }}</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">Complexidade: <span class="font-semibold">{{ $prd['estimated_complexity'] ?? 'N/A' }}</span> | Horas estimadas: <span class="font-semibold">{{ $prd['estimated_hours'] ?? 'N/A' }}h</span></p>
        </div>
    @endif

    @if(!empty($prd['objective']))
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">🎯 Objetivo</h4>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $prd['objective'] }}</p>
        </div>
    @endif

    @if(!empty($prd['scope']))
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">📋 Escopo</h4>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $prd['scope'] }}</p>
        </div>
    @endif

    @if(!empty($prd['database_schema']['tables']))
        <div>
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">🗄️ Schema do Banco de Dados</h4>
            <div class="space-y-4">
                @foreach($prd['database_schema']['tables'] as $table)
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                        <div class="bg-gray-100 dark:bg-gray-700 px-4 py-2">
                            <span class="font-mono text-sm font-bold text-gray-800 dark:text-gray-200">{{ $table['name'] }}</span>
                            @if(!empty($table['description']))
                                <span class="text-xs text-gray-500 dark:text-gray-400 ml-2">— {{ $table['description'] }}</span>
                            @endif
                        </div>
                        @if(!empty($table['columns']))
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Coluna</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Tipo</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Obrigatório</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Descrição</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($table['columns'] as $col)
                                        <tr>
                                            <td class="px-4 py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $col['name'] }}</td>
                                            <td class="px-4 py-2 text-xs text-gray-600 dark:text-gray-400">{{ $col['type'] }}</td>
                                            <td class="px-4 py-2 text-xs">{{ ($col['nullable'] ?? true) ? '❌' : '✅' }}</td>
                                            <td class="px-4 py-2 text-xs text-gray-600 dark:text-gray-400">{{ $col['description'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                        @if(!empty($table['relations']))
                            <div class="px-4 py-2 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">
                                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Relações:</span>
                                @foreach($table['relations'] as $rel)
                                    <span class="text-xs text-gray-600 dark:text-gray-400 ml-2">{{ $rel['type'] }} → {{ $rel['table'] }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if(!empty($prd['api_endpoints']))
        <div>
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">🔌 APIs e Endpoints</h4>
            <div class="space-y-2">
                @foreach($prd['api_endpoints'] as $endpoint)
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="px-2 py-0.5 text-xs font-bold rounded bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">{{ $endpoint['method'] }}</span>
                            <span class="font-mono text-sm text-gray-800 dark:text-gray-200">{{ $endpoint['uri'] }}</span>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400">{{ $endpoint['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if(!empty($prd['business_rules']))
        <div>
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">⚖️ Regras de Negócio</h4>
            <ol class="list-decimal list-inside space-y-1">
                @foreach($prd['business_rules'] as $rule)
                    <li class="text-sm text-gray-600 dark:text-gray-400">{{ $rule }}</li>
                @endforeach
            </ol>
        </div>
    @endif

    @if(!empty($prd['components']))
        <div>
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">🧩 Componentes</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach($prd['components'] as $comp)
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="px-2 py-0.5 text-xs rounded bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">{{ $comp['type'] ?? 'Component' }}</span>
                            <span class="font-mono text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $comp['name'] }}</span>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mb-2">{{ $comp['description'] ?? '—' }}</p>
                        @if(!empty($comp['responsibilities']))
                            <ul class="list-disc list-inside text-xs text-gray-500 dark:text-gray-400">
                                @foreach($comp['responsibilities'] as $resp)
                                    <li>{{ $resp }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if(!empty($prd['workflows']))
        <div>
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">🔄 Fluxos de Trabalho</h4>
            @foreach($prd['workflows'] as $wf)
                <div class="mb-3">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200 mb-1">{{ $wf['name'] }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach($wf['steps'] as $i => $step)
                            <span class="px-2 py-1 text-xs bg-gray-100 dark:bg-gray-700 rounded text-gray-700 dark:text-gray-300">{{ $i + 1 }}. {{ $step }}</span>
                            @if(!$loop->last)
                                <span class="text-gray-400">→</span>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if(!empty($prd['acceptance_criteria']))
        <div>
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">✅ Critérios de Aceitação</h4>
            <ol class="list-decimal list-inside space-y-1">
                @foreach($prd['acceptance_criteria'] as $criteria)
                    <li class="text-sm text-gray-600 dark:text-gray-400">{{ $criteria }}</li>
                @endforeach
            </ol>
        </div>
    @endif

    @if(!empty($prd['non_functional_requirements']))
        <div>
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">🔒 Requisitos Não-Funcionais</h4>
            <div class="flex flex-wrap gap-2">
                @foreach($prd['non_functional_requirements'] as $nfr)
                    <span class="px-2 py-1 text-xs bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200 rounded">{{ $nfr }}</span>
                @endforeach
            </div>
        </div>
    @endif
</div>

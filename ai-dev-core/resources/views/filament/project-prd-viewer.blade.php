<div class="space-y-6">

    {{-- Cabeçalho --}}
    @if(!empty($prd['title']))
        <div>
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ $prd['title'] }}</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Complexidade: <span class="font-semibold">{{ $prd['estimated_complexity'] ?? 'N/A' }}</span>
            </p>
        </div>
    @endif

    {{-- Objetivo --}}
    @if(!empty($prd['objective']))
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
            <h4 class="text-sm font-semibold text-blue-700 dark:text-blue-300 mb-2">🎯 Objetivo</h4>
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ $prd['objective'] }}</p>
        </div>
    @endif

    {{-- Resumo do Escopo --}}
    @if(!empty($prd['scope_summary']))
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">📋 Resumo do Escopo</h4>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $prd['scope_summary'] }}</p>
        </div>
    @endif

    {{-- Público-Alvo --}}
    @if(!empty($prd['target_audience']))
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">👥 Público-Alvo</h4>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $prd['target_audience'] }}</p>
        </div>
    @endif

    {{-- Módulos --}}
    @if(!empty($prd['modules']))
        <div>
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
                🧩 Módulos de Alto Nível
                <span class="ml-2 px-2 py-0.5 text-xs bg-gray-200 dark:bg-gray-700 rounded-full text-gray-600 dark:text-gray-300">
                    {{ count($prd['modules']) }} módulos
                </span>
            </h4>
            <div class="space-y-3">
                @foreach($prd['modules'] as $i => $module)
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-400 dark:text-gray-500 font-mono">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span>
                                <span class="font-semibold text-sm text-gray-800 dark:text-gray-200">{{ $module['name'] }}</span>
                            </div>
                            @php
                                $priorityColor = match($module['priority'] ?? 'normal') {
                                    'high'   => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                                    'medium' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
                                    default  => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                                };
                            @endphp
                            <span class="px-2 py-0.5 text-xs rounded font-medium {{ $priorityColor }}">
                                {{ $module['priority'] ?? 'normal' }}
                            </span>
                        </div>

                        @if(!empty($module['description']))
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $module['description'] }}</p>
                        @endif

                        @if(!empty($module['dependencies']))
                            <div class="mt-2 flex flex-wrap gap-1">
                                <span class="text-xs text-gray-400 dark:text-gray-500">Depende de:</span>
                                @foreach($module['dependencies'] as $dep)
                                    <span class="px-2 py-0.5 text-xs bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300 rounded">{{ $dep }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Requisitos Não-Funcionais --}}
    @if(!empty($prd['non_functional_requirements']))
        <div>
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">🔒 Requisitos Não-Funcionais</h4>
            <div class="flex flex-wrap gap-2">
                @foreach($prd['non_functional_requirements'] as $nfr)
                    <span class="px-2 py-1 text-xs bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200 rounded">{{ $nfr }}</span>
                @endforeach
            </div>
        </div>
    @endif

</div>

<x-filament-widgets::widget>
    @if ($developmentEnabled)
        <div class="rounded-xl border border-success-300 bg-success-50 px-5 py-3 dark:border-success-700 dark:bg-success-950">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="relative flex h-3 w-3">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success-400 opacity-75"></span>
                        <span class="relative inline-flex h-3 w-3 rounded-full bg-success-500"></span>
                    </span>
                    <span class="text-sm font-semibold text-success-700 dark:text-success-300">
                        Desenvolvimento LIBERADO — Os agentes de IA estão ativos e podem executar tasks.
                    </span>
                </div>
                <a href="{{ route('filament.admin.pages.system-settings-page') }}"
                   class="text-xs font-medium text-success-600 underline hover:text-success-800 dark:text-success-400">
                    Gerenciar
                </a>
            </div>
        </div>
    @else
        <div class="rounded-xl border border-warning-300 bg-warning-50 px-5 py-3 dark:border-warning-700 dark:bg-warning-950">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <x-filament::icon
                        icon="heroicon-o-pause-circle"
                        class="h-5 w-5 text-warning-500"
                    />
                    <span class="text-sm font-semibold text-warning-700 dark:text-warning-300">
                        Desenvolvimento PAUSADO — Agentes não executarão nenhuma task até ser liberado.
                    </span>
                </div>
                <a href="{{ route('filament.admin.pages.system-settings-page') }}"
                   class="text-xs font-medium text-warning-600 underline hover:text-warning-800 dark:text-warning-400">
                    Liberar
                </a>
            </div>
        </div>
    @endif
</x-filament-widgets::widget>

<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Status Banner --}}
        @if ($developmentEnabled)
            <div class="rounded-xl border border-success-200 bg-success-50 p-6 dark:border-success-800 dark:bg-success-950">
                <div class="flex items-center gap-4">
                    <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-success-100 dark:bg-success-900">
                        <x-filament::icon
                            icon="heroicon-o-play-circle"
                            class="h-7 w-7 text-success-600 dark:text-success-400"
                        />
                    </div>
                    <div>
                        <p class="text-lg font-semibold text-success-700 dark:text-success-300">
                            Desenvolvimento LIBERADO
                        </p>
                        <p class="text-sm text-success-600 dark:text-success-400">
                            Os agentes de IA estão autorizados a trabalhar nas tasks pendentes. Novos jobs de orquestração serão iniciados automaticamente.
                        </p>
                    </div>
                </div>
            </div>
        @else
            <div class="rounded-xl border border-danger-200 bg-danger-50 p-6 dark:border-danger-800 dark:bg-danger-950">
                <div class="flex items-center gap-4">
                    <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-danger-100 dark:bg-danger-900">
                        <x-filament::icon
                            icon="heroicon-o-pause-circle"
                            class="h-7 w-7 text-danger-600 dark:text-danger-400"
                        />
                    </div>
                    <div>
                        <p class="text-lg font-semibold text-danger-700 dark:text-danger-300">
                            Desenvolvimento PAUSADO
                        </p>
                        <p class="text-sm text-danger-600 dark:text-danger-400">
                            Nenhum agente de IA será executado. Você pode configurar projetos, especificações, módulos e tasks livremente sem risco de execução acidental.
                        </p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Explicação --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h3 class="mb-4 text-base font-semibold text-gray-900 dark:text-white">Como funciona esta configuração</h3>
            <ul class="space-y-3 text-sm text-gray-600 dark:text-gray-400">
                <li class="flex items-start gap-2">
                    <x-filament::icon icon="heroicon-o-shield-check" class="mt-0.5 h-4 w-4 flex-shrink-0 text-primary-500" />
                    <span><strong>Pausado (padrão):</strong> Você pode criar projetos, gerar especificações, aprovar módulos e configurar tasks à vontade. Nenhum agente executará código.</span>
                </li>
                <li class="flex items-start gap-2">
                    <x-filament::icon icon="heroicon-o-bolt" class="mt-0.5 h-4 w-4 flex-shrink-0 text-success-500" />
                    <span><strong>Liberado:</strong> O Orchestrator, Specialist Agent e QA Auditor passam a trabalhar automaticamente nas tasks com status <em>Pending</em>.</span>
                </li>
                <li class="flex items-start gap-2">
                    <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 h-4 w-4 flex-shrink-0 text-warning-500" />
                    <span><strong>Geração de specs e orçamentos</strong> funciona <em>sempre</em> — independente desta flag. Apenas execução de código (OrchestratorJob, ProcessSubtaskJob, QAAuditJob) é bloqueada.</span>
                </li>
            </ul>
        </div>

        {{-- Status atual --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h3 class="mb-1 text-base font-semibold text-gray-900 dark:text-white">Controle de execução</h3>
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                Use os botões no topo da página para liberar ou pausar os agentes a qualquer momento.
            </p>
            <div class="flex items-center gap-3">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Status atual:</span>
                @if ($developmentEnabled)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-success-100 px-3 py-1 text-xs font-semibold text-success-700 dark:bg-success-900 dark:text-success-300">
                        <span class="h-2 w-2 rounded-full bg-success-500 animate-pulse"></span>
                        Agentes Ativos
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                        <span class="h-2 w-2 rounded-full bg-gray-400"></span>
                        Agentes Pausados
                    </span>
                @endif
            </div>
        </div>

    </div>
</x-filament-panels::page>

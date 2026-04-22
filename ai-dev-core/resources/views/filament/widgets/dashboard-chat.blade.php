<x-filament-widgets::widget>
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-header flex items-center gap-3 px-6 py-4 border-b border-gray-100 dark:border-white/5">
            <div class="flex flex-1 items-center gap-3">
                <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="h-6 w-6 text-primary-500" />
                <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    Assistente do Sistema
                </h2>
            </div>
            <x-filament::button wire:click="clearChat" color="gray" size="sm" variant="ghost">
                Limpar
            </x-filament::button>
        </div>

        <div class="p-6">
            <div class="flex flex-col gap-6" style="height: 500px;">
                {{-- Chat Area --}}
                <div id="chat-container" class="flex-1 overflow-y-auto space-y-6 p-6 bg-gray-50 dark:bg-gray-950/50 rounded-xl border border-gray-100 dark:border-white/5 custom-scrollbar"
                     x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                     x-on:scroll-chat.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                >
                    @foreach($history as $msg)
                        <div class="flex {{ $msg['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[85%] rounded-2xl px-6 py-3 shadow-sm
                                {{ $msg['role'] === 'user' 
                                    ? 'bg-primary-600 text-white rounded-tr-none' 
                                    : 'bg-white dark:bg-gray-800 text-gray-950 dark:text-white rounded-tl-none ring-1 ring-gray-950/5 dark:ring-white/10' 
                                }}">
                                <p class="text-[10px] font-bold uppercase tracking-wider opacity-60 mb-1">
                                    {{ $msg['role'] === 'user' ? 'Você' : 'Assistente' }}
                                </p>
                                <div class="text-sm leading-relaxed prose dark:prose-invert max-w-none">
                                    {!! nl2br(e($msg['content'])) !!}
                                </div>
                            </div>
                        </div>
                    @endforeach

                    @if($isProcessing)
                        <div class="flex justify-start">
                            <div class="flex items-center gap-3 px-4 py-2 bg-gray-100 dark:bg-gray-800 rounded-full animate-pulse">
                                <x-filament::loading-indicator class="h-4 w-4 text-primary-500" />
                                <span class="text-xs text-gray-500 dark:text-gray-400">Pensando...</span>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Input Area --}}
                <div class="flex flex-col gap-4">
                    <div style="width: 100%;">
                        <textarea
                            wire:model="message"
                            wire:keydown.enter.prevent="sendMessage"
                            placeholder="Como posso te ajudar hoje?"
                            rows="3"
                            style="width: 100%; display: block; border-radius: 0.75rem; border: 1px solid #e5e7eb; padding: 1rem; font-size: 1rem; line-height: 1.5;"
                            class="dark:bg-gray-800 dark:border-white/10 dark:text-white"
                            :disabled="$isProcessing"
                        ></textarea>
                    </div>
                    
                    <div class="flex justify-end">
                        <x-filament::button 
                            wire:click="sendMessage" 
                            :disabled="$isProcessing"
                            size="lg"
                            class="px-10"
                            style="border-radius: 0.75rem;"
                        >
                            Enviar Pergunta
                        </x-filament::button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; }
    </style>
</x-filament-widgets::widget>

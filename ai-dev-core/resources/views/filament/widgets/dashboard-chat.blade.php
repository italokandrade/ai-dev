<x-filament-widgets::widget>
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-header flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-white/5">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-primary-50 dark:bg-primary-500/10 rounded-lg">
                    <x-filament::icon icon="heroicon-o-sparkles" class="h-5 w-5 text-primary-500" />
                </div>
                <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    Assistente AI-Dev
                </h2>
            </div>
            <x-filament::button wire:click="clearChat" color="gray" size="sm" variant="ghost" icon="heroicon-m-trash">
                Limpar
            </x-filament::button>
        </div>

        <div class="flex flex-col" style="height: 450px;">
            {{-- Chat Area --}}
            <div id="chat-container" class="flex-1 overflow-y-auto p-6 space-y-6 custom-scrollbar bg-gray-50/30 dark:bg-gray-950/20"
                 x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                 x-on:scroll-chat.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
            >
                @foreach($history as $msg)
                    <div class="flex {{ $msg['role'] === 'user' ? 'justify-end' : 'justify-start' }} animate-fade-in-up">
                        <div class="max-w-[85%] px-5 py-3 shadow-sm flex flex-col gap-1
                            {{ $msg['role'] === 'user' 
                                ? 'bg-primary-600 text-white rounded-2xl rounded-tr-sm' 
                                : 'bg-white dark:bg-gray-800 text-gray-950 dark:text-gray-100 rounded-2xl rounded-tl-sm ring-1 ring-gray-950/5 dark:ring-white/10' 
                            }}">
                            <span class="text-[10px] font-bold uppercase tracking-wider opacity-60">
                                {{ $msg['role'] === 'user' ? 'Você' : 'Assistente' }}
                            </span>
                            <div class="text-sm leading-relaxed prose dark:prose-invert max-w-none">
                                {!! nl2br(e($msg['content'])) !!}
                            </div>
                        </div>
                    </div>
                @endforeach

                @if($isProcessing)
                    <div class="flex justify-start animate-pulse">
                        <div class="px-5 py-3 bg-white dark:bg-gray-800 rounded-2xl rounded-tl-sm ring-1 ring-gray-950/5 dark:ring-white/10 shadow-sm flex items-center gap-3">
                            <x-filament::loading-indicator class="h-4 w-4 text-primary-500" />
                            <span class="text-xs text-gray-500 dark:text-gray-400 font-medium">Processando...</span>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Input Area --}}
            <div class="p-4 border-t border-gray-100 dark:border-white/5 bg-white dark:bg-gray-900 rounded-b-xl">
                <div class="relative flex items-end gap-2">
                    <textarea
                        wire:model="message"
                        wire:keydown.enter.prevent="sendMessage"
                        placeholder="Faça uma pergunta sobre o projeto..."
                        rows="1"
                        class="w-full pl-5 pr-12 py-3 bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded-full focus:ring-2 focus:ring-primary-500/50 focus:border-primary-500 resize-none transition-all text-sm shadow-inner"
                        style="min-height: 46px; max-height: 120px;"
                        :disabled="$isProcessing"
                        x-data="{ resize() { $el.style.height = '46px'; $el.style.height = $el.scrollHeight + 'px' } }"
                        x-init="resize()"
                        @input="resize()"
                    ></textarea>
                    
                    <button 
                        wire:click="sendMessage" 
                        :disabled="$isProcessing"
                        class="absolute right-1.5 bottom-1.5 p-2 rounded-full bg-primary-600 text-white hover:bg-primary-500 transition-all shadow-md disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center group"
                    >
                        <x-filament::icon icon="heroicon-m-paper-airplane" class="w-4 h-4 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
                    </button>
                </div>
            </div>
        </div>
    </div>

    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; }
        .custom-scrollbar:hover::-webkit-scrollbar-thumb { background: #cbd5e1; }
        .dark .custom-scrollbar:hover::-webkit-scrollbar-thumb { background: #475569; }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in-up {
            animation: fadeInUp 0.3s ease-out forwards;
        }
    </style>
</x-filament-widgets::widget>

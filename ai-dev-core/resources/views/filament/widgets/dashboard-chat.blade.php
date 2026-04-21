<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-chat-bubble-left-right" icon-color="primary" class="shadow-md overflow-hidden">
        <x-slot name="heading">
            <span class="text-xl font-bold tracking-tight text-gray-800 dark:text-gray-100">Assistente Inteligente do Sistema</span>
        </x-slot>

        <x-slot name="headerEnd">
            <x-filament::button wire:click="clearChat" color="gray" size="sm" icon="heroicon-o-trash" variant="ghost" class="hover:bg-gray-100 dark:hover:bg-gray-800">
                Limpar Conversa
            </x-filament::button>
        </x-slot>

        <div class="flex flex-col h-[700px] w-full">
            {{-- Área de Mensagens --}}
            <div id="chat-container" class="flex-1 overflow-y-auto space-y-8 p-8 bg-gray-50/80 dark:bg-gray-900/40 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-inner custom-scrollbar"
                 x-init="
                    $nextTick(() => { $el.scrollTop = $el.scrollHeight });
                 "
                 x-on:scroll-chat.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
            >
                @foreach($history as $msg)
                    <div class="flex {{ $msg['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[80%] shadow-md 
                            {{ $msg['role'] === 'user' 
                                ? 'bg-primary-600 text-white rounded-[2rem] rounded-tr-none' 
                                : 'bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 rounded-[2rem] rounded-tl-none border border-gray-100 dark:border-gray-700' 
                            }}">
                            
                            <div class="px-8 py-5">
                                <div class="text-[10px] opacity-70 mb-3 font-black uppercase tracking-[0.2em] flex items-center gap-2">
                                    @if($msg['role'] === 'user')
                                        <x-filament::icon icon="heroicon-m-user" class="h-3 w-3" />
                                        <span>VOCÊ</span>
                                    @else
                                        <x-filament::icon icon="heroicon-m-sparkles" class="h-3 w-3" />
                                        <span>ASSISTENTE AI-DEV</span>
                                    @endif
                                </div>
                                <div class="prose dark:prose-invert prose-sm max-w-none leading-relaxed text-lg font-medium">
                                    {!! nl2br(e($msg['content'])) !!}
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach

                @if($isProcessing)
                    <div class="flex justify-start">
                        <div class="flex items-center gap-4 text-gray-500 bg-white dark:bg-gray-800 px-6 py-3 rounded-full border border-gray-100 dark:border-gray-700 shadow-md animate-pulse">
                            <x-filament::loading-indicator class="h-5 w-5 text-primary-500" />
                            <span class="font-bold text-sm uppercase tracking-widest">Investigando o sistema...</span>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Espaçamento --}}
            <div class="h-8"></div>

            {{-- Área de Input Profissional --}}
            <div class="w-full bg-white dark:bg-gray-900 rounded-[2.5rem] border-2 border-gray-100 dark:border-gray-800 shadow-xl p-3 focus-within:border-primary-500 transition-all duration-300">
                <div class="px-4 pt-2">
                    <textarea
                        wire:model="message"
                        wire:keydown.enter.prevent="sendMessage"
                        placeholder="Ex: Como eu posso cadastrar um novo usuário?"
                        rows="4"
                        class="block w-full border-0 bg-transparent p-0 text-gray-900 dark:text-gray-100 placeholder:text-gray-400 focus:ring-0 text-lg leading-relaxed resize-none"
                        :disabled="$isProcessing"
                    ></textarea>
                </div>
                
                <div class="flex items-center justify-between mt-2 pt-2 border-t border-gray-50 dark:border-gray-800 px-2 pb-1">
                    <div class="flex items-center gap-2 text-[10px] text-gray-400 uppercase font-black tracking-widest pl-4">
                        <span class="h-1.5 w-1.5 bg-green-500 rounded-full animate-ping"></span>
                        Status: Conectado à IA do Sistema
                    </div>
                    
                    <x-filament::button 
                        wire:click="sendMessage" 
                        :disabled="$isProcessing"
                        size="xl"
                        icon="heroicon-m-paper-airplane"
                        icon-position="after"
                        class="rounded-full px-10 shadow-lg shadow-primary-500/20"
                    >
                        Enviar Pergunta
                    </x-filament::button>
                </div>
            </div>
            
            <div class="mt-4 text-center">
                <span class="text-[9px] text-gray-400 uppercase font-bold tracking-[0.3em]">IA Conectada via OpenRouter • Powered by AI-Dev Master</span>
            </div>
        </div>
    </x-filament::section>

    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 10px; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #374151; }
    </style>
</x-filament-widgets::widget>

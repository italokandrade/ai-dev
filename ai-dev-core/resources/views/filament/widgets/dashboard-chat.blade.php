<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-chat-bubble-left-right" icon-color="primary" class="shadow-xl rounded-3xl border-none">
        <x-slot name="heading">
            <span class="text-2xl font-extrabold tracking-tight text-gray-900 dark:text-white">Assistente do Sistema</span>
        </x-slot>

        <x-slot name="headerEnd">
            <x-filament::button wire:click="clearChat" color="gray" size="sm" icon="heroicon-o-trash" variant="ghost" class="rounded-full">
                Limpar Histórico
            </x-filament::button>
        </x-slot>

        <div class="flex flex-col h-[750px] w-full gap-8 py-4">
            {{-- Mensagens --}}
            <div id="chat-container" class="flex-1 overflow-y-auto space-y-10 p-10 bg-slate-50/50 dark:bg-gray-900/50 rounded-[2.5rem] border border-gray-100 dark:border-gray-800 shadow-inner"
                 x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                 x-on:scroll-chat.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
            >
                @foreach($history as $msg)
                    <div class="flex {{ $msg['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[80%] shadow-lg 
                            {{ $msg['role'] === 'user' 
                                ? 'bg-primary-600 text-white rounded-[2.5rem] rounded-tr-none' 
                                : 'bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 rounded-[2.5rem] rounded-tl-none border border-gray-100 dark:border-gray-700' 
                            }}">
                            
                            <div class="px-10 py-6">
                                <div class="text-[11px] opacity-60 mb-4 font-black uppercase tracking-[0.3em] flex items-center gap-3">
                                    @if($msg['role'] === 'user')
                                        <x-filament::icon icon="heroicon-m-user" class="h-4 w-4" />
                                        <span>VOCÊ</span>
                                    @else
                                        <x-filament::icon icon="heroicon-m-sparkles" class="h-4 w-4" />
                                        <span>ASSISTENTE AI-DEV</span>
                                    @endif
                                </div>
                                <div class="prose dark:prose-invert prose-md max-w-none leading-loose text-lg">
                                    {!! nl2br(e($msg['content'])) !!}
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach

                @if($isProcessing)
                    <div class="flex justify-start">
                        <div class="flex items-center gap-5 text-gray-500 bg-white dark:bg-gray-800 px-8 py-4 rounded-full border border-gray-100 dark:border-gray-700 shadow-lg animate-pulse">
                            <x-filament::loading-indicator class="h-6 w-6 text-primary-500" />
                            <span class="font-bold text-sm uppercase tracking-widest">Processando sua pergunta...</span>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Caixa de Entrada Grande --}}
            <div class="w-full flex flex-col gap-6 bg-white dark:bg-gray-900 rounded-[3rem] border-2 border-gray-100 dark:border-gray-800 shadow-2xl p-6 focus-within:border-primary-500 transition-all duration-300">
                <div class="w-full px-4">
                    <textarea
                        wire:model="message"
                        wire:keydown.enter.prevent="sendMessage"
                        placeholder="Como eu posso te ajudar com o sistema agora?"
                        rows="4"
                        style="width: 100%; min-width: 100%; font-size: 1.125rem;"
                        class="block w-full border-0 bg-transparent p-0 text-gray-900 dark:text-gray-100 placeholder:text-gray-400 focus:ring-0 leading-relaxed resize-none"
                        :disabled="$isProcessing"
                    ></textarea>
                </div>
                
                <div class="flex items-center justify-between mt-4 pt-4 border-t border-gray-50 dark:border-gray-800 px-4">
                    <div class="flex items-center gap-3 text-xs text-gray-400 font-bold tracking-widest">
                        <span class="h-2 w-2 bg-green-500 rounded-full shadow-[0_0_8px_rgba(34,197,94,0.6)]"></span>
                        IA DO SISTEMA ATIVA
                    </div>
                    
                    <x-filament::button 
                        wire:click="sendMessage" 
                        :disabled="$isProcessing"
                        size="xl"
                        icon="heroicon-m-paper-airplane"
                        icon-position="after"
                        class="rounded-full px-12 shadow-xl shadow-primary-500/20"
                    >
                        Enviar
                    </x-filament::button>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

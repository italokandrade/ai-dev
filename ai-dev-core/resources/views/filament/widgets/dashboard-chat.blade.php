<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-chat-bubble-left-right" icon-color="primary" class="shadow-sm">
        <x-slot name="heading">
            <span class="text-xl font-bold tracking-tight">Assistente do Sistema</span>
        </x-slot>

        <x-slot name="headerEnd">
            <x-filament::button wire:click="clearChat" color="gray" size="sm" icon="heroicon-o-trash" variant="ghost">
                Limpar Conversa
            </x-filament::button>
        </x-slot>

        <div class="flex flex-col h-[650px] gap-6">
            {{-- Área de Mensagens --}}
            <div id="chat-container" class="flex-1 overflow-y-auto space-y-6 p-6 bg-gray-50/50 dark:bg-gray-900/30 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-inner"
                 x-init="
                    $nextTick(() => { $el.scrollTop = $el.scrollHeight });
                 "
                 x-on:scroll-chat.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
            >
                @foreach($history as $msg)
                    <div class="flex {{ $msg['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[85%] shadow-sm overflow-hidden
                            {{ $msg['role'] === 'user' 
                                ? 'bg-primary-600 text-white rounded-3xl rounded-tr-none' 
                                : 'bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-200 rounded-3xl rounded-tl-none border border-gray-100 dark:border-gray-700' 
                            }}">
                            
                            <div class="px-6 py-4">
                                <div class="text-[10px] opacity-60 mb-2 font-black uppercase tracking-widest flex items-center gap-2">
                                    @if($msg['role'] === 'user')
                                        <span>VOCÊ</span>
                                        <x-filament::icon icon="heroicon-m-user" class="h-3 w-3" />
                                    @else
                                        <x-filament::icon icon="heroicon-m-sparkles" class="h-3 w-3" />
                                        <span>ASSISTENTE AI-DEV</span>
                                    @endif
                                </div>
                                <div class="prose dark:prose-invert prose-sm max-w-none leading-relaxed text-base">
                                    {!! nl2br(e($msg['content'])) !!}
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach

                @if($isProcessing)
                    <div class="flex justify-start p-2">
                        <div class="flex items-center gap-3 text-gray-500 text-sm bg-white dark:bg-gray-800 px-5 py-3 rounded-full border border-gray-100 dark:border-gray-700 shadow-sm animate-pulse">
                            <x-filament::loading-indicator class="h-5 w-5 text-primary-500" />
                            <span class="font-medium">O assistente está investigando o sistema...</span>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Espaçamento entre Chat e Input --}}
            <div class="mt-2"></div>

            {{-- Área de Input Redesenhada --}}
            <div class="flex flex-col gap-4 bg-white dark:bg-gray-900 p-4 rounded-3xl border border-gray-200 dark:border-gray-700 shadow-lg focus-within:ring-2 focus-within:ring-primary-500/20 transition-all">
                <div class="w-full">
                    <textarea
                        wire:model="message"
                        wire:keydown.enter.prevent="sendMessage"
                        placeholder="Como eu cadastro um novo usuário?"
                        rows="3"
                        class="block w-full border-0 bg-transparent p-2 text-gray-900 dark:text-gray-100 placeholder:text-gray-400 focus:ring-0 sm:text-base resize-none"
                        :disabled="$isProcessing"
                    ></textarea>
                </div>
                
                <div class="flex items-center justify-between border-t border-gray-100 dark:border-gray-800 pt-3">
                    <div class="text-[10px] text-gray-400 uppercase font-bold tracking-tight px-2">
                        Utilizando modelo "IA do Sistema" com acesso ao código via BoostTool.
                    </div>
                    
                    <x-filament::button 
                        wire:click="sendMessage" 
                        :disabled="$isProcessing"
                        size="lg"
                        icon="heroicon-m-paper-airplane"
                        icon-position="after"
                        class="rounded-2xl px-8"
                    >
                        Enviar Pergunta
                    </x-filament::button>
                </div>
            </div>
            <div class="h-2"></div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-chat-bubble-left-right" icon-color="primary" class="shadow-sm">
        <x-slot name="heading">
            <span class="text-lg font-bold tracking-tight">Assistente do Sistema</span>
        </x-slot>

        <x-slot name="headerEnd">
            <x-filament::button wire:click="clearChat" color="gray" size="sm" icon="heroicon-o-trash" variant="ghost">
                Limpar Conversa
            </x-filament::button>
        </x-slot>

        <div class="flex flex-col h-[550px] gap-6 py-2">
            {{-- Área de Mensagens --}}
            <div id="chat-container" class="flex-1 overflow-y-auto space-y-6 p-6 bg-gray-50/50 dark:bg-gray-900/30 rounded-xl border border-gray-100 dark:border-gray-800"
                 x-init="
                    $nextTick(() => { $el.scrollTop = $el.scrollHeight });
                 "
                 x-on:scroll-chat.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
            >
                @foreach($history as $msg)
                    <div class="flex {{ $msg['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[85%] shadow-sm overflow-hidden
                            {{ $msg['role'] === 'user' 
                                ? 'bg-primary-600 text-white rounded-2xl rounded-tr-none' 
                                : 'bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-200 rounded-2xl rounded-tl-none border border-gray-100 dark:border-gray-700' 
                            }}">
                            
                            <div class="px-5 py-3">
                                <div class="text-[10px] opacity-60 mb-1 font-black uppercase tracking-widest flex items-center gap-1">
                                    @if($msg['role'] === 'user')
                                        <span>VOCÊ</span>
                                        <x-filament::icon icon="heroicon-m-user" class="h-3 w-3" />
                                    @else
                                        <x-filament::icon icon="heroicon-m-sparkles" class="h-3 w-3" />
                                        <span>ASSISTENTE AI-DEV</span>
                                    @endif
                                </div>
                                <div class="prose dark:prose-invert prose-sm max-w-none leading-relaxed">
                                    {!! nl2br(e($msg['content'])) !!}
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach

                @if($isProcessing)
                    <div class="flex justify-start p-2">
                        <div class="flex items-center gap-3 text-gray-400 text-sm bg-white dark:bg-gray-800 px-4 py-2 rounded-full border border-gray-100 dark:border-gray-700 shadow-sm animate-pulse">
                            <x-filament::loading-indicator class="h-4 w-4 text-primary-500" />
                            <span>Investigando o código do sistema...</span>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Área de Input --}}
            <div class="flex gap-3 items-end bg-white dark:bg-gray-900 p-2 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm focus-within:border-primary-500 transition-colors">
                <div class="flex-1">
                    <textarea
                        wire:model="message"
                        wire:keydown.enter.prevent="sendMessage"
                        placeholder="Como eu cadastro um novo usuário?"
                        rows="1"
                        class="block w-full border-0 bg-transparent py-3 px-4 text-gray-900 dark:text-gray-100 placeholder:text-gray-400 focus:ring-0 sm:text-sm sm:leading-6 resize-none"
                        :disabled="$isProcessing"
                    ></textarea>
                </div>
                <div class="pb-1 pr-1">
                    <x-filament::button 
                        wire:click="sendMessage" 
                        :disabled="$isProcessing"
                        size="md"
                        icon="heroicon-m-paper-airplane"
                        icon-position="after"
                        class="rounded-xl"
                    >
                        Perguntar
                    </x-filament::button>
                </div>
            </div>
            <div class="text-[10px] text-center text-gray-400 uppercase tracking-tighter">
                O assistente utiliza o modelo configurado em "IA do Sistema" e possui acesso de leitura ao código via Boost MCP.
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

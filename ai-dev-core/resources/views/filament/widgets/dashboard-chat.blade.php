<x-filament-widgets::widget>
    <div style="background: #ffffff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 0 0 1px rgba(0,0,0,0.05); overflow: hidden;" class="dark:bg-gray-900">

        {{-- Header --}}
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #f1f5f9;" class="dark:border-white/5">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 36px; height: 36px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="white" style="width:18px; height:18px">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                    </svg>
                </div>
                <div>
                    <div style="font-weight: 600; font-size: 14px; color: #111827;" class="dark:text-white">Assistente AI-Dev</div>
                    <div style="font-size: 11px; color: #6b7280;">Powered by Claude</div>
                </div>
            </div>
            <button wire:click="clearChat"
                style="display: flex; align-items: center; gap: 6px; padding: 6px 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: transparent; cursor: pointer; font-size: 12px; color: #6b7280; transition: all 0.15s;"
                onmouseover="this.style.background='#f9fafb'; this.style.borderColor='#d1d5db';"
                onmouseout="this.style.background='transparent'; this.style.borderColor='#e5e7eb';"
                class="dark:border-white/10 dark:text-gray-400 dark:hover:bg-white/5">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:14px;height:14px">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
                Limpar
            </button>
        </div>

        {{-- Messages Area --}}
        <div id="chat-messages"
             style="height: 340px; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 16px; background: #f8fafc;"
             class="dark:bg-gray-950/30 custom-chat-scroll"
             x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
             x-on:scroll-chat.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
        >
            @foreach($history as $msg)
                @if($msg['role'] === 'user')
                    {{-- User Message --}}
                    <div style="display: flex; justify-content: flex-end;">
                        <div style="max-width: 75%; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; padding: 10px 16px; border-radius: 18px 18px 4px 18px; font-size: 14px; line-height: 1.5; box-shadow: 0 2px 8px rgba(99,102,241,0.25);">
                            {!! nl2br(e($msg['content'])) !!}
                        </div>
                    </div>
                @else
                    {{-- Assistant Message --}}
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <div style="flex-shrink: 0; width: 30px; height: 30px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="white" style="width:15px;height:15px">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                            </svg>
                        </div>
                        <div style="max-width: 75%; background: white; color: #111827; padding: 10px 16px; border-radius: 18px 18px 18px 4px; font-size: 14px; line-height: 1.5; box-shadow: 0 1px 4px rgba(0,0,0,0.08); border: 1px solid #f1f5f9;" class="dark:bg-gray-800 dark:text-gray-100 dark:border-white/10">
                            {!! nl2br(e($msg['content'])) !!}
                        </div>
                    </div>
                @endif
            @endforeach

            {{-- Typing indicator: shown instantly via wire:loading when sendMessage is running --}}
            <div wire:loading wire:target="sendMessage" style="display: none; align-items: flex-start; gap: 10px;">
                <div style="flex-shrink: 0; width: 30px; height: 30px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="white" style="width:15px;height:15px">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                    </svg>
                </div>
                <div style="background: white; padding: 12px 18px; border-radius: 18px 18px 18px 4px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); border: 1px solid #f1f5f9; display: flex; gap: 5px; align-items: center;" class="dark:bg-gray-800 dark:border-white/10">
                    <span style="width:7px;height:7px;background:#6366f1;border-radius:50%;animation:typingBounce 1.2s ease-in-out infinite 0s;display:inline-block;"></span>
                    <span style="width:7px;height:7px;background:#6366f1;border-radius:50%;animation:typingBounce 1.2s ease-in-out infinite 0.25s;display:inline-block;"></span>
                    <span style="width:7px;height:7px;background:#6366f1;border-radius:50%;animation:typingBounce 1.2s ease-in-out infinite 0.5s;display:inline-block;"></span>
                </div>
            </div>
        </div>

        {{-- Input Area --}}
        <div style="padding: 16px 20px; border-top: 1px solid #f1f5f9; background: #ffffff;" class="dark:border-white/5 dark:bg-gray-900">
            <div wire:loading.remove wire:target="sendMessage"
                 style="display: flex; align-items: flex-end; gap: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 10px 14px; transition: border-color 0.15s; box-shadow: inset 0 1px 3px rgba(0,0,0,0.04);"
                 class="dark:bg-gray-800/50 dark:border-gray-700"
                 x-data
                 @focusin="$el.style.borderColor='#6366f1'; $el.style.boxShadow='0 0 0 3px rgba(99,102,241,0.12), inset 0 1px 3px rgba(0,0,0,0.04)';"
                 @focusout="$el.style.borderColor='#e2e8f0'; $el.style.boxShadow='inset 0 1px 3px rgba(0,0,0,0.04)';">
                <textarea
                    wire:model="message"
                    wire:keydown.enter.prevent="sendMessage"
                    placeholder="Pergunte sobre o projeto, código ou documentação..."
                    rows="1"
                    class="dark:text-white dark:placeholder-gray-500"
                    style="flex: 1; border: none; background: transparent; resize: none; outline: none; font-size: 14px; line-height: 1.5; color: #111827; min-height: 24px; max-height: 100px; padding: 0;"
                    :disabled="$isProcessing"
                    x-data="{ resize() { $el.style.height = '24px'; $el.style.height = Math.min($el.scrollHeight, 100) + 'px' } }"
                    x-init="resize()"
                    @input="resize()"
                    @keydown.enter.prevent="$wire.sendMessage()"
                ></textarea>
                <button
                    wire:click="sendMessage"
                    wire:loading.attr="disabled"
                    wire:target="sendMessage"
                    style="flex-shrink: 0; width: 36px; height: 36px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border: none; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s; box-shadow: 0 2px 6px rgba(99,102,241,0.35);"
                    onmouseover="if(!this.disabled){ this.style.transform='scale(1.08)'; this.style.boxShadow='0 4px 12px rgba(99,102,241,0.5)'; }"
                    onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 2px 6px rgba(99,102,241,0.35)';"
                >
                    {{-- Arrow icon — hidden while loading --}}
                    <svg wire:loading.remove wire:target="sendMessage"
                         xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white" style="width:16px;height:16px;">
                        <path d="M3.478 2.405a.75.75 0 0 0-.926.94l2.432 7.905H13.5a.75.75 0 0 1 0 1.5H4.984l-2.432 7.905a.75.75 0 0 0 .926.94 60.519 60.519 0 0 0 18.445-8.986.75.75 0 0 0 0-1.218A60.517 60.517 0 0 0 3.478 2.405Z" />
                    </svg>
                    {{-- Spinner — shown while loading --}}
                    <svg wire:loading wire:target="sendMessage"
                         style="width:16px;height:16px;animation:spin 1s linear infinite;display:none;"
                         viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="10" stroke="rgba(255,255,255,0.3)" stroke-width="3"/>
                        <path d="M12 2a10 10 0 0 1 10 10" stroke="white" stroke-width="3" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <style>
        .custom-chat-scroll::-webkit-scrollbar { width: 4px; }
        .custom-chat-scroll::-webkit-scrollbar-track { background: transparent; }
        .custom-chat-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .custom-chat-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        @keyframes typingBounce {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-5px); }
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</x-filament-widgets::widget>

<x-filament-widgets::widget>
    <div class="aidev-chat dark:bg-gray-900">

        {{-- Header --}}
        <div class="aidev-chat__header dark:border-white/5">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div class="aidev-chat__avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="white" style="width:18px; height:18px">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                    </svg>
                </div>
                <div>
                    <div class="aidev-chat__title dark:text-white">Assistente AI-Dev</div>
                    <div class="aidev-chat__subtitle">IA do Sistema</div>
                </div>
            </div>
            <button wire:click="clearChat" class="aidev-chat__clear-btn dark:border-white/10 dark:text-gray-400">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:14px;height:14px">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
                Limpar
            </button>
        </div>

        {{-- Messages Area --}}
        <div id="chat-messages"
             class="aidev-chat__messages dark:bg-gray-950/30"
             x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
             x-on:scroll-chat.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
        >
            @foreach($history as $msg)
                @if($msg['role'] === 'user')
                    {{-- User Message --}}
                    <div style="display: flex; justify-content: flex-end;">
                        <div class="aidev-chat__bubble aidev-chat__bubble--user">
                            {!! nl2br(e($msg['content'])) !!}
                        </div>
                    </div>
                @else
                    {{-- Assistant Message --}}
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <div class="aidev-chat__avatar aidev-chat__avatar--sm">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="white" style="width:15px;height:15px">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                            </svg>
                        </div>
                        <div class="aidev-chat__bubble aidev-chat__bubble--assistant dark:bg-gray-800 dark:text-gray-100 dark:border-white/10">
                            {!! nl2br(e($msg['content'])) !!}
                        </div>
                    </div>
                @endif
            @endforeach

            {{--
                Indicador de digitação.
                O div EXTERNO é controlado pelo wire:loading (fica display:block quando carregando).
                O div INTERNO tem display:flex fixo — assim o layout fica correto independente
                do que o wire:loading defina no elemento pai.
            --}}
            <div wire:loading wire:target="sendMessage" style="display: none;">
                <div style="display: flex; align-items: flex-start; gap: 10px;">
                    <div class="aidev-chat__avatar aidev-chat__avatar--sm">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="white" style="width:15px;height:15px">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                        </svg>
                    </div>
                    <div class="aidev-chat__typing dark:bg-gray-800 dark:border-white/10">
                        <span class="aidev-chat__dot" style="animation-delay: 0s;"></span>
                        <span class="aidev-chat__dot" style="animation-delay: 0.25s;"></span>
                        <span class="aidev-chat__dot" style="animation-delay: 0.5s;"></span>
                    </div>
                </div>
            </div>
        </div>

        {{--
            Input Area — SEMPRE VISÍVEL.
            Usa Alpine x-model (localMsg) em vez de wire:model para
            ter controle total sobre a limpeza imediata do campo.
        --}}
        <div class="aidev-chat__input-area dark:border-white/5 dark:bg-gray-900">
            <div class="aidev-chat__input-wrapper dark:bg-gray-800/50 dark:border-gray-700"
                 x-data="{
                     localMsg: '',
                     scrollChat() {
                         const chat = document.getElementById('chat-messages');
                         if (chat) chat.scrollTop = chat.scrollHeight;
                     },
                     resizeTextarea(el) {
                         el.style.height = '24px';
                         el.style.height = Math.min(el.scrollHeight, 100) + 'px';
                     },
                     sendAndScroll() {
                         if (!this.localMsg.trim()) return;
                         this.$wire.sendMessage(this.localMsg);
                         this.localMsg = '';
                         const ta = this.$el.querySelector('textarea');
                         if (ta) ta.style.height = '24px';
                         this.scrollChat();
                         setTimeout(() => this.scrollChat(), 80);
                     }
                 }"
            >
                <textarea
                    x-model="localMsg"
                    placeholder="Pergunte sobre o projeto, código ou documentação..."
                    rows="1"
                    class="aidev-chat__textarea dark:text-white dark:placeholder-gray-500"
                    @input="resizeTextarea($el)"
                    @keydown.enter.prevent="sendAndScroll()"
                ></textarea>
                <button @click="sendAndScroll()" class="aidev-chat__send-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white" style="width:16px;height:16px;">
                        <path d="M3.478 2.405a.75.75 0 0 0-.926.94l2.432 7.905H13.5a.75.75 0 0 1 0 1.5H4.984l-2.432 7.905a.75.75 0 0 0 .926.94 60.519 60.519 0 0 0 18.445-8.986.75.75 0 0 0 0-1.218A60.517 60.517 0 0 0 3.478 2.405Z" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <style>
        /* ═══════════════════════════════════════════════════════════════════
           AI-DEV DESIGN SYSTEM — Color tokens centralizados
           Todos os componentes customizados devem usar estas variáveis.
           Os valores estão alinhados com Filament Color::Indigo/Violet.
           ═══════════════════════════════════════════════════════════════════ */
        :root {
            /* Primary — Indigo (Filament primary) */
            --aidev-primary-500: #6366f1;
            --aidev-primary-600: #4f46e5;
            --aidev-primary-400: #818cf8;

            /* Secondary — Violet */
            --aidev-secondary-500: #8b5cf6;
            --aidev-secondary-600: #7c3aed;

            /* Gradient */
            --aidev-gradient: linear-gradient(135deg, var(--aidev-primary-500), var(--aidev-secondary-500));
            --aidev-gradient-shadow: rgba(99, 102, 241, 0.25);
            --aidev-gradient-shadow-hover: rgba(99, 102, 241, 0.45);

            /* Neutrals — Slate */
            --aidev-bg: #ffffff;
            --aidev-bg-secondary: #f8fafc;
            --aidev-border: #f1f5f9;
            --aidev-border-input: #e2e8f0;
            --aidev-text: #111827;
            --aidev-text-muted: #6b7280;
            --aidev-scrollbar: #cbd5e1;
            --aidev-scrollbar-hover: #94a3b8;
        }

        /* ═══════════ Chat Container ═══════════ */
        .aidev-chat {
            background: var(--aidev-bg);
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 0 0 1px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        /* ═══════════ Header ═══════════ */
        .aidev-chat__header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--aidev-border);
        }
        .aidev-chat__title {
            font-weight: 600; font-size: 14px; color: var(--aidev-text);
        }
        .aidev-chat__subtitle {
            font-size: 11px; color: var(--aidev-text-muted);
        }
        .aidev-chat__clear-btn {
            display: flex; align-items: center; gap: 6px;
            padding: 6px 12px;
            border: 1px solid var(--aidev-border-input);
            border-radius: 8px;
            background: transparent; cursor: pointer;
            font-size: 12px; color: var(--aidev-text-muted);
            transition: all 0.15s;
        }
        .aidev-chat__clear-btn:hover {
            background: var(--aidev-bg-secondary);
            border-color: #d1d5db;
        }

        /* ═══════════ Avatar (gradient) ═══════════ */
        .aidev-chat__avatar {
            width: 36px; height: 36px;
            background: var(--aidev-gradient);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .aidev-chat__avatar--sm {
            width: 30px; height: 30px;
            border-radius: 50%;
        }

        /* ═══════════ Messages Area ═══════════ */
        .aidev-chat__messages {
            height: 340px; overflow-y: auto;
            padding: 20px;
            display: flex; flex-direction: column; gap: 16px;
            background: var(--aidev-bg-secondary);
        }
        .aidev-chat__messages::-webkit-scrollbar { width: 4px; }
        .aidev-chat__messages::-webkit-scrollbar-track { background: transparent; }
        .aidev-chat__messages::-webkit-scrollbar-thumb { background: var(--aidev-scrollbar); border-radius: 4px; }
        .aidev-chat__messages::-webkit-scrollbar-thumb:hover { background: var(--aidev-scrollbar-hover); }

        /* ═══════════ Message Bubbles ═══════════ */
        .aidev-chat__bubble {
            max-width: 75%; padding: 10px 16px;
            font-size: 14px; line-height: 1.5;
        }
        .aidev-chat__bubble--user {
            background: var(--aidev-gradient);
            color: white;
            border-radius: 18px 18px 4px 18px;
            box-shadow: 0 2px 8px var(--aidev-gradient-shadow);
        }
        .aidev-chat__bubble--assistant {
            background: var(--aidev-bg);
            color: var(--aidev-text);
            border-radius: 18px 18px 18px 4px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            border: 1px solid var(--aidev-border);
        }

        /* ═══════════ Typing Indicator ═══════════ */
        .aidev-chat__typing {
            background: var(--aidev-bg);
            padding: 12px 18px;
            border-radius: 18px 18px 18px 4px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            border: 1px solid var(--aidev-border);
            display: flex; gap: 5px; align-items: center;
        }
        .aidev-chat__dot {
            width: 7px; height: 7px;
            background: var(--aidev-primary-500);
            border-radius: 50%;
            display: inline-block;
            animation: typingBounce 1.2s ease-in-out infinite;
        }

        /* ═══════════ Input Area ═══════════ */
        .aidev-chat__input-area {
            padding: 16px 20px;
            border-top: 1px solid var(--aidev-border);
            background: var(--aidev-bg);
        }
        .aidev-chat__input-wrapper {
            display: flex; align-items: flex-end; gap: 10px;
            background: var(--aidev-bg-secondary);
            border: 1px solid var(--aidev-border-input);
            border-radius: 14px;
            padding: 10px 14px;
            transition: border-color 0.15s, box-shadow 0.15s;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.04);
        }
        .aidev-chat__input-wrapper:focus-within {
            border-color: var(--aidev-primary-500);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.12), inset 0 1px 3px rgba(0,0,0,0.04);
        }
        .aidev-chat__textarea {
            flex: 1; border: none; background: transparent;
            resize: none; outline: none;
            font-size: 14px; line-height: 1.5;
            color: var(--aidev-text);
            min-height: 24px; max-height: 100px; padding: 0;
        }
        .aidev-chat__send-btn {
            flex-shrink: 0; width: 36px; height: 36px;
            background: var(--aidev-gradient);
            border: none; border-radius: 10px;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.15s;
            box-shadow: 0 2px 6px var(--aidev-gradient-shadow);
        }
        .aidev-chat__send-btn:hover {
            transform: scale(1.08);
            box-shadow: 0 4px 12px var(--aidev-gradient-shadow-hover);
        }

        /* ═══════════ Animations ═══════════ */
        @keyframes typingBounce {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-5px); }
        }
    </style>
</x-filament-widgets::widget>

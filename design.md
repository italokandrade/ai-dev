# AI-Dev Core — Design Proposal (Aether Dark)

> Documento de especificação para Claude Design.
> Baseado na identidade visual de **www.italoandrade.com** + compatibilidade total com o stack do projeto.

---

## 1. Visão do Produto

**AI-Dev Core** é uma plataforma de desenvolvimento de sistemas agênticos. Ela orquestra agentes de IA via Laravel AI SDK, gerencia projetos, módulos, tarefas, especificações e cotações. O novo design estende a identidade visual do site pessoal do autor para um painel administrativo completo — transmitindo **precisão técnica, sofisticação e poder**.

---

## 2. Stack Técnico (restrições para o design)

| Camada | Tecnologia | Versão |
|---|---|---|
| Backend | PHP | 8.3.30 |
| Framework | Laravel | 13.5.0 |
| Admin Panel | **Filament** | **^5.5** |
| CSS | **Tailwind CSS** | **v4** (CSS-first, sem `tailwind.config.js`) |
| Build | Vite | ^8.0 |
| JS reativo | **Alpine.js** | bundled via Filament |
| AI SDK | `laravel/ai` | ^0.5.0 |
| Fila | Laravel Horizon | ^5.45 |
| Banco | PostgreSQL 16 + pgvector | — |
| Acesso | Filament Shield (`bezhansalleh/filament-shield`) | ^4.2 |
| Logs | `pxlrbt/filament-activity-log` | ^2.1 |

### Regras invioláveis de compatibilidade

- Filament 5 usa seu próprio sistema CSS. Customizações via `->viteTheme()` e variáveis CSS.
- Tailwind v4: usar `@import "tailwindcss"` e `@theme {}` — **sem** `tailwind.config.js` e **sem** `@tailwind`.
- Alpine.js já disponível globalmente via Filament — não importar separadamente.
- Sem utilitários Tailwind depreciados: `bg-opacity-*`, `flex-shrink-*`, `overflow-ellipsis`.

---

## 3. Identidade Visual — Baseada em www.italoandrade.com

### 3.1 Paleta de Cores (extraída do site)

| Token | Valor | Uso |
|---|---|---|
| `--bg-base` | `#050505` | Fundo principal — quase preto puro |
| `--bg-deep` | `#0a0a14` | Fundo do radial gradient |
| `--bg-surface` | `#0f1117` | Sidebar, cards |
| `--bg-elevated` | `#161b27` | Modais, dropdowns, inputs |
| `--bg-overlay` | `#1e2435` | Hover states |
| `--grid-line` | `#1f2937` | Linhas da grade de fundo |
| `--primary` | `#3b82f6` | Azul — blue-500 |
| `--secondary` | `#8b5cf6` | Roxo — purple-500 / violet-500 |
| `--gradient-line` | `from #3b82f6 to #9333ea` | Divider, destaques |
| `--glow-blue` | `rgba(37,99,235,0.10)` | Orbe azul animado |
| `--glow-purple` | `rgba(147,51,234,0.10)` | Orbe roxo animado |
| `--text-primary` | `#ffffff` | Títulos |
| `--text-secondary` | `#e5e7eb` | gray-200 |
| `--text-muted` | `#9ca3af` | gray-400 |
| `--text-faint` | `#4b5563` | gray-600 — labels de status |
| `--border-subtle` | `rgba(31,41,55,0.8)` | Bordas suaves (grid color) |
| `--border-default` | `rgba(55,65,81,0.6)` | Bordas de cards |

### 3.2 Tipografia (extraída do site)

| Uso | Fonte | Peso |
|---|---|---|
| Interface geral | `Inter` | 300, 400, 700 |
| Títulos principais | `Inter` | 700 — `tracking-tighter` |
| Código, IDs, status, métricas | `JetBrains Mono` | 400, 700 |

**Import (Google Fonts — mesmo do site):**
```html
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&family=Inter:wght@300;400;700&display=swap" rel="stylesheet">
```

**Configuração Tailwind v4:**
```css
@theme {
    --font-sans: 'Inter', ui-sans-serif, system-ui, sans-serif;
    --font-mono: 'JetBrains Mono', ui-monospace, monospace;
}
```

### 3.3 Padrões Visuais Marcantes do Site

#### Grade animada de fundo
```css
.grid-bg {
    background-image:
        linear-gradient(to right, #1f2937 1px, transparent 1px),
        linear-gradient(to bottom, #1f2937 1px, transparent 1px);
    background-size: 40px 40px;
    mask-image: radial-gradient(ellipse at center, black, transparent 80%);
    opacity: 0.20;
}
```
Aplicar como camada `position: fixed; inset: 0; z-index: 0` atrás de todo o conteúdo do painel.

#### Orbes de brilho (ambient glow)
```css
/* Dois orbes pulsantes em segundo plano — azul e roxo */
@keyframes pulse-soft {
    0%, 100% { transform: scale(1); opacity: 0.5; }
    50% { transform: scale(1.2); opacity: 0.8; }
}

.glow-orb-blue {
    position: fixed;
    top: 25%; left: 25%;
    width: 320px; height: 320px;
    background: rgba(37,99,235,0.10);
    border-radius: 50%;
    filter: blur(120px);
    animation: pulse-soft 3s infinite ease-in-out;
    pointer-events: none; z-index: 0;
}

.glow-orb-purple {
    position: fixed;
    bottom: 25%; right: 25%;
    width: 320px; height: 320px;
    background: rgba(147,51,234,0.10);
    border-radius: 50%;
    filter: blur(120px);
    animation: pulse-soft 3s 1.5s infinite ease-in-out;
    pointer-events: none; z-index: 0;
}
```

#### Divider gradiente (linha decorativa)
```css
.gradient-divider {
    height: 1px;
    background: linear-gradient(to right, #3b82f6, #9333ea);
    border-radius: 999px;
}
```
Usar abaixo do logo na sidebar, nos títulos de seção e como separador de cards.

#### Cursor piscante (elementos monospaced)
```css
.typing::after {
    content: '|';
    animation: blink 1s infinite;
}
@keyframes blink { 50% { opacity: 0; } }
```
Usar no chat do assistente de IA enquanto processa a resposta.

#### Barra de status animada
```css
/* Estilo "Deploying Assets..." do site */
.status-bar-track {
    width: 192px; height: 4px;
    background: #1f2937;
    border-radius: 999px;
    overflow: hidden;
}
.status-bar-fill {
    height: 100%;
    background: #3b82f6;
    animation: loading 2s infinite;
}
@keyframes loading {
    0%   { transform: translateX(-100%); }
    100% { transform: translateX(200%); }
}
```
Usar no widget de status dos agentes quando estão executando.

---

## 4. Layout da Interface

### 4.1 Fundo Global

```
┌─────────────────────────────────────────────┐
│  [grade 40×40px, opacity 20%]               │  ← posição fixed, z-index 0
│     [orbe azul blur, top-left]              │
│              [orbe roxo blur, bottom-right] │
│  [todo o conteúdo do painel, z-index 10+]   │
└─────────────────────────────────────────────┘
```

### 4.2 Sidebar

```
┌──────────────────────┐
│  AI-Dev               │  ← Inter Bold, tracking-tighter
│  CORE                 │     "CORE" em blue-500
│  ─────────────────── │  ← gradient-divider (blue→purple)
│                       │
│  WORKSPACE            │  ← JetBrains Mono, 10px, gray-600, uppercase
│  > Dashboard          │
│  > Projetos           │
│  > Módulos            │
│  > Tarefas            │
│                       │
│  ESPECIFICAÇÕES       │
│  > PRDs               │
│  > Cotações           │
│                       │
│  AGENTES              │
│  > Configurações      │
│  > Logs               │
│                       │
│  SISTEMA              │
│  > Configurações      │
│  > Usuários           │
│  > Permissões         │
│                       │
│  ─────────────────── │  ← gradient-divider
│  [●] Italo Andrade    │  ← avatar + nome + status
│  Status: Online       │  ← JetBrains Mono, 10px
└──────────────────────┘
```

**Especificações:**
- Largura: `240px`
- Fundo: `#0f1117` (uma camada acima do `#050505` base)
- Borda direita: `1px solid #1f2937`
- Item ativo: `background: rgba(59,130,246,0.10)` + `border-left: 2px solid #3b82f6`
- Hover: `background: rgba(255,255,255,0.03)`
- Label de grupo: `JetBrains Mono`, `10px`, `letter-spacing: 0.12em`, `color: #4b5563`

### 4.3 Topbar

```
┌────────────────────────────────────────────────────────────────────┐
│  ≡   [🔍 Buscar...  ⌘K]                [● 2 agentes] [🔔] [👤]  │
└────────────────────────────────────────────────────────────────────┘
```

- Fundo: `rgba(5,5,5,0.85)` + `backdrop-filter: blur(12px)`
- Borda inferior: `1px solid #1f2937`
- Indicador de agentes: bolinha verde pulsante (`animate-pulse`) + texto `JetBrains Mono`

### 4.4 Dashboard — Grid de Widgets

```
┌──────────────┬──────────────┬──────────────┐
│ Projetos     │ Tasks        │ Agentes      │  ← 3 stat cards
│ Ativos: 5    │ Abertas: 12  │ Ativos: 2    │
└──────┬───────┴──────────────┴───────┬──────┘
       │                              │
       │  DashboardChat (AI)          │  Agent Health
       │  ─────────────────────────   │  ──────────────
       │  Assistente de IA            │  [●] GPT-4o  OK
       │  Histórico de conversa       │  [●] Claude  OK
       │  Input de mensagem           │  [○] Gemini  --
       │                              │
       └──────────────────────────────┘
┌─────────────────────────────────────────────┐
│          Project Roadmap (timeline)          │  ← full width
└─────────────────────────────────────────────┘
┌─────────────────────────────────────────────┐
│     Task Board (Backlog/Dev/Review/Done)     │  ← full width
└─────────────────────────────────────────────┘
```

---

## 5. Componentes Detalhados

### 5.1 Stat Card

```html
<div class="relative overflow-hidden rounded-xl border border-gray-800 bg-[#0f1117] p-6">
    <!-- Glow sutil no canto superior -->
    <div class="absolute -top-8 -right-8 w-24 h-24 bg-blue-600/10 rounded-full blur-2xl"></div>

    <div class="flex items-start justify-between">
        <div>
            <p class="font-mono text-[10px] uppercase tracking-widest text-gray-600">Projetos Ativos</p>
            <p class="mt-2 text-4xl font-bold tracking-tighter text-white">5</p>
            <p class="mt-1 font-mono text-xs text-gray-500">+2 este mês</p>
        </div>
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-500/10 border border-blue-500/20">
            <!-- ícone heroicon -->
        </div>
    </div>

    <!-- Divider gradiente na base -->
    <div class="mt-4 h-px bg-gradient-to-r from-blue-500/50 to-purple-600/50"></div>
</div>
```

### 5.2 Badges de Status

| Status | Estilo |
|---|---|
| Ativo / Online | `bg-blue-500/10 text-blue-400 border border-blue-500/20` |
| Concluído | `bg-emerald-500/10 text-emerald-400 border border-emerald-500/20` |
| Em desenvolvimento | `bg-violet-500/10 text-violet-400 border border-violet-500/20` |
| Pausado | `bg-amber-500/10 text-amber-400 border border-amber-500/20` |
| Erro | `bg-rose-500/10 text-rose-400 border border-rose-500/20` |

Todos com `font-mono text-[10px] uppercase tracking-widest rounded-full px-2.5 py-0.5`.

### 5.3 DashboardChat

```
┌──────────────────────────────────────────────────────────┐
│  font-mono text-[10px] tracking-widest text-gray-600:    │
│  STATUS: ASSISTANT · ONLINE                    [Limpar]  │
│  ── gradient-divider ──────────────────────────────────  │
│                                                          │
│  [●] Olá! Sou o Assistente do AI-Dev...                  │
│       texto Inter 300, gray-300                          │
│                                                          │
│       [Usuário] Como está o Projeto X?  ←─ bolha direita │
│       bg: rgba(59,130,246,0.10)                         │
│       border: rgba(59,130,246,0.20)                     │
│                                                          │
│  [●] O Projeto X tem 3 módulos...        ←─ bolha esq.  │
│       bg: #161b27                                        │
│                                                          │
│  ── gradient-divider ──────────────────────────────────  │
│  [Mensagem...                    ]          [→ Enviar]   │
│   input: bg #161b27, border #1f2937                      │
│   botão: bg blue-500, hover shadow-[0_0_16px_blue-500/40]│
└──────────────────────────────────────────────────────────┘
```

Avatar do assistente: `w-6 h-6 rounded bg-blue-500/20 border border-blue-500/30` + ícone pulsante durante `isProcessing`.

### 5.4 Tabelas (Resource Lists)

```css
/* Cabeçalho */
th {
    font-family: 'JetBrains Mono', monospace;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: #4b5563; /* gray-600 */
    background: #0f1117;
}

/* Linha */
tr {
    border-bottom: 1px solid #1f2937;
}
tr:hover {
    background: rgba(255,255,255,0.02);
}
```

### 5.5 Inputs e Formulários

```css
input, select, textarea {
    background: #161b27;
    border: 1px solid #1f2937;
    border-radius: 8px;
    color: #f1f5f9;
    font-family: 'Inter', sans-serif;
}

input:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
    outline: none;
}

label {
    font-family: 'Inter', sans-serif;
    font-size: 13px;
    font-weight: 500;
    color: #9ca3af; /* gray-400 */
}
```

### 5.6 Botão Primário

```css
.btn-primary {
    background: #3b82f6;
    color: white;
    font-family: 'Inter', sans-serif;
    font-weight: 500;
    border-radius: 8px;
    padding: 8px 20px;
    transition: all 0.2s;
}
.btn-primary:hover {
    background: #2563eb;
    box-shadow: 0 0 20px rgba(59,130,246,0.40);
}

/* Variante gradiente (ações principais) */
.btn-gradient {
    background: linear-gradient(to right, #3b82f6, #9333ea);
}
.btn-gradient:hover {
    box-shadow: 0 0 24px rgba(99,102,241,0.35);
}
```

---

## 6. Tela de Login

Replicar diretamente a estética do site pessoal:

```
┌────────────────────────────────────────────────────────────┐
│  [grade animada 40×40, opacity 20%]                        │
│  [orbe azul blur top-left]  [orbe roxo blur bottom-right] │
│                                                            │
│              AI-DEV                                        │
│              CORE                                          │
│              ─── gradient-divider ───                      │
│                                                            │
│         ┌──────────────────────────────────┐              │
│         │  bg: rgba(15,17,23,0.80)         │              │
│         │  border: 1px solid #1f2937       │              │
│         │  backdrop-blur: 12px             │              │
│         │                                  │              │
│         │  Email                           │              │
│         │  [_______________________________]│              │
│         │  Senha                           │              │
│         │  [_______________________________]│              │
│         │                                  │              │
│         │  [    → Entrar no sistema    ]   │              │
│         │   btn-gradient                   │              │
│         └──────────────────────────────────┘              │
│                                                            │
│  ─────────────────────────────────────────────────        │
│  font-mono 10px uppercase tracking-widest gray-600:       │
│  STATUS: AUTHENTICATING...                                 │
└────────────────────────────────────────────────────────────┘
```

---

## 7. Como Implementar no Filament 5

### 7.1 AdminPanelProvider — configurações

```php
// app/Providers/Filament/AdminPanelProvider.php
return $panel
    ->default()
    ->id('admin')
    ->path('admin')
    ->login()
    ->brandName('AI-Dev Core')
    ->brandLogo(asset('images/logo.svg'))
    ->darkMode(\Filament\Support\Enums\ThemeMode::Dark)
    ->colors([
        'primary'   => \Filament\Support\Colors\Color::Blue,
        'secondary' => \Filament\Support\Colors\Color::Violet,
        'success'   => \Filament\Support\Colors\Color::Emerald,
        'warning'   => \Filament\Support\Colors\Color::Amber,
        'danger'    => \Filament\Support\Colors\Color::Rose,
        'info'      => \Filament\Support\Colors\Color::Sky,
        'gray'      => \Filament\Support\Colors\Color::Zinc,
    ])
    ->font('Inter', 'https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&family=Inter:wght@300;400;700&display=swap')
    ->viteTheme('resources/css/filament/admin/theme.css')
    ->sidebarCollapsibleOnDesktop()
    ->maxContentWidth(\Filament\Support\Enums\MaxWidth::Full)
    // ... resto da configuração
```

### 7.2 Estrutura de arquivos

```
resources/
├── css/
│   ├── app.css                                ← não alterar
│   └── filament/
│       └── admin/
│           └── theme.css                      ← CRIAR (tema Aether Dark)
├── views/
│   └── filament/
│       ├── widgets/
│       │   └── dashboard-chat.blade.php       ← redesenhar
│       └── components/
│           └── stat-card.blade.php            ← CRIAR
public/
└── images/
    └── logo.svg                               ← CRIAR (logotipo)
```

### 7.3 Adicionar ao vite.config.js

```js
laravel({
    input: [
        'resources/css/app.css',
        'resources/css/filament/admin/theme.css',  // ← adicionar
        'resources/js/app.js',
    ],
    refresh: true,
}),
```

### 7.4 Estrutura do theme.css

```css
/* resources/css/filament/admin/theme.css */
@import 'tailwindcss';

/* ——— FONTES ——— */
@import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&family=Inter:wght@300;400;700&display=swap');

/* ——— CONFIGURAÇÃO ——— */
@theme {
    --font-sans: 'Inter', ui-sans-serif, system-ui, sans-serif;
    --font-mono: 'JetBrains Mono', ui-monospace, monospace;
}

/* ——— VARIÁVEIS ——— */
:root {
    --bg-base:        #050505;
    --bg-surface:     #0f1117;
    --bg-elevated:    #161b27;
    --bg-overlay:     #1e2435;
    --grid-line:      #1f2937;

    --primary:        #3b82f6;
    --secondary:      #9333ea;
    --glow-blue:      rgba(37,99,235,0.10);
    --glow-purple:    rgba(147,51,234,0.10);

    --text-primary:   #ffffff;
    --text-secondary: #e5e7eb;
    --text-muted:     #9ca3af;
    --text-faint:     #4b5563;

    --border-subtle:  #1f2937;
    --border-default: rgba(55,65,81,0.60);
}

/* ——— ANIMAÇÕES ——— */
@keyframes pulse-soft {
    0%, 100% { transform: scale(1); opacity: 0.5; }
    50%       { transform: scale(1.2); opacity: 0.8; }
}
@keyframes blink {
    50% { opacity: 0; }
}
@keyframes loading {
    0%   { transform: translateX(-100%); }
    100% { transform: translateX(200%); }
}

/* ——— FUNDO GLOBAL ——— */
.fi-body {
    background-color: var(--bg-base) !important;
    background-image:
        linear-gradient(to right, var(--grid-line) 1px, transparent 1px),
        linear-gradient(to bottom, var(--grid-line) 1px, transparent 1px);
    background-size: 40px 40px;
}

/* ——— SIDEBAR ——— */
.fi-sidebar {
    background-color: var(--bg-surface) !important;
    border-right: 1px solid var(--border-subtle) !important;
}

/* Item ativo */
.fi-sidebar-item-active {
    background: rgba(59,130,246,0.10) !important;
    border-left: 2px solid var(--primary) !important;
}

/* ——— TOPBAR ——— */
.fi-topbar {
    background: rgba(5,5,5,0.85) !important;
    backdrop-filter: blur(12px) !important;
    border-bottom: 1px solid var(--border-subtle) !important;
}

/* ——— CARDS / SECTIONS ——— */
.fi-section {
    background: var(--bg-surface) !important;
    border: 1px solid var(--border-subtle) !important;
    border-radius: 12px !important;
}

/* ——— INPUTS ——— */
.fi-input {
    background: var(--bg-elevated) !important;
    border-color: var(--border-subtle) !important;
}
.fi-input:focus {
    border-color: var(--primary) !important;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.15) !important;
}
```

---

## 8. Entregáveis para Implementação

1. `resources/css/filament/admin/theme.css` — CSS completo Aether Dark
2. `app/Providers/Filament/AdminPanelProvider.php` — atualizar font, logo, darkMode, viteTheme
3. `vite.config.js` — adicionar theme.css ao input
4. `public/images/logo.svg` — logotipo (sugestão: `AI` em Inter Bold + linha gradiente)
5. `resources/views/filament/widgets/dashboard-chat.blade.php` — chat redesenhado
6. `resources/views/filament/components/stat-card.blade.php` — stat card customizado

---

## 9. Referências Visuais

| Referência | O que extrair |
|---|---|
| **www.italoandrade.com** | Grade animada, orbes blur, tipografia JetBrains Mono + Inter, palette azul/roxo, fundo `#050505` |
| **Linear.app** | Densidade informacional, sidebar dark, badges de status |
| **Vercel Dashboard** | Cards de métricas, grid limpo, status indicators |
| **Anthropic Console** | IA como produto de primeira classe |

---

*Gerado em 2026-04-23 · AI-Dev Core · Filament 5.5 + Tailwind v4 + Laravel 13.5*

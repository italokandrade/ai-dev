<?php

namespace App\Filament\Pages;

use App\Models\SystemSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SystemSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Sistema';
    protected static ?string $title = 'Configurações do Sistema';
    protected static string|\UnitEnum|null $navigationGroup = 'Configuração';
    protected static ?int $navigationSort = 10;
    protected string $view = 'filament.pages.system-settings-page';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'system_name' => SystemSetting::get(SystemSetting::SYSTEM_NAME, 'AI-Dev'),
            
            // Premium
            'ai_premium_provider' => SystemSetting::get(SystemSetting::AI_PREMIUM_PROVIDER, 'openrouter'),
            'ai_premium_key' => SystemSetting::get(SystemSetting::AI_PREMIUM_KEY),
            'ai_premium_model' => SystemSetting::get(SystemSetting::AI_PREMIUM_MODEL, 'anthropic/claude-opus-4.7'),
            
            // High
            'ai_high_provider' => SystemSetting::get(SystemSetting::AI_HIGH_PROVIDER, 'openrouter'),
            'ai_high_key' => SystemSetting::get(SystemSetting::AI_HIGH_KEY),
            'ai_high_model' => SystemSetting::get(SystemSetting::AI_HIGH_MODEL, 'anthropic/claude-sonnet-4-6'),
            
            // Fast
            'ai_fast_provider' => SystemSetting::get(SystemSetting::AI_FAST_PROVIDER, 'openrouter'),
            'ai_fast_key' => SystemSetting::get(SystemSetting::AI_FAST_KEY),
            'ai_fast_model' => SystemSetting::get(SystemSetting::AI_FAST_MODEL, 'anthropic/claude-haiku-4.5'),

            // Sistema
            'ai_system_provider' => SystemSetting::get(SystemSetting::AI_SYSTEM_PROVIDER, 'openrouter'),
            'ai_system_key' => SystemSetting::get(SystemSetting::AI_SYSTEM_KEY),
            'ai_system_model' => SystemSetting::get(SystemSetting::AI_SYSTEM_MODEL, 'anthropic/claude-sonnet-4-6'),

            'development_enabled' => SystemSetting::isDevelopmentEnabled(),
            'maintenance_mode' => SystemSetting::get(SystemSetting::MAINTENANCE_MODE, '0') === '1',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Identidade do Sistema')
                    ->schema([
                        TextInput::make('system_name')->label('Nome do Sistema')->required(),
                        Grid::make(2)->schema([
                            FileUpload::make('system_logo')->label('Logotipo')->image()->directory('system'),
                            FileUpload::make('system_favicon')->label('Favicon')->image()->directory('system'),
                        ]),
                    ])->collapsible(),

                $this->getAiSection('IA Nível PREMIUM (Planejamento)', 'Configurações para o nível mais alto de inteligência.', 'ai_premium'),
                $this->getAiSection('IA Nível HIGH (Desenvolvimento/QA)', 'Configurações para o motor principal de codificação.', 'ai_high'),
                $this->getAiSection('IA Nível FAST (Documentação/Jobs)', 'Configurações para tarefas rápidas e menor custo.', 'ai_fast'),
                $this->getAiSection('IA do Sistema (Produção/Interação)', 'Modelo utilizado pelos usuários finais da aplicação.', 'ai_system'),

                Section::make('Controle Operacional')
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('development_enabled')->label('Habilitar Agentes')->onColor('success'),
                            Toggle::make('maintenance_mode')->label('Modo de Manutenção')->onColor('warning'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getAiSection(string $title, string $description, string $prefix): Section
    {
        return Section::make($title)
            ->description($description)
            ->schema([
                Grid::make(3)->schema([
                    Select::make("{$prefix}_provider")
                        ->label('Provider')
                        ->options([
                            'openrouter' => 'OpenRouter',
                            'anthropic' => 'Anthropic',
                            'openai' => 'OpenAI',
                            'ollama' => 'Ollama (Local)',
                        ])->required(),
                    TextInput::make("{$prefix}_key")
                        ->label('API Key')
                        ->password()
                        ->revealable(),
                    TextInput::make("{$prefix}_model")
                        ->label('Modelo')
                        ->placeholder('ex: anthropic/claude-3.5-sonnet')
                        ->required(),
                ]),
            ])->collapsible()->collapsed();
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data as $key => $value) {
            if ($key === 'development_enabled') {
                SystemSetting::setDevelopmentEnabled($value);
            } elseif ($key === 'maintenance_mode') {
                SystemSetting::set(SystemSetting::MAINTENANCE_MODE, $value ? '1' : '0');
            } else {
                SystemSetting::set($key, $value);
            }
        }

        Notification::make()->title('Configurações salvas!')->success()->send();
    }
}

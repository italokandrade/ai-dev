<?php

namespace App\Filament\Pages;

use App\Models\SystemSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

    protected static ?string $navigationLabel = 'Configurações';

    protected static ?string $title = 'Configurações do Sistema';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuracao';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.system-settings-page';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'system_name' => SystemSetting::get(SystemSetting::SYSTEM_NAME, 'AI-Dev'),
            'openrouter_api_key' => SystemSetting::get(SystemSetting::OPENROUTER_API_KEY),
            'default_opus_model' => SystemSetting::get(SystemSetting::DEFAULT_OPUS_MODEL, 'anthropic/claude-opus-4.7'),
            'default_sonnet_model' => SystemSetting::get(SystemSetting::DEFAULT_SONNET_MODEL, 'anthropic/claude-sonnet-4-6'),
            'development_enabled' => SystemSetting::isDevelopmentEnabled(),
            'maintenance_mode' => SystemSetting::get(SystemSetting::MAINTENANCE_MODE, '0') === '1',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Identidade do Sistema')
                    ->description('Configurações visuais e de identificação.')
                    ->schema([
                        TextInput::make('system_name')
                            ->label('Nome do Sistema')
                            ->required(),
                        Grid::make(2)->schema([
                            FileUpload::make('system_logo')
                                ->label('Logotipo')
                                ->image()
                                ->directory('system'),
                            FileUpload::make('system_favicon')
                                ->label('Favicon')
                                ->image()
                                ->directory('system'),
                        ]),
                    ])->collapsible(),

                Section::make('Configurações de IA')
                    ->description('Credenciais e modelos para as interações do sistema.')
                    ->schema([
                        TextInput::make('openrouter_api_key')
                            ->label('OpenRouter API Key')
                            ->password()
                            ->revealable()
                            ->helperText('Chave usada para as IAs de interação e assistentes.'),
                        Grid::make(2)->schema([
                            TextInput::make('default_opus_model')
                                ->label('Modelo Opus (Planejamento)')
                                ->default('anthropic/claude-opus-4.7'),
                            TextInput::make('default_sonnet_model')
                                ->label('Modelo Sonnet (Código/QA)')
                                ->default('anthropic/claude-sonnet-4-6'),
                        ]),
                    ])->collapsible(),

                Section::make('Controle Operacional')
                    ->description('Gerenciamento de execução e disponibilidade.')
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('development_enabled')
                                ->label('Habilitar Agentes de Desenvolvimento')
                                ->helperText('Ativa/Pausa a execução automática de tasks pelos subagentes.')
                                ->onColor('success')
                                ->offColor('danger'),
                            Toggle::make('maintenance_mode')
                                ->label('Modo de Manutenção')
                                ->helperText('Bloqueia o acesso de usuários comuns ao sistema.')
                                ->onColor('warning'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Salvar Configurações')
                ->color('primary')
                ->action('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        SystemSetting::set(SystemSetting::SYSTEM_NAME, $data['system_name']);
        SystemSetting::set(SystemSetting::OPENROUTER_API_KEY, $data['openrouter_api_key']);
        SystemSetting::set(SystemSetting::DEFAULT_OPUS_MODEL, $data['default_opus_model']);
        SystemSetting::set(SystemSetting::DEFAULT_SONNET_MODEL, $data['default_sonnet_model']);
        SystemSetting::setDevelopmentEnabled($data['development_enabled']);
        SystemSetting::set(SystemSetting::MAINTENANCE_MODE, $data['maintenance_mode'] ? '1' : '0');

        if (isset($data['system_logo'])) {
            SystemSetting::set(SystemSetting::SYSTEM_LOGO, is_array($data['system_logo']) ? reset($data['system_logo']) : $data['system_logo']);
        }
        
        if (isset($data['system_favicon'])) {
            SystemSetting::set(SystemSetting::SYSTEM_FAVICON, is_array($data['system_favicon']) ? reset($data['system_favicon']) : $data['system_favicon']);
        }

        Notification::make()
            ->title('Configurações atualizadas!')
            ->success()
            ->send();
    }
}

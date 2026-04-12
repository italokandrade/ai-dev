<?php

namespace App\Filament\Widgets;

use App\Models\SystemSetting;
use Filament\Widgets\Widget;

class DevelopmentStatusWidget extends Widget
{
    protected string $view = 'filament.widgets.development-status';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public bool $developmentEnabled = false;

    public function mount(): void
    {
        $this->developmentEnabled = SystemSetting::isDevelopmentEnabled();
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StackComponent: string implements HasLabel
{
    case Tailwind = 'tailwind';
    case Alpine = 'alpine';
    case Laravel = 'laravel';
    case Livewire = 'livewire';
    case Filament = 'filament';
    case Animejs = 'animejs';

    public function getLabel(): string
    {
        return match ($this) {
            self::Tailwind => 'Tailwind CSS',
            self::Alpine => 'Alpine.js',
            self::Laravel => 'Laravel',
            self::Livewire => 'Livewire',
            self::Filament => 'Filament',
            self::Animejs => 'Anime.js',
        };
    }
}

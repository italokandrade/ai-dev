<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ContextCategory: string implements HasLabel
{
    case FilamentResource = 'filament_resource';
    case FilamentWidget = 'filament_widget';
    case FilamentForm = 'filament_form';
    case FilamentTable = 'filament_table';
    case FilamentAction = 'filament_action';
    case LivewireComponent = 'livewire_component';
    case BladeLayout = 'blade_layout';
    case AnimejsAnimation = 'animejs_animation';
    case EloquentModel = 'eloquent_model';
    case LaravelService = 'laravel_service';
    case LaravelMigration = 'laravel_migration';
    case LaravelTest = 'laravel_test';
    case TailwindPattern = 'tailwind_pattern';

    public function getLabel(): string
    {
        return match ($this) {
            self::FilamentResource => 'Filament Resource',
            self::FilamentWidget => 'Filament Widget',
            self::FilamentForm => 'Filament Form',
            self::FilamentTable => 'Filament Table',
            self::FilamentAction => 'Filament Action',
            self::LivewireComponent => 'Livewire Component',
            self::BladeLayout => 'Blade Layout',
            self::AnimejsAnimation => 'Anime.js Animation',
            self::EloquentModel => 'Eloquent Model',
            self::LaravelService => 'Laravel Service',
            self::LaravelMigration => 'Laravel Migration',
            self::LaravelTest => 'Laravel Test',
            self::TailwindPattern => 'Tailwind Pattern',
        };
    }
}

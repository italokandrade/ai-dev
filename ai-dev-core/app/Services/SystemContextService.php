<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SystemContextService
{
    public static function getFullContext(): string
    {
        $context = [
            'os' => self::getOperatingSystem(),
            'database' => self::getDatabaseInfo(),
            'php' => PHP_VERSION,
            'stack' => self::getStackVersions(),
        ];

        return self::formatToPrompt($context);
    }

    private static function getOperatingSystem(): string
    {
        if (File::exists('/etc/os-release')) {
            $osData = parse_ini_file('/etc/os-release');
            return ($osData['PRETTY_NAME'] ?? 'Linux') . ' (' . php_uname('m') . ')';
        }
        return PHP_OS . ' ' . php_uname('r');
    }

    private static function getDatabaseInfo(): array
    {
        try {
            $version = DB::select('SELECT version()')[0]->version;
            $pgvector = DB::select("SELECT count(*) FROM pg_extension WHERE extname = 'vector'")[0]->count > 0;
            
            return [
                'engine' => 'PostgreSQL',
                'version' => $version,
                'extensions' => [
                    'pgvector' => $pgvector ? 'Installed' : 'Not Found'
                ]
            ];
        } catch (\Exception $e) {
            return ['error' => 'Could not detect database info'];
        }
    }

    private static function getStackVersions(): array
    {
        $composerPath = base_path('composer.lock');
        $packagePath = base_path('package.json');
        
        $stack = [
            'laravel' => app()->version(),
            'filament' => 'v5',
            'livewire' => 'v4',
            'alpinejs' => 'v3',
            'tailwindcss' => 'v4',
            'animejs' => 'v4'
        ];

        if (File::exists($composerPath)) {
            $composer = json_decode(File::get($composerPath), true);
            $packages = collect($composer['packages'] ?? [])->merge($composer['packages-dev'] ?? []);
            
            $findVersion = fn($name) => $packages->where('name', $name)->first()['version'] ?? null;

            $stack['laravel'] = $findVersion('laravel/framework') ?? $stack['laravel'];
            $stack['filament'] = $findVersion('filament/filament') ?? $stack['filament'];
            $stack['livewire'] = $findVersion('livewire/livewire') ?? $stack['livewire'];
        }

        if (File::exists($packagePath)) {
            $package = json_decode(File::get($packagePath), true);
            $deps = array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []);
            
            $stack['tailwindcss'] = $deps['tailwindcss'] ?? $stack['tailwindcss'];
            $stack['alpinejs'] = $deps['alpinejs'] ?? $stack['alpinejs'];
            $stack['animejs'] = $deps['animejs'] ?? $stack['animejs'];
        }

        return $stack;
    }

    private static function formatToPrompt(array $context): string
    {
        $out = "CONTEXTO ATUAL DO AMBIENTE (REAL-TIME):\n";
        $out .= "- OS: {$context['os']}\n";
        $out .= "- PHP: {$context['php']}\n";
        $out .= "- DB: {$context['database']['engine']} ({$context['database']['version']})\n";
        $out .= "  - Extensions: " . json_encode($context['database']['extensions']) . "\n";
        $out .= "- Stack:\n";
        foreach ($context['stack'] as $tech => $ver) {
            $out .= "  - {$tech}: {$ver}\n";
        }
        $out .= "\nIMPORTANTE: Use estritamente as tecnologias acima. Não sugira nada fora deste escopo.";
        
        return $out;
    }
}

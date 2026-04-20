<?php

namespace App\Ai\Tools;

use App\Ai\Agents\DocsAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DocSearchTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Searches the official TALL Stack documentation (Laravel 13, Filament 5, Livewire 4, Alpine.js, Tailwind CSS v4, Anime.js). Use this before implementing any feature to get accurate API references and code examples.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = $request['query'];

        $response = (new DocsAgent)->prompt($query);

        return $response->text;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The documentation search query. Be specific: e.g. "Filament 5 table filters", "Livewire 4 lazy loading", "Laravel 13 queueable actions".')
                ->required(),
        ];
    }
}

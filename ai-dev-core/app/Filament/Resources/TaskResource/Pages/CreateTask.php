<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['prd_payload'] = [
            'objective' => $data['prd_objective'] ?? '',
            'acceptance_criteria' => $data['prd_acceptance_criteria'] ?? [],
            'constraints' => $data['prd_constraints'] ?? [],
            'knowledge_areas' => $data['prd_knowledge_areas'] ?? [],
        ];

        unset(
            $data['prd_objective'],
            $data['prd_acceptance_criteria'],
            $data['prd_constraints'],
            $data['prd_knowledge_areas'],
        );

        return $data;
    }
}

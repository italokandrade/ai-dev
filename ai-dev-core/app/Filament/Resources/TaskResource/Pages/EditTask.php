<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $prd = $data['prd_payload'] ?? [];

        $data['prd_objective'] = $prd['objective'] ?? '';
        $data['prd_acceptance_criteria'] = $prd['acceptance_criteria'] ?? [];
        $data['prd_constraints'] = $prd['constraints'] ?? [];
        $data['prd_knowledge_areas'] = $prd['knowledge_areas'] ?? [];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
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

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

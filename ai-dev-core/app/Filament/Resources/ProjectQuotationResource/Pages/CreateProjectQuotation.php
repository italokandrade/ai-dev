<?php

namespace App\Filament\Resources\ProjectQuotationResource\Pages;

use App\Filament\Resources\ProjectQuotationResource;
use App\Jobs\GenerateProjectQuotationJob;
use App\Models\Project;
use App\Models\ProjectQuotation;
use Filament\Resources\Pages\CreateRecord;

class CreateProjectQuotation extends CreateRecord
{
    protected static string $resource = ProjectQuotationResource::class;

    protected function afterCreate(): void
    {
        $record = $this->record;

        if ($record->project_id) {
            GenerateProjectQuotationJob::dispatch($record->project);
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] ??= 'draft';
        $data['complexity_level'] ??= 2;
        $data['urgency_level'] ??= 1;
        $data['required_areas'] ??= [];
        $data['project_name'] ??= $this->resolveProjectName($data);
        $data['project_description'] ??= $this->resolveProjectDescription($data);

        foreach ([
            'backend_hours',
            'frontend_hours',
            'mobile_hours',
            'database_hours',
            'devops_hours',
            'design_hours',
            'testing_hours',
            'security_hours',
            'pm_hours',
        ] as $field) {
            $data[$field] ??= 0;
        }

        foreach ([
            'hourly_rate_backend' => 120.00,
            'hourly_rate_frontend' => 110.00,
            'hourly_rate_mobile' => 130.00,
            'hourly_rate_database' => 115.00,
            'hourly_rate_devops' => 125.00,
            'hourly_rate_design' => 100.00,
            'hourly_rate_testing' => 90.00,
            'hourly_rate_security' => 140.00,
            'hourly_rate_pm' => 130.00,
            'actual_token_cost_usd' => 0,
            'actual_infra_cost' => 0,
        ] as $field => $default) {
            $data[$field] ??= $default;
        }

        $quotation = new ProjectQuotation($data);
        $quotation->recalculate();

        return array_merge($data, [
            'urgency_multiplier' => $quotation->urgency_multiplier,
            'complexity_multiplier' => $quotation->complexity_multiplier,
            'team_size' => $quotation->team_size,
            'total_human_hours' => $quotation->total_human_hours,
            'total_human_cost' => $quotation->total_human_cost,
            'ai_dev_cost' => $quotation->ai_dev_cost,
            'ai_dev_price' => $quotation->ai_dev_price,
            'savings_amount' => $quotation->savings_amount,
            'savings_percentage' => $quotation->savings_percentage,
        ]);
    }

    protected function resolveProjectName(array $data): ?string
    {
        if (! empty($data['project_name'])) {
            return $data['project_name'];
        }

        if (empty($data['project_id'])) {
            return null;
        }

        return $this->getProjectNameById($data['project_id']);
    }

    protected function resolveProjectDescription(array $data): ?string
    {
        if (! empty($data['project_description'])) {
            return $data['project_description'];
        }

        if (empty($data['project_id'])) {
            return null;
        }

        $project = Project::find($data['project_id']);

        return $project?->currentSpecification?->ai_specification['objective'] ?? null;
    }

    protected function getProjectNameById(string $projectId): ?string
    {
        return Project::find($projectId)?->name;
    }
}

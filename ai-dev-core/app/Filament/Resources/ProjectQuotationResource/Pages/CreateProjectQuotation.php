<?php

namespace App\Filament\Resources\ProjectQuotationResource\Pages;

use App\Filament\Resources\ProjectQuotationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProjectQuotation extends CreateRecord
{
    protected static string $resource = ProjectQuotationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Instancia temporária para calcular
        $quotation = new \App\Models\ProjectQuotation($data);
        $quotation->recalculate();

        return array_merge($data, [
            'urgency_multiplier'    => $quotation->urgency_multiplier,
            'complexity_multiplier' => $quotation->complexity_multiplier,
            'team_size'             => $quotation->team_size,
            'total_human_hours'     => $quotation->total_human_hours,
            'total_human_cost'      => $quotation->total_human_cost,
            'ai_dev_cost'           => $quotation->ai_dev_cost,
            'ai_dev_price'          => $quotation->ai_dev_price,
            'savings_amount'        => $quotation->savings_amount,
            'savings_percentage'    => $quotation->savings_percentage,
        ]);
    }
}

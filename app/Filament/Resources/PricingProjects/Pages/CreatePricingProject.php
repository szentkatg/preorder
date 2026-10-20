<?php

namespace App\Filament\Resources\PricingProjects\Pages;

use App\Filament\Resources\PricingProjects\PricingProjectResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreatePricingProject extends CreateRecord
{
    protected static string $resource = PricingProjectResource::class;

    protected static bool $canCreateAnother = false;

    protected function getRedirectUrl(): string
    {
        return PricingProjectResource::getUrl('products', ['record' => $this->getRecord()]);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Létrehozás és termékek kiválasztása');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        abort_unless(in_array($data['status'] ?? null, ['draft', 'active'], true), 422);
        $data['created_by'] = auth()->id();

        return $data;
    }
}

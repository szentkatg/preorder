<?php

namespace App\Filament\Resources\ItemAssortments\Pages;

use App\Filament\Resources\ItemAssortments\ItemAssortmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateItemAssortment extends CreateRecord
{
    protected static string $resource = ItemAssortmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        session([
            'item_assortment_defaults' => [
                'product_id' => $data['product_id'] ?? null,
                'color_id' => $data['color_id'] ?? null,
                'assortment_sku_id' => $data['assortment_sku_id'] ?? null,
            ],
        ]);

        return $data;
    }

    protected function fillForm(): void
    {
        parent::fillForm();

        $defaults = session('item_assortment_defaults');

        if ($defaults) {
            $this->form->fill($defaults);
        }
    }
}

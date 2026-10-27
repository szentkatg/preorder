<?php

namespace App\Filament\Support;

use App\Filament\Support\Concerns\HasPricingColumnPreferences;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

abstract class PricingConfigurationListRecords extends ListRecords
{
    use HasPricingColumnPreferences;

    protected string $view = 'filament.resources.pricing-configuration-list';

    public function mount(): void
    {
        parent::mount();

        $this->pricingColumnWidths = $this->loadPricingColumnWidths();
    }

    public function table(Table $table): Table
    {
        return parent::table($table)->reorderableColumns();
    }

    protected function pricingPreferencePrefix(): string
    {
        $model = static::getResource()::getModel();

        return 'pricing_configuration_'.(new $model)->getTable();
    }
}

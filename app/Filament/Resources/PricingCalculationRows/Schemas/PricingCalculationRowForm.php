<?php

namespace App\Filament\Resources\PricingCalculationRows\Schemas;

use App\Models\PricingCalculationRow;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PricingCalculationRowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('pricing_project_id')
                    ->label('Árprojekt')
                    ->relationship('pricingProject', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('product_id')
                    ->label('Modell')
                    ->relationship('product', 'model_code')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('color_id')
                    ->label('Szín')
                    ->relationship('color', 'name_hu')
                    ->searchable()
                    ->preload(),

                Select::make('supplier_id')
                    ->label('Beszállító')
                    ->relationship('supplier', 'short_name')
                    ->searchable()
                    ->preload()
                    ->disabled(),

                Select::make('purchase_currency_id')
                    ->label('Beszerzési deviza')
                    ->relationship('purchaseCurrency', 'code')
                    ->searchable()
                    ->preload()
                    ->disabled(),

                TextInput::make('purchase_price')
                    ->label('Beszerzési ár')
                    ->numeric()
                    ->disabled(),

                TextInput::make('exchange_rate')
                    ->label('Árfolyam')
                    ->numeric()
                    ->disabled(),

                TextInput::make('shipping_cost_percent')
                    ->label('Szállítási költség %')
                    ->numeric()
                    ->disabled(),

                TextInput::make('customs_percent')
                    ->label('Vám %')
                    ->numeric()
                    ->disabled(),

                TextInput::make('candidate_count')
                    ->label('Vizsgált aktív beszerzési árak')
                    ->numeric()
                    ->disabled(),

                Select::make('price_list_id')
                    ->label('Árlista')
                    ->relationship('priceList', 'code')
                    ->searchable()
                    ->preload(),

                Select::make('price_type')
                    ->label('Ártípus')
                    ->options([
                        PricingCalculationRow::TYPE_COST => 'Bekerülési érték',
                        PricingCalculationRow::TYPE_RETAIL => 'Kisker',
                        PricingCalculationRow::TYPE_WHOLESALE => 'Nagyker',
                        PricingCalculationRow::TYPE_DISTRIBUTOR => 'Disztribútor',
                    ])
                    ->required(),

                Select::make('currency_id')
                    ->label('Pénznem')
                    ->relationship('currency', 'code')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('calculated_price')
                    ->label('Kalkulált ár')
                    ->numeric()
                    ->disabled(),

                TextInput::make('manual_price')
                    ->label('Manuális ár')
                    ->numeric(),

                Select::make('status')
                    ->label('Státusz')
                    ->options([
                        PricingCalculationRow::STATUS_CALCULATED => 'Kalkulált ár',
                        PricingCalculationRow::STATUS_REVIEWED => 'Átnézett ár',
                        PricingCalculationRow::STATUS_APPROVED => 'Jóváhagyott ár',
                        PricingCalculationRow::STATUS_MODIFIED_APPROVED => 'Módosított jóváhagyott ár',
                        PricingCalculationRow::STATUS_APPROVED_ROUND_2 => '2. körös jóváhagyott',
                        PricingCalculationRow::STATUS_APPROVED_ROUND_3 => '3. körös jóváhagyott',
                    ])
                    ->default(PricingCalculationRow::STATUS_CALCULATED)
                    ->required(),
            ])
            ->columns(2);
    }
}

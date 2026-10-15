<?php

namespace App\Filament\Resources\PricingCalculationRows\Schemas;

use App\Models\PricingCalculationRow;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PricingCalculationRowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('pricing_project_id')
                    ->disabled(fn (?PricingCalculationRow $record) => self::isManagedPrice($record))
                    ->label('Árprojekt')
                    ->relationship('pricingProject', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('product_id')
                    ->disabled(fn (?PricingCalculationRow $record) => self::isManagedPrice($record))
                    ->label('Modell')
                    ->relationship('product', 'model_code')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('color_id')
                    ->disabled(fn (?PricingCalculationRow $record) => self::isManagedPrice($record))
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
                    ->disabled(fn (?PricingCalculationRow $record) => self::isManagedPrice($record))
                    ->label('Árlista')
                    ->relationship('priceList', 'code')
                    ->searchable()
                    ->preload(),

                Select::make('price_type')
                    ->disabled(fn (?PricingCalculationRow $record) => self::isManagedPrice($record))
                    ->label('Ártípus')
                    ->options([
                        PricingCalculationRow::TYPE_COST => 'Bekerülési érték',
                        PricingCalculationRow::TYPE_RETAIL => 'Kisker',
                        PricingCalculationRow::TYPE_WHOLESALE => 'Nagyker',
                        PricingCalculationRow::TYPE_DISTRIBUTOR => 'Disztribútor',
                    ])
                    ->required(),

                Select::make('currency_id')
                    ->disabled(fn (?PricingCalculationRow $record) => self::isManagedPrice($record))
                    ->label('Pénznem')
                    ->relationship('currency', 'code')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('calculated_price')
                    ->label('Kalkulált ár')
                    ->numeric()
                    ->disabled(),

                TextInput::make('base_price')->label('Számítás alapja')->numeric()->disabled(),
                TextInput::make('multiplier')->label('Kalkulációs szorzó / kedvezmény-együttható')->numeric()->disabled(),
                TextInput::make('source_wholesale_row_id')->label('Forrás nagyker kalkulációs sor')->numeric()->disabled(),
                TextInput::make('distributor_discount_percent')->label('Disztribútori kedvezmény %')->numeric()->disabled(),
                TextInput::make('unrounded_price')->label('Kerekítés előtti ár')->numeric()->disabled(),
                Select::make('pricing_rounding_rule_id')->label('Kalkuláció kerekítési szabálya')
                    ->relationship('pricingRoundingRule', 'name')->disabled(),
                TextInput::make('retained_price')->label('Korábban jóváhagyott, megtartott ár')->numeric()->disabled(),
                Toggle::make('requires_review')->label('Felülvizsgálat szükséges')->disabled(),

                TextInput::make('manual_price')
                    ->disabled(fn (?PricingCalculationRow $record) => self::isManagedPrice($record))
                    ->helperText(fn (?PricingCalculationRow $record) => self::isManagedPrice($record) ? 'Az árszint saját kisker/nagyker munkafelületén szerkeszthető.' : null)
                    ->label('Manuális ár')
                    ->numeric(),

                Select::make('status')
                    ->disabled(fn (?PricingCalculationRow $record) => self::isManagedPrice($record))
                    ->helperText(fn (?PricingCalculationRow $record) => self::isManagedPrice($record) ? 'Az árszint saját munkafelületének Jóváhagyás műveletével állítható.' : null)
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

    private static function isManagedPrice(?PricingCalculationRow $record): bool
    {
        return $record?->exists && match ($record->price_type) {
            PricingCalculationRow::TYPE_RETAIL => in_array($record->currency?->code, ['HUF', 'EUR', 'CZK', 'RON', 'PLN'], true),
            PricingCalculationRow::TYPE_WHOLESALE => in_array($record->currency?->code, ['HUF', 'EUR'], true),
            PricingCalculationRow::TYPE_DISTRIBUTOR => $record->currency?->code === 'EUR',
            default => false,
        };
    }
}

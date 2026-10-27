<?php

namespace App\Filament\Resources\PricingRoundingRules\Schemas;

use App\Models\PricingCalculationRow;
use App\Models\PricingRoundingRule;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PricingRoundingRuleForm
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

                TextInput::make('name')
                    ->label('Név')
                    ->required()
                    ->maxLength(255),

                Select::make('price_type')
                    ->label('Ártípus')
                    ->options(self::priceTypeOptions())
                    ->live()
                    ->required(),

                Select::make('price_list_id')
                    ->label('Árlista')
                    ->relationship('priceList', 'code')
                    ->searchable()
                    ->preload(),

                Select::make('currency_id')
                    ->label('Pénznem')
                    ->relationship('currency', 'code')
                    ->searchable()
                    ->preload(),

                TextInput::make('round_to')
                    ->label('Kerekítés egysége')
                    ->numeric(),

                TextInput::make('decimal_places')
                    ->label('Tizedesek száma')
                    ->numeric()
                    ->integer(),

                Select::make('mode')
                    ->label('Mód')
                    ->helperText(fn (Get $get) => $get('price_type') === 'outlet'
                        ? 'Outletnél az irány automatikus: felfelé, ha a korrekció utáni ár teljesíti a minimumkedvezményt; különben lefelé. A kiválasztott hagyományos módot itt nem alkalmazzuk.' : null)
                    ->disabled(fn (Get $get) => $get('price_type') === 'outlet')
                    ->dehydrated()
                    ->options([
                        PricingRoundingRule::MODE_NEAREST => 'Legközelebbi',
                        PricingRoundingRule::MODE_UP => 'Felfelé',
                        PricingRoundingRule::MODE_DOWN => 'Lefelé',
                    ])
                    ->default(PricingRoundingRule::MODE_NEAREST)
                    ->required(),

                TextInput::make('adjustment')
                    ->label('Kerekítés utáni korrekció')
                    ->numeric()
                    ->default(0)
                    ->required(),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true),
            ])
            ->columns(2);
    }

    public static function priceTypeOptions(): array
    {
        return [
            PricingCalculationRow::TYPE_COST => 'Bekerülési érték',
            PricingCalculationRow::TYPE_RETAIL => 'Kisker',
            PricingCalculationRow::TYPE_WHOLESALE => 'Nagyker',
            PricingCalculationRow::TYPE_DISTRIBUTOR => 'Disztribútor',
            PricingCalculationRow::TYPE_OUTLET => 'Outlet',
        ];
    }
}

<?php

namespace App\Filament\Resources\PricingParameters\Schemas;

use App\Models\PricingParameter;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PricingParameterForm
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

                Select::make('parameter_type')
                    ->label('Paraméter')
                    ->options([
                        PricingParameter::TYPE_SHIPPING_COST_PERCENT => 'Szállítási költség %',
                        PricingParameter::TYPE_CUSTOMS_PERCENT => 'Vám %',
                        PricingParameter::TYPE_RETAIL_MULTIPLIER => 'Kisker szorzó',
                        PricingParameter::TYPE_COUNTRY_MULTIPLIER => 'Ország / pénznem szorzó',
                        PricingParameter::TYPE_WHOLESALE_MARGIN => 'Nagyker haszonkulcs',
                        PricingParameter::TYPE_DISTRIBUTOR_DISCOUNT => 'Disztribútor kedvezmény %',
                    ])
                    ->required(),

                Select::make('scope_type')
                    ->label('Szűkítés')
                    ->options([
                        PricingParameter::SCOPE_GLOBAL => 'Globális',
                        PricingParameter::SCOPE_SUPPLIER_COUNTRY => 'Beszállító ország',
                        PricingParameter::SCOPE_ITEM_MAIN_GROUP => 'Főcsoport',
                        PricingParameter::SCOPE_PRODUCT => 'Modell',
                    ])
                    ->default(PricingParameter::SCOPE_GLOBAL)
                    ->live()
                    ->required(),

                TextInput::make('supplier_country_code')
                    ->label('Beszállító országkód')
                    ->maxLength(2)
                    ->visible(fn ($get): bool => $get('scope_type') === PricingParameter::SCOPE_SUPPLIER_COUNTRY)
                    ->dehydrated(fn ($get): bool => $get('scope_type') === PricingParameter::SCOPE_SUPPLIER_COUNTRY),

                Select::make('item_main_group_id')
                    ->label('Főcsoport')
                    ->relationship('itemMainGroup', 'name_hu')
                    ->searchable()
                    ->preload()
                    ->visible(fn ($get): bool => $get('scope_type') === PricingParameter::SCOPE_ITEM_MAIN_GROUP)
                    ->dehydrated(fn ($get): bool => $get('scope_type') === PricingParameter::SCOPE_ITEM_MAIN_GROUP),

                Select::make('product_id')
                    ->label('Modell')
                    ->relationship('product', 'model_code')
                    ->searchable()
                    ->preload()
                    ->visible(fn ($get): bool => $get('scope_type') === PricingParameter::SCOPE_PRODUCT)
                    ->dehydrated(fn ($get): bool => $get('scope_type') === PricingParameter::SCOPE_PRODUCT),

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

                TextInput::make('value')
                    ->label('Érték')
                    ->numeric()
                    ->required(),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true),
            ])
            ->columns(2);
    }
}

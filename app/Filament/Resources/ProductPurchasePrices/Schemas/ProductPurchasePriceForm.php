<?php

namespace App\Filament\Resources\ProductPurchasePrices\Schemas;

use App\Models\Color;
use App\Models\Product;
use App\Models\Supplier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductPurchasePriceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Termék')
                    ->relationship(
                        name: 'product',
                        titleAttribute: 'model_code',
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Product $record): string => trim(
                            collect([
                                $record->model_code,
                                $record->model_name_hu
                                    ?? $record->model_name_en,
                            ])
                                ->filter()
                                ->implode(' | ')
                        )
                    )
                    ->searchable([
                        'model_code',
                        'model_name_hu',
                        'model_name_en',
                    ])
                    ->required(),

                Select::make('color_id')
                    ->label('Szín')
                    ->relationship(
                        name: 'color',
                        titleAttribute: 'code',
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Color $record): string => trim(
                            collect([
                                $record->code,
                                $record->name_hu
                                    ?? $record->name_en,
                            ])
                                ->filter()
                                ->implode(' | ')
                        )
                    )
                    ->searchable([
                        'code',
                        'name_hu',
                        'name_en',
                    ])
                    ->placeholder('Általános ár – minden színre')
                    ->nullable(),

                Select::make('supplier_id')
                    ->label('Beszállító')
                    ->relationship(
                        name: 'supplier',
                        titleAttribute: 'erp_partner_code',
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Supplier $record): string => trim(
                            collect([
                                $record->erp_partner_code,
                                $record->short_name ?: $record->name,
                            ])
                                ->filter()
                                ->implode(' | ')
                        )
                    )
                    ->searchable([
                        'erp_partner_code',
                        'short_name',
                        'name',
                    ])
                    ->required(),

                Select::make('currency_id')
                    ->label('Pénznem')
                    ->relationship(
                        name: 'currency',
                        titleAttribute: 'code',
                    )
                    ->searchable()
                    ->required(),

                TextInput::make('purchase_price')
                    ->label('Beszerzési ár')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true),
            ]);
    }
}
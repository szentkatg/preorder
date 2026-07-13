<?php

namespace App\Filament\Resources\ProductPurchasePrices\Schemas;

use App\Models\Color;
use App\Models\Product;
use App\Models\Supplier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

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
                        fn ($record): string => "{$record->model_code} - {$record->name_hu}"
                    )
                    ->searchable([
                        'model_code',
                        'name_hu',
                        'name_en',
                    ])
                    ->live()
                    ->afterStateUpdated(
                        fn (Set $set) => $set('color_id', null)
                    )
                    ->required(),

                Select::make('color_id')
                    ->label('Szín')
                    ->options(function (Get $get): array {
                        $productId = $get('product_id');

                        if (! $productId) {
                            return [];
                        }

                        return Color::query()
                            ->whereHas('skus', function ($query) use ($productId) {
                                $query->where('product_id', $productId);
                            })
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Color $color): array => [
                                $color->id => "{$color->code} - {$color->name_hu}",
                            ])
                            ->all();
                    })
                    ->disabled(fn (Get $get): bool => blank($get('product_id')))
                    ->placeholder('Általános ár – minden színre')
                    ->nullable()
                    ->native(false),

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
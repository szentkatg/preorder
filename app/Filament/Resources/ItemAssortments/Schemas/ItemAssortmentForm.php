<?php

namespace App\Filament\Resources\ItemAssortments\Schemas;

use App\Models\Sku;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ItemAssortmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Termék')
                    ->relationship('product', 'model_code')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live(),

                Select::make('color_id')
                    ->label('Szín')
                    ->relationship('color', 'name_hu')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live(),

                Select::make('assortment_sku_id')
                    ->label('Gyűjtő SKU')
                    ->options(function ($get) {
                        return Sku::query()
                            ->where('type', 'assortment')
                            ->when($get('product_id'), fn ($query, $productId) => $query->where('product_id', $productId))
                            ->when($get('color_id'), fn ($query, $colorId) => $query->where('color_id', $colorId))
                            ->orderBy('sku_code')
                            ->pluck('sku_code', 'id');
                    })
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('component_sku_id')
                    ->label('Tartalmazott SKU')
                    ->options(function ($get) {
                        return Sku::query()
                            ->select('skus.*')
                            ->where('skus.type', 'normal')
                            ->when($get('product_id'), fn ($query, $productId) => $query->where('skus.product_id', $productId))
                            ->when($get('color_id'), fn ($query, $colorId) => $query->where('skus.color_id', $colorId))
                            ->leftJoin('size_range_items', 'skus.size_id', '=', 'size_range_items.size_id')
                            ->orderBy('size_range_items.sort_order')
                            ->pluck('skus.sku_code', 'skus.id');
                    })
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('quantity')
                    ->label('Darab')
                    ->numeric()
                    ->required()
                    ->default(1),
            ]);
    }
}
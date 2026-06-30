<?php

namespace App\Filament\Resources\ColorImages\Schemas;

use App\Models\Color;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ColorImageForm
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
                    ->live()
                    ->required(),

                Select::make('color_id')
                    ->label('Szín')
                    ->options(function (callable $get) {
                        $productId = $get('product_id');

                        if (! $productId) {
                            return [];
                        }

                        return Color::query()
                            ->where('product_id', $productId)
                            ->orderBy('sort_order')
                            ->pluck('name_hu', 'id');
                    })
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Ha nincs kiválasztva szín, a kép általános modellkép lesz.'),

                TextInput::make('image_url')
                    ->label('Kép URL / útvonal')
                    ->required()
                    ->maxLength(1000)
                    ->columnSpanFull(),

                TextInput::make('sort_order')
                    ->label('Sorrend')
                    ->numeric()
                    ->default(10)
                    ->required(),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true)
                    ->required(),
            ]);
    }
}
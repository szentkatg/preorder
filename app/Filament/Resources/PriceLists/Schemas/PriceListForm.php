<?php

namespace App\Filament\Resources\PriceLists\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use App\Models\PriceList;
use Illuminate\Database\Eloquent\Builder;

class PriceListForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')->required(),

                TextInput::make('name_hu')
                    ->label('Név HU')
                    ->required(),

                TextInput::make('name_en')
                    ->label('Név EN'),

                Select::make('type')
                    ->label('Árlista típusa')
                    ->options([
                        'wholesale' => 'Nagyker',
                        'retail' => 'Kisker',
                    ])
                    ->default('wholesale')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, $set) {
                        if ($state === 'retail') {
                            $set('retail_price_list_id', null);
                        }
                    }),

                Select::make('season_id')
                    ->label('Szezon')
                    ->relationship('season', 'name')
                    ->searchable()
                    ->preload(),
                
                Select::make('currency_id')
                    ->label('Pénznem')
                    ->relationship('currency', 'code')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('retail_price_list_id')
                    ->label('Kapcsolt kisker árlista')
                    ->options(function ($record) {
                        return PriceList::query()
                            ->where('type', 'retail')
                            ->when(
                                $record,
                                fn ($query) => $query->whereKeyNot($record->id)
                            )
                            ->orderBy('code')
                            ->pluck('name_hu', 'id');
                    })
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->visible(fn ($get) => $get('type') === 'wholesale')
                    ->dehydrated(fn ($get) => $get('type') === 'wholesale'),

                Toggle::make('active')
                    ->default(true),
            ]);
    }
}
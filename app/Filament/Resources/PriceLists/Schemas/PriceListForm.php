<?php

namespace App\Filament\Resources\PriceLists\Schemas;

use App\Models\Currency;
use App\Models\PriceList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class PriceListForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kód')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'unique' => 'Ez az árlistakód már létezik. Használd a meglévő árlistát, vagy adj meg másik kódot.',
                    ]),

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
                        'distributor' => 'Disztribútor (EUR)',
                    ])
                    ->default('wholesale')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, $set) {
                        if ($state !== 'wholesale') {
                            $set('retail_price_list_id', null);
                        }
                        if ($state !== 'distributor') {
                            $set('source_wholesale_price_list_id', null);
                        }
                    }),

                Select::make('currency_id')
                    ->label('Pénznem')
                    ->relationship('currency', 'code', modifyQueryUsing: fn ($query, $get) => $query
                        ->when($get('type') === 'distributor', fn ($query) => $query->where('code', 'EUR')))
                    ->rules(fn ($get) => $get('type') === 'distributor' ? [Rule::exists('currencies', 'id')->where('code', 'EUR')] : [])
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

                Select::make('source_wholesale_price_list_id')->label('Forrás EUR nagyker árlista')
                    ->options(fn () => PriceList::query()->where('type', 'wholesale')->where('active', true)
                        ->whereHas('currency', fn ($query) => $query->where('code', 'EUR'))->orderBy('code')->pluck('code', 'id'))
                    ->searchable()->preload()->required(fn ($get) => $get('type') === 'distributor')
                    ->visible(fn ($get) => $get('type') === 'distributor')->dehydrated(fn ($get) => $get('type') === 'distributor')
                    ->rules([Rule::exists('price_lists', 'id')->where('type', 'wholesale')->where('active', true)
                        ->whereIn('currency_id', Currency::query()->select('id')->where('code', 'EUR'))])
                    ->helperText('A disztribútori nettó ár ebből az EUR nagyker árlistából készül. A kedvezmény és a kerekítés külön paraméter.'),

                Toggle::make('active')
                    ->default(true),
            ]);
    }
}

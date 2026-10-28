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

                TextInput::make('name')
                    ->label('Név')
                    ->required(),

                Select::make('type')
                    ->label('Árlista típusa')
                    ->options([
                        'wholesale' => 'Nagyker',
                        'retail' => 'Kisker',
                        'distributor' => 'Disztribútor (EUR)',
                        'outlet' => 'Outlet (bruttó kisker)',
                    ])
                    ->default('wholesale')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, $set) {
                        if (! in_array($state, ['wholesale', 'distributor'], true)) {
                            $set('retail_price_list_id', null);
                        }
                        if ($state !== 'distributor') {
                            $set('source_wholesale_price_list_id', null);
                        }
                        if ($state !== 'outlet') {
                            $set('source_retail_price_list_id', null);
                        }
                    }),

                Select::make('currency_id')
                    ->label('Pénznem')
                    ->relationship('currency', 'code', modifyQueryUsing: fn ($query, $get) => $query
                        ->when($get('type') === 'distributor', fn ($query) => $query->where('code', 'EUR')))
                    ->rules(fn ($get) => $get('type') === 'distributor' ? [Rule::exists('currencies', 'id')->where('code', 'EUR')] : [])
                    ->searchable()
                    ->preload()
                    ->live()
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
                            ->get()->mapWithKeys(fn (PriceList $list) => [$list->id => $list->translate('name')]);
                    })
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->visible(fn ($get) => in_array($get('type'), ['wholesale', 'distributor'], true))
                    ->dehydrated(fn ($get) => in_array($get('type'), ['wholesale', 'distributor'], true))
                    ->helperText('A partnerportálon és az exportban megjelenő kisker árak forrása. Disztribútornál nem ez, hanem a külön megadott nagyker árlista a kalkuláció alapja.'),

                Select::make('source_wholesale_price_list_id')->label('Forrás EUR nagyker árlista')
                    ->options(fn () => PriceList::query()->where('type', 'wholesale')->where('active', true)
                        ->whereHas('currency', fn ($query) => $query->where('code', 'EUR'))->orderBy('code')->pluck('code', 'id'))
                    ->searchable()->preload()->required(fn ($get) => $get('type') === 'distributor')
                    ->visible(fn ($get) => $get('type') === 'distributor')->dehydrated(fn ($get) => $get('type') === 'distributor')
                    ->rules([Rule::exists('price_lists', 'id')->where('type', 'wholesale')->where('active', true)
                        ->whereIn('currency_id', Currency::query()->select('id')->where('code', 'EUR'))])
                    ->helperText('A disztribútori nettó ár ebből az EUR nagyker árlistából készül. A kedvezmény és a kerekítés külön paraméter.'),

                Select::make('source_retail_price_list_id')->label('Forrás normál kisker árlista (outlet)')
                    ->options(fn ($get) => PriceList::where('active', true)->where('type', 'retail')
                        ->where('currency_id', $get('currency_id') ?? 0)->orderBy('code')->pluck('code', 'id'))
                    ->searchable()->preload()->required(fn ($get) => $get('type') === 'outlet')
                    ->visible(fn ($get) => $get('type') === 'outlet')->dehydrated(fn ($get) => $get('type') === 'outlet')
                    ->rules(fn ($get) => [Rule::exists('price_lists', 'id')->where('active', true)->where('type', 'retail')->where('currency_id', $get('currency_id'))])
                    ->helperText('Az outlet ebből az azonos pénznemű bruttó kisker árból készül, külön kedvezménnyel és outlet kerekítéssel.'),

                Toggle::make('active')
                    ->default(true),
            ]);
    }
}

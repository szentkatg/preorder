<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Brand;
use App\Models\Order;
use App\Models\OrderSheetType;
use App\Models\OrderType;
use App\Models\PartnerAddress;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('season_id')
                    ->label('Szezon')
                    ->relationship('season', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabled(fn ($record) => $record?->isSubmitted()),

                Select::make('partner_id')
                    ->label('Partner')
                    ->relationship('partner', 'name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->required()
                    ->disabled(fn ($record) => $record?->isSubmitted())
                    ->afterStateUpdated(function (Set $set) {
                        $set('partner_address_id', null);
                        $set('price_list_id', null);
                        $set('currency_id', null);
                        $set('language_id', null);
                        $set('brand_id', null);
                        $set('order_sheet_type_id', null);
                    }),

                Select::make('partner_address_id')
                    ->label('Partner cím')
                    ->options(function (Get $get) {
                        $partnerId = $get('partner_id');

                        if (! $partnerId) {
                            return [];
                        }

                        return PartnerAddress::query()
                            ->where('partner_id', $partnerId)
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id');
                    })
                    ->searchable()
                    ->live()
                    ->required()
                    ->disabled(fn ($record) => $record?->isSubmitted())
                    ->afterStateUpdated(function (Set $set, ?int $state) {
                        $address = PartnerAddress::find($state);

                        $set('price_list_id', $address?->price_list_id);
                        $set('currency_id', $address?->currency_id);
                        $set('language_id', $address?->language_id);

                        $set('brand_id', null);
                        $set('order_sheet_type_id', null);
                    }),

                Select::make('brand_id')
                    ->label('Márka')
                    ->options(function (Get $get) {
                        $addressId = $get('partner_address_id');

                        if (! $addressId) {
                            return [];
                        }

                        return Brand::query()
                            ->whereHas('partnerAddresses', fn ($query) => $query->where('partner_addresses.id', $addressId)
                            )
                            ->orderBy('name')
                            ->pluck('name', 'id');
                    })
                    ->searchable()
                    ->live()
                    ->required()
                    ->disabled(fn ($record) => $record?->isSubmitted())
                    ->afterStateUpdated(fn (Set $set) => $set('order_sheet_type_id', null)),

                Select::make('order_sheet_type_id')
                    ->label('Rendelőlap')
                    ->options(function (Get $get) {
                        $addressId = $get('partner_address_id');

                        if (! $addressId) {
                            return [];
                        }

                        return OrderSheetType::query()
                            ->whereHas('partnerAddresses', fn ($query) => $query->where('partner_addresses.id', $addressId)
                            )
                            ->orderBy('name')
                            ->pluck('name', 'id');
                    })
                    ->searchable()
                    ->required()
                    ->disabled(fn ($record) => $record?->isSubmitted()),

                TextInput::make('reference_number')
                    ->label('Hivatkozási szám')
                    ->required()
                    ->maxLength(100)
                    ->dehydrateStateUsing(
                        fn (?string $state): ?string => filled($state)
                            ? trim($state)
                            : $state
                    )
                    ->unique(
                        table: Order::class,
                        column: 'reference_number',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                            ->where('season_id', $get('season_id'))
                            ->where('partner_id', $get('partner_id'))
                            ->where('partner_address_id', $get('partner_address_id'))
                            ->where('brand_id', $get('brand_id'))
                            ->where('order_sheet_type_id', $get('order_sheet_type_id')),
                    )
                    ->disabled(fn ($record) => $record?->isSubmitted()),

                Select::make('order_type_id')
                    ->label('Rendeléstípus')
                    ->relationship('orderType', 'name')
                    ->getOptionLabelFromRecordUsing(
                        fn (OrderType $record): string => "{$record->code} – {$record->name}"
                    )
                    ->default(fn () => OrderType::query()->where('code', 'VRELO')->value('id'))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabled(fn ($record) => $record?->isSubmitted()),

                Select::make('price_list_id')
                    ->label('Árlista')
                    ->relationship('priceList', 'code')
                    ->disabled()
                    ->dehydrated()
                    ->disabled(fn ($record) => $record?->isSubmitted()),

                Select::make('currency_id')
                    ->label('Pénznem')
                    ->relationship('currency', 'code')
                    ->disabled()
                    ->dehydrated()
                    ->disabled(fn ($record) => $record?->isSubmitted()),

                Select::make('language_id')
                    ->label('Nyelv')
                    ->relationship('language', 'code')
                    ->disabled()
                    ->dehydrated()
                    ->disabled(fn ($record) => $record?->isSubmitted()),

                Select::make('status')
                    ->label('Státusz')
                    ->options([
                        'editing' => 'Kitöltés alatt',
                        'draft' => 'Piszkozat',
                        'submitted' => 'Beküldve',
                    ])
                    ->default('editing')
                    ->required(),

                Textarea::make('notes')
                    ->label('Megjegyzés'),
            ]);
    }
}

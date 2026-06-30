<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Models\PriceListItem;
use App\Models\Sku;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('sku_id')
                    ->label('SKU')
                    ->options(function () {
                        $order = $this->getOwnerRecord();

                        return Sku::query()
                            ->whereHas('product', function ($query) use ($order) {
                                $query
                                    ->where('season_id', $order->season_id)
                                    ->where('brand_id', $order->brand_id)
                                    ->where('order_sheet_type_id', $order->order_sheet_type_id)
                                    ->where('active', true);
                            })
                            ->where('active', true)
                            ->orderBy('sku_code')
                            ->pluck('sku_code', 'id');
                    })
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('quantity')
                    ->label('Mennyiség')
                    ->numeric()
                    ->required()
                    ->default(0),
            ]);
    }

    protected function calculatePrices(array $data): array
    {
        $order = $this->getOwnerRecord();

        $sku = Sku::find($data['sku_id'] ?? null);

        $unitPrice = 0;

        if ($sku && $order->price_list_id && $order->season_id) {
            $priceItem = PriceListItem::query()
                ->where('price_list_id', $order->price_list_id)
                ->where('season_id', $order->season_id)
                ->where('product_id', $sku->product_id)
                ->first();
        
            $unitPrice = $priceItem?->net_price ?? 0;
        }

        $quantity = (int) ($data['quantity'] ?? 0);

        $assortmentContent = 0;

        if ($sku) {
            $assortmentContent = (int) $sku
                ->assortmentComponents()
                ->sum('quantity');
        }

        $effectiveQuantity = $assortmentContent > 0
            ? $quantity * $assortmentContent
            : $quantity;

        $data['unit_price'] = $unitPrice;
        $data['line_total'] = $unitPrice * $effectiveQuantity;

        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku.sku_code')
                    ->label('Cikkszám')
                    ->searchable(),

                TextColumn::make('sku.sku_name')
                    ->label('Megnevezés')
                    ->searchable(),

                TextColumn::make('quantity')
                    ->label('Rendelt egység'),

                TextColumn::make('assortment_content')
                    ->label('Gyűjtő tartalma')
                    ->state(fn ($record) => $record->assortment_content > 0 ? $record->assortment_content : '-'),

                TextColumn::make('effective_quantity')
                    ->label('Tényleges db'),

                TextColumn::make('unit_price')
                    ->label('Egységár')
                    ->numeric(2),

                TextColumn::make('line_total')
                    ->label('Sorérték')
                    ->numeric(2),
            ])
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])            
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => ! $this->getOwnerRecord()->isSubmitted())
                    ->mutateDataUsing(fn (array $data): array => $this->calculatePrices($data)),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => ! $this->getOwnerRecord()->isSubmitted())
                    ->mutateDataUsing(fn (array $data): array => $this->calculatePrices($data)),

                DeleteAction::make()
                    ->visible(fn () => ! $this->getOwnerRecord()->isSubmitted()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => ! $this->getOwnerRecord()->isSubmitted()),
                ]),
            ]);
    }
}
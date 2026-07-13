<?php

namespace App\Filament\Resources\ProductPurchasePrices\Tables;

use App\Models\Brand;
use App\Models\Season;
use App\Models\Supplier;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductPurchasePricesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product_purchase_price_id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('product.model_code')
                    ->label('Modellkód')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product.model_name_hu')
                    ->label('Modellnév')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('color.code')
                    ->label('Szín')
                    ->placeholder('Általános')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supplier.erp_partner_code')
                    ->label('Beszállító ERP-kód')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supplier.short_name')
                    ->label('Beszállító')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('currency.code')
                    ->label('Pénznem')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('purchase_price')
                    ->label('Beszerzési ár')
                    ->formatStateUsing(
                        fn ($state): string => number_format(
                            (float) $state,
                            4,
                            ',',
                            ' '
                        )
                    )
                    ->alignEnd()
                    ->sortable(),

                IconColumn::make('active')
                    ->label('Aktív')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('season_id')
                    ->label('Szezon')
                    ->options(
                        Season::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()
                    )
                    ->searchable()
                    ->query(
                        fn (Builder $query, array $data): Builder =>
                            $query->when(
                                $data['value'] ?? null,
                                fn (Builder $query, $seasonId): Builder =>
                                    $query->whereHas(
                                        'product',
                                        fn (Builder $productQuery): Builder =>
                                            $productQuery->where(
                                                'season_id',
                                                $seasonId
                                            )
                                    )
                            )
                    ),

                SelectFilter::make('brand_id')
                    ->label('Márka')
                    ->options(
                        Brand::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()
                    )
                    ->searchable()
                    ->query(
                        fn (Builder $query, array $data): Builder =>
                            $query->when(
                                $data['value'] ?? null,
                                fn (Builder $query, $brandId): Builder =>
                                    $query->whereHas(
                                        'product',
                                        fn (Builder $productQuery): Builder =>
                                            $productQuery->where(
                                                'brand_id',
                                                $brandId
                                            )
                                    )
                            )
                    ),

                SelectFilter::make('supplier_id')
                    ->label('Beszállító')
                    ->options(
                        Supplier::query()
                            ->orderBy('erp_partner_code')
                            ->get()
                            ->mapWithKeys(
                                fn (Supplier $supplier): array => [
                                    $supplier->supplier_id => trim(
                                        collect([
                                            $supplier->erp_partner_code,
                                            $supplier->short_name
                                                ?: $supplier->name,
                                        ])
                                            ->filter()
                                            ->implode(' | ')
                                    ),
                                ]
                            )
                            ->all()
                    )
                    ->searchable(),

                SelectFilter::make('active')
                    ->label('Állapot')
                    ->options([
                        '1' => 'Aktív',
                        '0' => 'Inaktív',
                    ])
                    ->default('1')
                    ->placeholder('Minden'),
            ])
            ->defaultSort('product_purchase_price_id', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
<?php

namespace App\Filament\Resources\Products\Tables;

use App\Services\ProductSkuSyncService;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('brand.name')
                    ->label('Márka')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('orderSheetType.name')
                    ->label('Rendelőlap')
                    ->sortable(),

                TextColumn::make('season.name')
                    ->label('Szezon')
                    ->sortable(),

                TextColumn::make('model_code')
                    ->label('Modell')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name_hu')
                    ->label('Név')
                    ->searchable(),

                TextColumn::make('itemMainGroup.code')
                    ->label('Főcsoport')
                    ->sortable(),

                TextColumn::make('sizeRange.code')
                    ->label('Méretsor'),

                TextColumn::make('promised_delivery_date')
                    ->label('Ígért szállítási határidő')
                    ->date('Y-m-d')
                    ->placeholder('—'),

                TextColumn::make('catalog_page')
                    ->label('Katalógus oldal')
                    ->sortable(),

                TextColumn::make('catalog_sort')
                    ->label('Kat. sorrend')
                    ->sortable(),

                IconColumn::make('active')
                    ->label('Aktív')
                    ->boolean(),
            ])
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
            ->filters([
                SelectFilter::make('brand')
                    ->label('Márka')
                    ->relationship('brand', 'name'),

                SelectFilter::make('orderSheetType')
                    ->label('Rendelőlap')
                    ->relationship('orderSheetType', 'name'),

                SelectFilter::make('season')
                    ->label('Szezon')
                    ->relationship('season', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('syncSkus')
                        ->label('SKU-k szinkronizálása')
                        ->icon('heroicon-o-cube')
                        ->requiresConfirmation()
                        ->modalHeading('SKU-k szinkronizálása')
                        ->modalDescription('A kiválasztott termékekhez létrehozza vagy frissíti az SKU-kat, a már nem érvényes SKU-kat pedig inaktiválja.')
                        ->action(function (Collection $records): void {
                            $service = app(ProductSkuSyncService::class);

                            $created = 0;
                            $updated = 0;
                            $deactivated = 0;
                            $skipped = 0;

                            foreach ($records as $product) {
                                $result = $service->sync($product);

                                $created += $result['created'];
                                $updated += $result['updated'];
                                $deactivated += $result['deactivated'];
                                $skipped += $result['skipped'];
                            }

                            Notification::make()
                                ->title('SKU szinkron kész')
                                ->body("Létrehozva: {$created}, frissítve: {$updated}, inaktiválva: {$deactivated}, kihagyva: {$skipped}")
                                ->success()
                                ->send();
                        }),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

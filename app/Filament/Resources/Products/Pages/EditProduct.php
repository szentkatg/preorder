<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Services\ProductSkuSyncService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateSkus')
                ->label('SKU-k szinkronizálása')
                ->icon('heroicon-o-cube')
                ->requiresConfirmation()
                ->modalHeading('SKU-k szinkronizálása')
                ->modalDescription('A rendszer létrehozza vagy frissíti az aktuális színekhez és méretsorhoz tartozó SKU-kat, a már nem érvényes SKU-kat pedig inaktiválja.')
                ->action(function () {
                    $result = app(ProductSkuSyncService::class)
                        ->sync($this->record);

                    if (($result['skipped'] ?? 0) > 0) {
                        Notification::make()
                            ->title('Nincs méretsor megadva a termékhez')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('SKU szinkron kész')
                        ->body(
                            'Létrehozva: ' . $result['created'] .
                            ', frissítve: ' . $result['updated'] .
                            ', inaktiválva: ' . $result['deactivated']
                        )
                        ->success()
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }
}
<?php

namespace App\Filament\Resources\PricingProjects\Pages;

use App\Services\Pricing\CostCalculationService;
use App\Filament\Resources\PricingProjects\PricingProjectResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPricingProject extends EditRecord
{
    protected static string $resource = PricingProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recalculateCost')
                ->label('Bekerülési értékek újraszámítása')
                ->icon('heroicon-o-calculator')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Bekerülési értékek újraszámítása')
                ->modalDescription('A rendszer az árprojekt szezonjának aktív beszerzési áraiból újraszámolja és elmenti a bekerülési érték kalkulációs sorokat. Több aktív beszerzési ár esetén a legalacsonyabb HUF bekerülési érték kerül kiválasztásra.')
                ->action(function (): void {
                    $result = app(CostCalculationService::class)
                        ->recalculate($this->record);

                    Notification::make()
                        ->title('Bekerülési érték kalkuláció kész')
                        ->body(
                            'Létrehozva: ' . $result['created'] .
                            ', frissítve: ' . $result['updated'] .
                            ', kihagyott csoport: ' . $result['skipped'] .
                            ', aktív beszerzési ár nélküli termék: ' .
                            $result['products_without_active_purchase_price'] .
                            ', hibák: ' . count($result['errors']) .
                            ', futás azonosító: ' . $result['run_id']
                        )
                        ->success()
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }
}

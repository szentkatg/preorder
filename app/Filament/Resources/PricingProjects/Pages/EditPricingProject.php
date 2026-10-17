<?php

namespace App\Filament\Resources\PricingProjects\Pages;

use App\Filament\Resources\PricingProjects\PricingProjectResource;
use App\Filament\Support\Actions\CalculateAllProjectPricesAction;
use App\Filament\Support\Actions\CalculateHufRetailAction;
use App\Filament\Support\Actions\PricingProjectLifecycleActions;
use App\Models\PricingProject;
use App\Services\Pricing\CostCalculationAuthorization;
use App\Services\Pricing\CostCalculationService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditPricingProject extends EditRecord
{
    protected static string $resource = PricingProjectResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            $stored = PricingProject::lockForUpdate()->findOrFail($record->id);
            abort_unless($stored->isOpenForPricing(), 403, 'A lezárt projekt csak a külön Visszanyitás művelettel nyitható meg.');
            abort_unless(in_array($data['status'] ?? $stored->status, ['draft', 'active'], true), 422);
            unset($data['season_id'], $data['closed_by'], $data['closed_at']);
            $stored->update($data);

            return $stored;
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            CalculateAllProjectPricesAction::make()->record(fn () => $this->record),
            PricingProjectLifecycleActions::close()->record(fn () => $this->record),
            PricingProjectLifecycleActions::reopen()->record(fn () => $this->record),
            PricingProjectLifecycleActions::export()->record(fn () => $this->record),
            Action::make('recalculateCost')
                ->visible(fn (): bool => CostCalculationAuthorization::canRecalculate($this->record))
                ->authorize(fn (): bool => CostCalculationAuthorization::canRecalculate($this->record))
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
                            'Létrehozva: '.$result['created'].
                            ', frissítve: '.$result['updated'].
                            ', kihagyott csoport: '.$result['skipped'].
                            ', aktív beszerzési ár nélküli termék: '.
                            $result['products_without_active_purchase_price'].
                            ', hibák: '.count($result['errors']).
                            ', futás azonosító: '.$result['run_id']
                        )
                        ->success()
                        ->send();
                }),

            CalculateHufRetailAction::make(fn () => $this->record),
            DeleteAction::make(),
        ];
    }
}

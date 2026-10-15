<?php

namespace App\Filament\Support\Actions;

use App\Models\PricingCalculationRow;
use App\Services\Pricing\DistributorCalculationService;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

class CalculateDistributorAction
{
    public static function make(): Action
    {
        return Action::make('calculateDistributor')->label('Disztribútori újraszámítás')->icon('heroicon-o-calculator')->color('success')
            ->visible(fn ($livewire) => $livewire->retailCurrencyCode() === 'EUR' && $livewire->selectedDistributorPriceList
                && $livewire->pricingWorkspaceIsEditable() && Gate::allows('update', $livewire->pricingSelectedProject)
                && Gate::allows('create', PricingCalculationRow::class) && Gate::allows('update', new PricingCalculationRow))
            ->requiresConfirmation()->modalHeading('Nettó disztribútori ár újraszámítása')
            ->modalDescription(fn ($livewire) => ($livewire->selectedDistributorPriceList?->code ?? '').': végleges nettó nagyker ár × (1 − kedvezmény% / 100), majd saját kerekítés. A forrás nagyker kalkuláció legyen aktuális és felülvizsgálatmentes. Manuális és jóváhagyott árakat megőrzünk.')
            ->modalSubmitActionLabel('Újraszámítás')->modalCancelActionLabel('Mégse')
            ->action(function ($livewire): void {
                abort_unless($livewire::canAccess() && $livewire->pricingWorkspaceIsEditable() && $livewire->retailCurrencyCode() === 'EUR'
                    && $livewire->selectedDistributorPriceList, 403);
                try {
                    $result = app(DistributorCalculationService::class)->recalculate($livewire->pricingSelectedProject, $livewire->selectedDistributorPriceList->id);
                    $notice = Notification::make()->title('Disztribútori kalkuláció kész')->body('Létrehozva: '.$result['created'].', frissítve: '.$result['updated']
                        .', felülvizsgálandó: '.$result['requires_review'].', kihagyott: '.$result['skipped'].'. Futás: '.$result['run_id'].'. Részletek a Kalkulációs hibák menüben.');
                    ($result['skipped'] ? $notice->warning() : $notice->success())->send();
                    unset($livewire->averageDistributorNetNetMultiplier);
                    $livewire->resetTable();
                } catch (DomainException $exception) {
                    Notification::make()->title('A disztribútori kalkuláció nem indítható')->body($exception->getMessage())->danger()->send();
                }
            });
    }
}

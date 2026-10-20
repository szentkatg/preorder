<?php

namespace App\Filament\Support\Actions;

use App\Filament\Support\AdminResourceTable;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\WholesaleCalculationService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\Gate;

class CalculateWholesaleAction
{
    public static function make(bool $all = false): Action
    {
        return Action::make($all ? 'calculateAllWholesale' : 'calculateWholesale')
            ->label($all ? 'Összes nagyker ár újraszámítása' : 'Nagyker újraszámítása')->icon('heroicon-o-calculator')->color('success')
            ->requiresConfirmation()->modalHeading('Nettó nagyker árak újraszámítása')
            ->modalDescription('Bruttó kisker ár ÷ nagyker szorzó, majd kerekítés. A kapcsolt kisker árlistát használjuk. Manuális és jóváhagyott végleges árakat nem írunk felül. Az ÁFA beállítása nem része az árképzésnek.')
            ->modalSubmitActionLabel('Újraszámítás')->modalCancelActionLabel('Mégse')
            ->visible(fn ($livewire) => $livewire->pricingWorkspaceIsEditable() && Gate::allows('update', $livewire->pricingSelectedProject)
                && Gate::allows('create', PricingCalculationRow::class) && Gate::allows('update', new PricingCalculationRow))
            ->schema(function ($livewire) use ($all): array {
                if ($all) {
                    return [];
                }
                $options = PriceList::query()->where('type', 'wholesale')->where('active', true)
                    ->whereHas('currency', fn ($query) => $query->where('code', $livewire->retailCurrencyCode()))->pluck('code', 'id')->all();

                return [Text::make(count($options) === 1 ? 'Nagyker célárlista: '.reset($options) : 'Válaszd ki a nagyker célárlistát.'),
                    Select::make('price_list_id')->label('Nagyker árlista')->options($options)->required()->searchable()
                        ->hidden(count($options) === 1)->dehydratedWhenHidden()->default(count($options) === 1 ? array_key_first($options) : null)];
            })
            ->action(function (array $data, $livewire) use ($all): void {
                abort_unless($livewire::canAccess() && $livewire->pricingWorkspaceIsEditable(), 403);
                try {
                    $targetId = null;
                    if (! $all) {
                        $ids = PriceList::query()->where('type', 'wholesale')->where('active', true)
                            ->whereHas('currency', fn ($query) => $query->where('code', $livewire->retailCurrencyCode()))->pluck('id');
                        $targetId = $data['price_list_id'] ?? ($ids->count() === 1 ? $ids->first() : null);
                        if (! $targetId || ! $ids->contains((int) $targetId)) {
                            throw new DomainException('Válaszd ki az aktuális pénznem aktív nagyker árlistáját.');
                        }
                    }
                    $result = app(WholesaleCalculationService::class)->recalculate($livewire->pricingSelectedProject,
                        $all ? null : $livewire->retailCurrencyCode(), $targetId === null ? null : (int) $targetId);
                    $notice = Notification::make()->title('Nagyker kalkuláció kész')->body('Árlisták: '.$result['price_lists']
                        .'. Létrehozva: '.$result['created'].', frissítve: '.$result['updated'].', felülvizsgálandó: '.$result['requires_review']
                        .', kihagyott: '.$result['skipped'].'. Futás: '.$result['run_id'].'. Részletek a Kalkulációs hibák menüben.');
                    ($result['skipped'] ? $notice->warning() : $notice->success())->send();
                    AdminResourceTable::refreshPreservingState($livewire);
                } catch (DomainException $exception) {
                    Notification::make()->title('A nagyker kalkuláció nem indítható')->body($exception->getMessage())->danger()->send();
                }
            });
    }
}

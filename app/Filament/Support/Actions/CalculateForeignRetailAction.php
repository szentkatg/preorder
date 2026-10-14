<?php

namespace App\Filament\Support\Actions;

use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\ForeignRetailCalculationService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

class CalculateForeignRetailAction
{
    public static function make(): Action
    {
        return Action::make('calculateForeignRetail')->label('Devizás kisker újraszámítása')->icon('heroicon-o-calculator')->color('success')
            ->visible(fn ($livewire): bool => $livewire->pricingWorkspaceIsEditable()
                && Gate::allows('update', $livewire->pricingSelectedProject)
                && Gate::allows('create', PricingCalculationRow::class) && Gate::allows('update', new PricingCalculationRow))
            ->modalDescription('Végleges HUF kisker ár ÷ a kiválasztott szezon árfolyama × országszorzó, majd kerekítés. Manuális és jóváhagyott árakat nem írunk felül.')
            ->schema([
                Select::make('source_price_list_id')->label('Forrás HUF kisker árlista')->required()->searchable()
                    ->options(fn (): array => PriceList::query()->where('type', 'retail')->where('active', true)
                        ->whereHas('currency', fn ($query) => $query->where('code', 'HUF'))->pluck('code', 'id')->all()),
                Select::make('price_list_id')->label('Devizás célárlista')->required()->searchable()
                    ->options(fn ($livewire): array => PriceList::query()->where('type', 'retail')->where('active', true)
                        ->whereHas('currency', fn ($query) => $query->where('code', $livewire->retailCurrencyCode()))->pluck('code', 'id')->all()),
            ])->action(function (array $data, $livewire): void {
                abort_unless($livewire::canAccess() && $livewire->pricingWorkspaceIsEditable(), 403);
                $list = PriceList::with('currency')->findOrFail($data['price_list_id']);
                abort_unless($list->currency?->code === $livewire->retailCurrencyCode(), 422);
                try {
                    $result = app(ForeignRetailCalculationService::class)->recalculate($livewire->pricingSelectedProject,
                        PriceList::findOrFail($data['source_price_list_id']), $list);
                    $notification = Notification::make()->title('Devizás kisker kalkuláció kész')
                        ->body('Létrehozva: '.$result['created'].', frissítve: '.$result['updated'].', felülvizsgálandó: '.$result['requires_review']
                            .', kihagyott: '.$result['skipped'].'. Részletek a Kalkulációs hibák menüben. Futás: '.$result['run_id']);
                    ($result['skipped'] > 0 ? $notification->warning() : $notification->success())->send();
                    $livewire->resetTable();
                } catch (DomainException $exception) {
                    Notification::make()->title('A devizás kalkuláció nem indítható')->body($exception->getMessage())->danger()->send();
                }
            });
    }
}

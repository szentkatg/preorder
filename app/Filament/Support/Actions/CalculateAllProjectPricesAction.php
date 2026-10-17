<?php

namespace App\Filament\Support\Actions;

use App\Models\PricingProject;
use App\Services\Pricing\ForeignRetailPriceListResolver;
use App\Services\Pricing\PricingProjectCalculationService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

class CalculateAllProjectPricesAction
{
    public static function make(): Action
    {
        return Action::make('calculateAllPrices')->label('Minden ár kiszámítása')->icon('heroicon-o-calculator')->color('success')
            ->visible(fn (PricingProject $record) => PricingProjectCalculationService::canCalculate($record))
            ->authorize(fn (PricingProject $record) => PricingProjectCalculationService::canCalculate($record))
            ->requiresConfirmation()->modalHeading('Az árprojekt összes árának kiszámítása')
            ->modalDescription('Sorrend: bekerülési érték → HUF kisker → devizás kisker → nagyker → disztribútor és outlet. A manuális és megtartott árak nem vesznek el; a függő árak ezekből a végleges forrásárakból számolódnak. Nem történik automatikus jóváhagyás vagy publikálás. A hibás modellek változatlan árakkal, felülvizsgálati jelzéssel maradnak; a részletek az Árkalkulációs hibák listában láthatók.')
            ->schema(function (): array {
                $resolver = app(ForeignRetailPriceListResolver::class);
                $sources = $resolver->sources();
                if ($sources->count() <= 1) {
                    return [];
                }

                return $resolver->targets()->map(fn ($list) => Select::make('sources.'.$list->id)
                    ->label($list->code.' HUF forrásárlistája')->options($sources->pluck('code', 'id'))->required())->all();
            })
            ->action(function (PricingProject $record, array $data, Action $action): void {
                try {
                    $result = app(PricingProjectCalculationService::class)->calculate($record, $data['sources'] ?? []);
                } catch (DomainException $exception) {
                    Notification::make()->title('A teljes számítás nem indítható')->body($exception->getMessage())->danger()->send();
                    $action->halt();
                }
                $notice = Notification::make()->title('A teljes árkalkuláció befejeződött')
                    ->body('Kiszámított modellek: '.$result['products'].', ársorok: '.$result['prices'].', hibás modellek: '.$result['failed'].
                        ', felülvizsgálandó sorok: '.$result['requires_review'].'. Futás: '.$result['run_id']);
                ($result['failed'] ? $notice->warning()->persistent() : $notice->success())->send();
            });
    }
}

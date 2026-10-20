<?php

namespace App\Filament\Support\Actions;

use App\Filament\Support\AdminResourceTable;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\ForeignRetailCalculationService;
use App\Services\Pricing\ForeignRetailPriceListResolver;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\Gate;

class CalculateForeignRetailAction
{
    public static function make(bool $all = false): Action
    {
        return Action::make($all ? 'calculateAllForeignRetail' : 'calculateForeignRetail')
            ->label($all ? 'Összes devizás kisker újraszámítása' : 'Devizás kisker újraszámítása')
            ->icon('heroicon-o-calculator')->color('success')->requiresConfirmation()
            ->visible(fn ($livewire): bool => $livewire->pricingWorkspaceIsEditable()
                && Gate::allows('update', $livewire->pricingSelectedProject)
                && Gate::allows('create', PricingCalculationRow::class) && Gate::allows('update', new PricingCalculationRow))
            ->modalHeading($all ? 'Összes devizás kisker árlista újraszámítása' : 'Devizás kisker újraszámítása')
            ->modalDescription(($all ? 'Minden aktív EUR/CZK/RON/PLN kisker célárlistát feldolgozunk az aktuális árprojektben. ' : '')
                .'Az egyértelmű párosítás automatikus; csak több választási lehetőségnél kell árlistát választani. Manuális és jóváhagyott árakat nem írunk felül.')
            ->modalSubmitActionLabel('Újraszámítás')->modalCancelActionLabel('Mégse')
            ->schema(fn ($livewire): array => self::schema($all, $livewire->retailCurrencyCode()))
            ->action(function (array $data, $livewire) use ($all): void {
                abort_unless($livewire::canAccess() && $livewire->pricingWorkspaceIsEditable(), 403);
                try {
                    if ($all) {
                        $result = app(ForeignRetailCalculationService::class)->recalculateAll($livewire->pricingSelectedProject, $data['sources'] ?? []);
                    } else {
                        $pair = app(ForeignRetailPriceListResolver::class)->resolvePair($livewire->retailCurrencyCode(),
                            $data['source_price_list_id'] ?? null, $data['price_list_id'] ?? null);
                        $result = app(ForeignRetailCalculationService::class)->recalculate($livewire->pricingSelectedProject, $pair['source'], $pair['target']);
                    }
                    $notification = Notification::make()->title('Devizás kisker kalkuláció kész')
                        ->body(($all ? 'Árlisták: '.$result['price_lists'].'. ' : '')
                            .'Létrehozva: '.$result['created'].', frissítve: '.$result['updated'].', felülvizsgálandó: '.$result['requires_review']
                            .', kihagyott: '.$result['skipped'].'. Részletek a Kalkulációs hibák menüben. Futás: '.$result['run_id']);
                    ($result['skipped'] > 0 ? $notification->warning() : $notification->success())->send();
                    AdminResourceTable::refreshPreservingState($livewire);
                } catch (DomainException $exception) {
                    Notification::make()->title('A devizás kalkuláció nem indítható')->body($exception->getMessage())->danger()->send();
                }
            });
    }

    private static function schema(bool $all, string $currency): array
    {
        $resolver = app(ForeignRetailPriceListResolver::class);
        $sources = $resolver->sources();
        $targets = $resolver->targets($all ? null : $currency);
        if ($sources->isEmpty() || $targets->isEmpty()) {
            return [Text::make($sources->isEmpty() ? 'Nincs aktív HUF kisker forrásárlista.' : 'Nincs aktív devizás kisker célárlista.')];
        }
        $sourceOptions = $sources->mapWithKeys(fn ($list): array => [$list->id => $list->code.' — '.$list->name_hu])->all();
        $sourceField = static fn (string $name, string $label): Select => Select::make($name)->label($label)
            ->required()->searchable()->options($sourceOptions)->hidden($sources->count() === 1)->dehydratedWhenHidden()
            ->default($sources->count() === 1 ? $sources->first()->id : null);
        if ($all) {
            $fields = [];
            foreach ($targets as $target) {
                $fields[] = Text::make(($sources->count() === 1 ? $sources->first()->code.' → ' : 'Célárlista: ')
                    .$target->code.' ('.$target->currency->code.')');
                $fields[] = $sourceField('sources.'.$target->id, $target->code.' — HUF forrásárlista');
            }

            return $fields;
        }

        return [
            Text::make('Forrás: '.($sources->count() === 1 ? $sources->first()->code : 'választandó')
                .' → cél: '.($targets->count() === 1 ? $targets->first()->code : 'választandó').' ('.$currency.')'),
            $sourceField('source_price_list_id', 'Forrás HUF kisker árlista'),
            Select::make('price_list_id')->label('Devizás célárlista')->required()->searchable()
                ->options($targets->mapWithKeys(fn ($list): array => [$list->id => $list->code.' — '.$list->name_hu])->all())
                ->hidden($targets->count() === 1)->dehydratedWhenHidden()
                ->default($targets->count() === 1 ? $targets->first()->id : null),
        ];
    }
}

<?php

namespace App\Filament\Support\Actions;

use App\Filament\Resources\PricingCalculationRows\Tables\HufRetailCalculationsTable;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\DistributorPriceReviewService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class DistributorReviewActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('distributorDetails')->label('Disztribútori részletek')->icon('heroicon-o-document-magnifying-glass')
                ->visible(fn (PricingCalculationRow $record, $livewire) => ($row = $livewire->distributorRow($record)) && Gate::allows('view', $row))
                ->authorize(fn (PricingCalculationRow $record, $livewire) => ($row = $livewire->distributorRow($record)) && Gate::allows('view', $row))
                ->modalHeading('Nettó disztribútori kalkuláció részletei')->modalWidth(Width::Screen)
                ->modalSubmitAction(false)->modalCancelActionLabel('Bezárás')
                ->modalContent(fn (PricingCalculationRow $record, $livewire) => view('filament.pricing.distributor-calculation-details', [
                    'record' => $livewire->distributorRow($record, fresh: true), 'source' => $record, 'vat' => $livewire->wholesaleVatPercent(),
                ])),
            Action::make('approveDistributor')->label('Disztribútori ár jóváhagyása')->icon('heroicon-o-check')->requiresConfirmation()
                ->schema([HufRetailCalculationsTable::approvalStatusSelect()])
                ->visible(fn (PricingCalculationRow $record, $livewire) => ($row = $livewire->distributorRow($record)) && Gate::allows('update', $row))
                ->disabled(fn ($livewire) => ! $livewire->pricingWorkspaceIsEditable())
                ->action(fn (PricingCalculationRow $record, array $data, $livewire) => self::change($record, $livewire,
                    fn ($row) => app(DistributorPriceReviewService::class)->approveCurrentPrice($row, $data['approval_status']))),
            Action::make('acceptCalculatedDistributor')->label('Kalkulált disztribútori ár elfogadása')->icon('heroicon-o-arrow-path')->requiresConfirmation()
                ->visible(fn (PricingCalculationRow $record, $livewire) => ($row = $livewire->distributorRow($record)) && Gate::allows('update', $row)
                    && ($row->requires_review || $row->manual_price !== null || $row->retained_price !== null))
                ->disabled(fn ($livewire) => ! $livewire->pricingWorkspaceIsEditable())
                ->action(fn (PricingCalculationRow $record, $livewire) => self::change($record, $livewire,
                    fn ($row) => app(DistributorPriceReviewService::class)->acceptCalculatedPrice($row))),
        ])->label('Disztribútor')->icon('heroicon-o-currency-euro')->button()
            ->visible(fn (PricingCalculationRow $record, $livewire) => ($row = $livewire->distributorRow($record))
                && (Gate::allows('view', $row) || Gate::allows('update', $row)));
    }

    public static function bulk(): BulkAction
    {
        return BulkAction::make('approveDistributorBulk')->label('Kijelölt disztribútori árak jóváhagyása')->icon('heroicon-o-check')
            ->requiresConfirmation()->modalHeading('Kijelölt disztribútori árak jóváhagyása')
            ->modalDescription('A kijelölt nagyker sorokhoz tartozó, kiválasztott disztribútori árlista árait hagyod jóvá. A nagyker árak és jóváhagyások nem változnak. A hiányzó vagy elavult sorokat kihagyjuk.')
            ->schema([HufRetailCalculationsTable::approvalStatusSelect()])
            ->visible(fn ($livewire) => self::canBulk($livewire))->authorize(fn ($livewire) => self::canBulk($livewire))
            ->action(function (Collection $records, array $data, $livewire): void {
                // The parent records are already scoped by the table; the service revalidates each child.
                $sources = $records->unique('id')->keyBy('id');
                $rows = PricingCalculationRow::query()->where('price_type', 'distributor')->where('pricing_project_id', $livewire->pricingProjectId)
                    ->where('price_list_id', $livewire->selectedDistributorPriceList->id)->where('currency_id', $livewire->selectedDistributorPriceList->currency_id)
                    ->whereIn('source_wholesale_row_id', $sources->keys())->get()
                    ->filter(fn ($row) => ($source = $sources->get($row->source_wholesale_row_id)) && $source->product_id == $row->product_id
                        && $source->color_key == $row->color_key && $source->currency_id == $row->currency_id)->values();
                $result = app(DistributorPriceReviewService::class)->approveCurrentPrices($rows, $data['approval_status']);
                $missing = $records->unique('id')->count() - $rows->count();
                $body = 'Sikeres: '.$result['approved'].', kihagyott: '.($result['skipped'] + $missing).', ebből még nincs kalkulálva: '.$missing.'.';
                foreach ($result['reasons'] as $reason => $count) {
                    $body .= ' '.$count.' sor: '.$reason;
                }
                $notice = Notification::make()->title('Disztribútori tömeges jóváhagyás kész')->body($body);
                (($result['skipped'] + $missing) ? $notice->warning() : $notice->success())->send();
                unset($livewire->averageDistributorNetNetMultiplier);
                $livewire->resetTable();
            })->deselectRecordsAfterCompletion();
    }

    private static function canBulk($livewire): bool
    {
        return $livewire::canAccess() && $livewire->retailCurrencyCode() === 'EUR' && $livewire->selectedDistributorPriceList
            && $livewire->pricingWorkspaceIsEditable() && Gate::allows('update', new PricingCalculationRow);
    }

    private static function change(PricingCalculationRow $record, $livewire, \Closure $change): void
    {
        abort_unless($livewire::canAccess() && $livewire->pricingWorkspaceIsEditable(), 403);
        $row = $livewire->distributorRow($record, fresh: true);
        abort_unless($row, 404);
        try {
            $change($row);
        } catch (DomainException $exception) {
            Notification::make()->title('A disztribútori kalkuláció nem aktuális')->body($exception->getMessage())->warning()->send();
        } finally {
            unset($livewire->averageDistributorNetNetMultiplier);
            $livewire->resetTable();
        }
    }
}

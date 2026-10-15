<?php

namespace App\Filament\Support\Concerns;

use App\Filament\Pages\PricingDistributorCalculations;
use App\Filament\Pages\PricingForeignRetailCalculations;
use App\Filament\Pages\PricingWholesaleCalculations;
use App\Filament\Resources\PricingCalculationRows\Tables\HufRetailCalculationsTable;
use App\Filament\Support\Actions\PairPreviousSeasonProductAction;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\DistributorPriceReviewService;
use App\Services\Pricing\RetailPriceReviewService;
use App\Services\Pricing\WholesalePriceReviewService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

trait HasPricingGridInteractions
{
    public function pricingEditableRow(PricingCalculationRow $record): ?PricingCalculationRow
    {
        abort_unless(static::canAccess(), 403);
        $expectedType = $this instanceof PricingWholesaleCalculations || $this instanceof PricingDistributorCalculations ? 'wholesale' : 'retail';
        if ($record->pricing_project_id != $this->pricingProjectId || $record->price_type !== $expectedType
            || $record->currency?->code !== $this->retailCurrencyCode()) {
            return null;
        }

        return $this instanceof PricingDistributorCalculations ? $this->distributorRow($record) : $record;
    }

    public function canEditPricingGridRow(PricingCalculationRow $record): bool
    {
        return $this->pricingWorkspaceIsEditable() && ($row = $this->pricingEditableRow($record))
            && ($row->pricingProject?->isOpenForPricing() ?? false) && Gate::allows('update', $row);
    }

    public function pricingRowOperations(PricingCalculationRow $source): array
    {
        $row = $this->pricingEditableRow($source);
        if (! $row) {
            return [];
        }
        $items = [];
        if ($this->canEditPricingGridRow($source)) {
            $items[] = ['action' => 'pricingApprove', 'label' => 'Jóváhagyás'];
            $items[] = ['action' => 'pricingAccept', 'label' => 'Kalkulált ár visszaállítása'];
            if ($this->canPairPricingProduct() && Gate::allows('update', $row->product)) {
                $items[] = ['action' => 'pairPreviousSeasonProduct', 'label' => 'Előző szezon termékének összekapcsolása'];
            }
        }
        if (Gate::allows('view', $row)) {
            $items[] = ['action' => 'pricingDetails', 'label' => 'Részletek'];
        }

        return $items;
    }

    protected function canPairPricingProduct(): bool
    {
        return ! ($this instanceof PricingForeignRetailCalculations || $this instanceof PricingWholesaleCalculations || $this instanceof PricingDistributorCalculations);
    }

    protected function pricingReviewService(): RetailPriceReviewService
    {
        return app(match (true) {
            $this instanceof PricingDistributorCalculations => DistributorPriceReviewService::class,
            $this instanceof PricingWholesaleCalculations => WholesalePriceReviewService::class,
            default => RetailPriceReviewService::class,
        });
    }

    protected function pricingGridRecord(mixed $id, bool $write = false): PricingCalculationRow
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0, 422);
        abort_if($write && ! $this->pricingWorkspaceIsEditable(), 403);
        // The actual page query includes project/archive, currency and distributor-source scopes.
        $source = $this->getTable()->getQuery()->find((int) $id);
        abort_unless($source, 404);
        $row = $this instanceof PricingDistributorCalculations ? $this->distributorRow($source, fresh: true) : $source;
        abort_unless($row, 404);
        Gate::authorize($write ? 'update' : 'view', $row);

        return $source;
    }

    public function setPricingManualPrice(mixed $recordId, mixed $value): void
    {
        abort_unless(is_scalar($value) || $value === null, 422);
        $value = $value === null ? null : trim(str_replace(',', '.', (string) $value));
        $value = $value === '' ? null : $value;
        abort_unless($value === null || (is_numeric($value) && is_finite((float) $value)
            && (float) $value > 0 && round((float) $value, 4) > 0 && (float) $value <= 99999999999.9999), 422);
        $source = $this->pricingGridRecord($recordId, write: true);
        $this->performPricingGridChange(fn () => $this->pricingReviewService()->setManualPrice($this->pricingEditableRow($source), $value));
    }

    public function openPricingDetails(mixed $recordId): void
    {
        $this->pricingGridRecord($recordId);
        $this->mountAction('pricingDetails', ['record_id' => (int) $recordId]);
    }

    public function pricingDetailsAction(): Action
    {
        return $this->pricingGridAction('pricingDetails')->label('Részletek')->modalHeading('Kalkuláció részletei')
            ->modalWidth(Width::Screen)->modalSubmitAction(false)->modalCancelActionLabel('Bezárás')
            ->modalContent(fn (PricingCalculationRow $record) => $this->pricingGridDetails($record));
    }

    public function pricingApproveAction(): Action
    {
        return $this->pricingGridAction('pricingApprove', write: true)->label('Jóváhagyás')->requiresConfirmation()
            ->schema([HufRetailCalculationsTable::approvalStatusSelect()])
            ->action(fn (PricingCalculationRow $record, array $data) => $this->performPricingGridChange(
                fn () => $this->pricingReviewService()->approveCurrentPrice($this->pricingEditableRow($record), $data['approval_status'])));
    }

    public function pricingAcceptAction(): Action
    {
        return $this->pricingGridAction('pricingAccept', write: true)->label('Kalkulált ár visszaállítása')->requiresConfirmation()
            ->modalDescription('A manuális/megtartott árat elvetjük, a legutóbbi kalkulált árat állítjuk vissza és hagyjuk jóvá. Elavult kalkulációnál előbb újraszámítás szükséges.')
            ->action(fn (PricingCalculationRow $record) => $this->performPricingGridChange(
                fn () => $this->pricingReviewService()->acceptCalculatedPrice($this->pricingEditableRow($record))));
    }

    public function pairPreviousSeasonProductAction(): Action
    {
        // The factory's own product-update policy is retained as well as the workspace restriction.
        return PairPreviousSeasonProductAction::make()
            ->record(fn (array $arguments) => $this->pricingGridRecord($arguments['record_id'] ?? null, write: true))
            ->authorize(fn (PricingCalculationRow $record) => $this->canPairPricingProduct()
                && $this->pricingWorkspaceIsEditable() && Gate::allows('update', $record) && Gate::allows('update', $record->product));
    }

    protected function pricingGridAction(string $name, bool $write = false): Action
    {
        return Action::make($name)
            ->record(fn (array $arguments) => $this->pricingGridRecord($arguments['record_id'] ?? null, $write))
            ->authorize(fn (PricingCalculationRow $record) => static::canAccess()
                && (! $write || $this->pricingWorkspaceIsEditable())
                && Gate::allows($write ? 'update' : 'view', $this->pricingEditableRow($record)));
    }

    protected function performPricingGridChange(\Closure $change): void
    {
        try {
            $change();
        } catch (DomainException $exception) {
            Notification::make()->title(match (true) {
                $this instanceof PricingDistributorCalculations => 'A disztribútori kalkuláció nem aktuális',
                $this instanceof PricingWholesaleCalculations => 'A nagyker kalkuláció nem aktuális',
                default => 'A kisker kalkuláció nem aktuális',
            })->body($exception->getMessage())->warning()->send();
        } finally {
            $this->refreshPricingGrid();
        }
    }

    protected function refreshPricingGrid(): void
    {
        unset($this->averageRetailMultiplier, $this->averageCostBasedMultiplier, $this->averageNetNetMultiplier, $this->averageDistributorNetNetMultiplier);
        $this->resetTable();
    }

    protected function pricingGridDetails(PricingCalculationRow $source): View
    {
        $row = $this->pricingEditableRow($source);
        abort_unless($row, 404);
        Gate::authorize('view', $row);

        return view(match (true) {
            $this instanceof PricingDistributorCalculations => 'filament.pricing.distributor-calculation-details',
            $this instanceof PricingWholesaleCalculations => 'filament.pricing.wholesale-calculation-details',
            default => 'filament.pricing.retail-calculation-details',
        }, ['record' => $row, 'source' => $source, 'vat' => method_exists($this, 'wholesaleVatPercent') ? $this->wholesaleVatPercent() : null]);
    }

    public function pricingAcceptBulkAction(): BulkAction
    {
        return BulkAction::make('pricingAcceptBulk')->label('Kalkulált ár visszaállítása')->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()->modalDescription('A kijelölt sorok manuális/megtartott árát elvetjük, a kalkulált árakat állítjuk vissza és hagyjuk jóvá. A nem módosítható, hiányzó vagy elavult sorokat kihagyjuk.')
            ->visible(fn () => static::canAccess() && $this->pricingWorkspaceIsEditable() && Gate::allows('update', new PricingCalculationRow))
            ->authorize(fn () => static::canAccess() && $this->pricingWorkspaceIsEditable() && Gate::allows('update', new PricingCalculationRow))
            ->action(function (Collection $records): void {
                $rows = $records->map(fn ($source) => $this->pricingEditableRow($source))->filter();
                $result = $this->pricingReviewService()->acceptCalculatedPrices($rows);
                $missing = $records->count() - $rows->count();
                $body = 'Visszaállítva és jóváhagyva: '.$result['accepted'].' sor. Kihagyott: '.($result['skipped'] + $missing).' sor.';
                foreach ($result['reasons'] as $reason => $count) {
                    $body .= ' '.$count.' sor: '.$reason;
                }
                if ($missing) {
                    $body .= ' '.$missing.' sorhoz nincs kalkulált disztribútori ár.';
                }
                $notice = Notification::make()->title('Kalkulált árak visszaállítása kész')->body($body);
                (($result['skipped'] + $missing) ? $notice->warning() : $notice->success())->send();
                $this->refreshPricingGrid();
            })->deselectRecordsAfterCompletion();
    }

    public function pricingDetailsBulkAction(): BulkAction
    {
        return BulkAction::make('pricingDetailsBulk')->label('Kijelölt sorok részletei')->icon('heroicon-o-document-magnifying-glass')
            ->authorize(fn () => static::canAccess() && Gate::allows('view', new PricingCalculationRow))
            ->visible(fn () => Gate::allows('view', new PricingCalculationRow))
            ->modalHeading('Kijelölt kalkulációk részletei')->modalWidth(Width::Screen)
            ->modalSubmitAction(false)->modalCancelActionLabel('Bezárás')
            ->modalContent(function (Collection $records) {
                $sources = $records->map(fn ($source) => $this->pricingGridRecord($source->id));

                return view('filament.pricing.bulk-details', ['details' => $sources->map(fn ($source) => [
                    'source' => $source, 'view' => $this->pricingGridDetails($source),
                ])]);
            });
    }
}

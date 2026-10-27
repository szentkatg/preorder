<?php

namespace App\Filament\Support\Concerns;

use App\Filament\Pages\PricingWholesaleCalculations;
use App\Filament\Resources\PricingCalculationRows\Tables\HufRetailCalculationsTable;
use App\Filament\Support\OutletColumns;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\OutletCalculationService;
use App\Services\Pricing\OutletPriceReviewService;
use App\Services\Pricing\RetailOutletReviewService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

trait HasOutletPricing
{
    public function supportsOutletPricing(): bool
    {
        return ! ($this instanceof PricingWholesaleCalculations);
    }

    #[Computed]
    public function outletPriceLists(): Collection
    {
        return $this->supportsOutletPricing() && $this->pricingSelectedProject
            ? app(OutletCalculationService::class)->lists($this->pricingSelectedProject, $this->retailCurrencyCode()) : collect();
    }

    protected function outletRow(PricingCalculationRow $source, int $listId): ?PricingCalculationRow
    {
        return OutletColumns::row($source, $listId);
    }

    public function setOutletManualPrice(mixed $recordId, mixed $listId, mixed $value): void
    {
        abort_unless(is_scalar($listId) && ctype_digit((string) $listId) && $this->outletPriceLists->contains('id', (int) $listId), 422);
        abort_unless(is_scalar($value) || $value === null, 422);
        $value = $value === null ? null : trim(str_replace(',', '.', (string) $value));
        $value = $value === '' ? null : $value;
        abort_unless($value === null || (is_numeric($value) && is_finite((float) $value) && round((float) $value, 4) > 0 && (float) $value <= 99999999999.9999), 422);
        $source = $this->pricingGridRecord($recordId, write: true);
        $source->unsetRelation('outletRows');
        $row = $this->outletRow($source, (int) $listId);
        abort_unless($row, 404);
        $this->performPricingGridChange(fn () => app(OutletPriceReviewService::class)->setManualPrice($row, $value), [$source->id]);
    }

    public function calculateOutletAction(): Action
    {
        return Action::make('calculateOutlet')->label('Outlet árak újraszámítása')->requiresConfirmation()
            ->visible(fn () => $this->canChangeOutletPrices() && Gate::allows('create', PricingCalculationRow::class) && Gate::allows('update', $this->pricingSelectedProject))
            ->authorize(fn () => $this->canChangeOutletPrices() && Gate::allows('create', PricingCalculationRow::class) && Gate::allows('update', $this->pricingSelectedProject))
            ->modalDescription('Az aktuális pénznem összes konfigurált outlet árlistáját újraszámítjuk. A jóváhagyott és manuális árakat megtartjuk; a minimum alatti kedvezmény nem hagyható jóvá.')
            ->action(fn () => $this->performPricingGridChange(function (): void {
                $result = app(OutletCalculationService::class)->recalculate($this->pricingSelectedProject, $this->retailCurrencyCode());
                $notice = Notification::make()->title('Outlet kalkuláció kész')->body('Új: '.$result['created'].', frissített: '.$result['updated'].', kihagyott: '.$result['skipped'].', minimum alatti kedvezmény: '.$result['below_minimum'].'.');
                ($result['skipped'] || $result['below_minimum'] ? $notice->warning() : $notice->success())->send();
            }));
    }

    public function canChangeOutletPrices(): bool
    {
        return static::canAccess() && $this->pricingWorkspaceIsEditable() && $this->outletPriceLists->isNotEmpty()
            && Gate::allows('update', new PricingCalculationRow);
    }

    public function pricingOutletApproveAction(): Action
    {
        return $this->outletApprovalAction('outlet');
    }

    public function pricingAllApproveAction(): Action
    {
        return $this->outletApprovalAction('all');
    }

    private function outletApprovalAction(string $mode): Action
    {
        return $this->pricingGridAction($mode === 'all' ? 'pricingAllApprove' : 'pricingOutletApprove', write: true)
            ->visible(fn () => $this->canChangeOutletPrices())
            ->authorize(fn () => $this->canChangeOutletPrices())
            ->label($mode === 'all' ? 'Minden ár jóváhagyása' : 'Outlet ár jóváhagyása')->requiresConfirmation()
            ->schema([HufRetailCalculationsTable::approvalStatusSelect()])
            ->action(fn (PricingCalculationRow $record, array $data) => $this->performPricingGridChange(
                fn () => app(RetailOutletReviewService::class)->approve($record, $this->outletPriceLists->modelKeys(), $mode, $data['approval_status'])));
    }

    public function outletApprovalBulkAction(string $mode): BulkAction
    {
        return BulkAction::make('approve'.ucfirst($mode).'PricesBulk')
            ->label($mode === 'all' ? 'Kijelölt: minden ár jóváhagyása' : 'Kijelölt: outlet árak jóváhagyása')->requiresConfirmation()
            ->visible(fn () => $this->canChangeOutletPrices())->authorize(fn () => $this->canChangeOutletPrices())
            ->schema([HufRetailCalculationsTable::approvalStatusSelect()])
            ->action(function (Collection $records, array $data) use ($mode): void {
                $sources = $records->map(fn ($row) => $this->pricingGridRecord($row->id, write: true));
                $result = app(RetailOutletReviewService::class)->approveRows($sources, $this->outletPriceLists->modelKeys(), $mode, $data['approval_status']);
                $notice = Notification::make()->title('Jóváhagyás befejezve')->body('Jóváhagyott árak: '.$result['approved'].'. Kihagyott terméksorok: '.$result['skipped'].'. '.implode(' ', array_keys($result['reasons'])));
                ($result['skipped'] ? $notice->warning() : $notice->success())->send();
                $this->refreshPricingGrid();
            })->deselectRecordsAfterCompletion();
    }

    protected function outletDecimalColumns(): array
    {
        $columns = [];
        foreach ($this->outletPriceLists as $list) {
            foreach (['discount_percent' => 'Minimum kedvezmény %', 'actual_discount' => 'Tényleges kedvezmény %', 'calculated_price' => 'Kalkulált ár', 'manual_price' => 'Manuális ár', 'final_price' => 'Végleges ár'] as $field => $label) {
                $columns['outlet_'.$list->id.'_'.$field] = $list->code.' – '.$label;
            }
        }

        return $columns;
    }
}

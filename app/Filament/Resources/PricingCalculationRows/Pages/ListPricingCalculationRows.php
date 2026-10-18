<?php

namespace App\Filament\Resources\PricingCalculationRows\Pages;

use App\Filament\Resources\PricingCalculationRows\PricingCalculationRowResource;
use App\Filament\Resources\PricingCalculationRows\Tables\PricingCalculationRowsTable;
use App\Filament\Support\Concerns\HasProductPricingEditor;
use App\Filament\Support\ProductPricingNavigation;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Services\Pricing\CostCalculationAuthorization;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class ListPricingCalculationRows extends ListRecords
{
    use HasProductPricingEditor;

    protected static string $resource = PricingCalculationRowResource::class;

    protected string $view = 'filament.resources.pricing-calculation-rows.list';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with([
            'pricingProject', 'product.itemMainGroup', 'supplier', 'currency', 'purchaseCurrency', 'color', 'priceList',
        ]));
    }

    public function pricingRowOperations(PricingCalculationRow $record): array
    {
        $items = [];
        if (CostCalculationAuthorization::canAdjustParameter($record)) {
            $items[] = ['action' => 'updateShippingCostPercent', 'label' => 'Szállítás %'];
            $items[] = ['action' => 'updateCustomsPercent', 'label' => 'Vám %'];
        }
        if (Gate::allows('view', $record)) {
            $items[] = ['action' => 'costDetails', 'label' => 'Részletek'];
        }
        if ($record->pricingProject?->isOpenForPricing() && Gate::allows('update', $record)) {
            $items[] = ['url' => PricingCalculationRowResource::getUrl('edit', ['record' => $record]), 'label' => 'Szerkesztés'];
        }

        return [...$items, ...ProductPricingNavigation::items($record)];
    }

    public function openCostDetails(mixed $recordId): void
    {
        $this->productPricingSourceRecord($recordId);
        $this->mountAction('costDetails', ['record_id' => (int) $recordId]);
    }

    public function costDetailsAction(): Action
    {
        return Action::make('costDetails')->label('Részletek')
            ->record(fn (array $arguments) => $this->productPricingSourceRecord($arguments['record_id'] ?? null))
            ->authorize(fn (PricingCalculationRow $record) => Gate::allows('view', $record))
            ->modalHeading('Kalkuláció részletei')->modalWidth(Width::Screen)
            ->modalSubmitAction(false)->modalCancelActionLabel('Bezárás')
            ->modalContent(fn (PricingCalculationRow $record) => view(match ($record->price_type) {
                'retail' => 'filament.pricing.retail-with-outlet-details',
                'wholesale' => 'filament.pricing.wholesale-calculation-details',
                'distributor' => 'filament.pricing.distributor-calculation-details',
                'outlet' => 'filament.pricing.outlet-calculation-details',
                default => 'filament.pricing.calculation-details',
            }, ['record' => $record, 'source' => $record->sourceWholesaleRow,
                'vat' => $record->currency?->code === 'HUF' ? 27 : null]));
    }

    public function startInlineCostPercentParameterAdjustment(mixed $recordId, string $parameterType, mixed $value): void
    {
        $record = $this->productPricingSourceRecord($recordId, 'update');
        CostCalculationAuthorization::authorizeParameterAdjustment($record);
        abort_unless(is_scalar($value), 422);
        $value = str_replace(',', '.', trim((string) $value));
        abort_unless(is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 && (float) $value <= 9999.9999, 422);
        $action = match ($parameterType) {
            PricingParameter::TYPE_SHIPPING_COST_PERCENT => 'updateShippingCostPercent',
            PricingParameter::TYPE_CUSTOMS_PERCENT => 'updateCustomsPercent',
            default => null,
        };
        abort_unless($action, 422);
        $this->mountAction($action, ['record_id' => $record->id, 'value' => $value]);
    }

    public function updateShippingCostPercentAction(): Action
    {
        return PricingCalculationRowsTable::parameterAction('updateShippingCostPercent', 'Szállítás %',
            PricingParameter::TYPE_SHIPPING_COST_PERCENT, 'shipping_cost_percent')
            ->record(fn (array $arguments) => $this->productPricingSourceRecord($arguments['record_id'] ?? null, 'update'));
    }

    public function updateCustomsPercentAction(): Action
    {
        return PricingCalculationRowsTable::parameterAction('updateCustomsPercent', 'Vám %',
            PricingParameter::TYPE_CUSTOMS_PERCENT, 'customs_percent')
            ->record(fn (array $arguments) => $this->productPricingSourceRecord($arguments['record_id'] ?? null, 'update'));
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

<?php

namespace App\Services\Pricing;

use App\Models\PricingCalculationRow;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class RetailCalculationDependencyService
{
    public const COST_INPUT_FIELDS = [
        'final_price', 'purchase_price', 'exchange_rate', 'shipping_cost_percent', 'customs_percent',
        'product_purchase_price_id', 'supplier_id', 'purchase_currency_id',
        'pricing_project_id', 'product_id', 'color_key', 'currency_id', 'price_type', 'price_list_key',
    ];

    public const SOURCE_COST_FIELDS = [
        'product_purchase_price_id', 'supplier_id', 'purchase_currency_id',
        'purchase_price', 'exchange_rate', 'shipping_cost_percent', 'customs_percent',
    ];

    public function __construct(
        private readonly HufRetailCalculator $calculator,
        private readonly ForeignRetailCalculator $foreignCalculator,
    ) {}

    public function invalidateForRetailChange(PricingCalculationRow $source): void
    {
        $this->invalidateRetailDependents([$source->id]);
    }

    public function invalidateRetailDependents(array $sourceIds): void
    {
        // HUF retail -> foreign retail -> wholesale, including query updates which do not emit model events.
        $foreign = PricingCalculationRow::query()->whereIn('source_retail_row_id', $sourceIds)
            ->where('price_type', PricingCalculationRow::TYPE_RETAIL)->pluck('id')->all();
        PricingCalculationRow::query()->whereIn('source_retail_row_id', [...$sourceIds, ...$foreign])
            ->whereIn('price_type', [PricingCalculationRow::TYPE_RETAIL, PricingCalculationRow::TYPE_WHOLESALE, PricingCalculationRow::TYPE_DISTRIBUTOR, PricingCalculationRow::TYPE_OUTLET])
            ->update(['requires_review' => true]);
    }

    public function invalidateForCostChange(PricingCalculationRow $cost): void
    {
        // Query updates intentionally avoid model events and never change prices or approvals.
        $contexts = [
            $cost->only(['pricing_project_id', 'product_id', 'color_key', 'currency_id']),
            collect(['pricing_project_id', 'product_id', 'color_key', 'currency_id'])
                ->mapWithKeys(fn (string $field): array => [$field => $cost->getOriginal($field)])->all(),
        ];

        if ($contexts[0] == $contexts[1]) {
            array_pop($contexts);
        }
        foreach ($contexts as $context) {
            $sources = PricingCalculationRow::query()->where($context)
                ->where('price_type', PricingCalculationRow::TYPE_RETAIL)
                ->whereHas('currency', fn ($query) => $query->where('code', 'HUF'))->pluck('id');
            $this->invalidateRetailDependents($sources->all());
            PricingCalculationRow::query()->where($context)
                ->where('price_type', PricingCalculationRow::TYPE_RETAIL)
                ->whereHas('currency', fn ($query) => $query->where('code', 'HUF'))
                ->where('requires_review', false)
                ->update(['requires_review' => true]);
        }
    }

    public function calculationInputsChanged(PricingCalculationRow $row, PricingCalculationRow $cost, array $calculation): bool
    {
        $snapshot = $row->calculation_snapshot ?? [];
        if (isset($snapshot['source_cost_row_id']) && (int) $snapshot['source_cost_row_id'] !== (int) $cost->id) {
            return true;
        }

        foreach (self::SOURCE_COST_FIELDS as $field) {
            if (! $this->sameNumber($row->{$field}, $cost->{$field})) {
                return true;
            }
        }

        foreach (['base_price' => 4, 'multiplier' => 6, 'unrounded_price' => 4, 'calculated_price' => 4] as $field => $precision) {
            if (! $this->sameNumber($row->{$field}, $calculation[$field] ?? null, $precision)) {
                return true;
            }
        }

        return $this->roundingChanged($snapshot['rounding'] ?? [], $calculation['snapshot']['rounding'] ?? []);
    }

    public function approvalInputsChanged(PricingCalculationRow $row, array $calculation): bool
    {
        $approval = $row->calculation_snapshot['approval'] ?? null;
        if (! is_array($approval)) {
            return false;
        }

        foreach (['base_price' => 4, 'multiplier' => 6, 'unrounded_price' => 4] as $field => $precision) {
            if (! $this->sameNumber($approval['inputs'][$field] ?? null, $calculation[$field] ?? null, $precision)) {
                return true;
            }
        }
        if (! $this->sameNumber($approval['calculated_price'] ?? null, $calculation['calculated_price'] ?? null, 4)) {
            return true;
        }
        if (isset($approval['source_cost_row_id'])
            && (int) $approval['source_cost_row_id'] !== (int) $calculation['snapshot']['source_cost_row_id']) {
            return true;
        }
        // Older approvals do not contain these copied inputs; their base price/rule still provide a baseline.
        foreach ($approval['source_cost_inputs'] ?? [] as $field => $value) {
            if (! $this->sameNumber($value, $calculation['snapshot']['source_cost_inputs'][$field] ?? null)) {
                return true;
            }
        }

        return $this->roundingChanged($approval['rounding'] ?? [], $calculation['snapshot']['rounding'] ?? []);
    }

    private function roundingChanged(array $previousRule, array $currentRule): bool
    {
        foreach (['round_to', 'decimal_places', 'adjustment'] as $field) {
            if (! $this->sameNumber($previousRule[$field] ?? null, $currentRule[$field] ?? null)) {
                return true;
            }
        }

        return ($previousRule['mode'] ?? null) !== ($currentRule['mode'] ?? null);
    }

    public function assertCalculationCurrent(PricingCalculationRow $row): void
    {
        if ($row->currency?->code !== 'HUF') {
            $source = $row->sourceRetailRow;
            if (! $source || ! $row->priceList || $source->product_id != $row->product_id
                || $source->color_key != $row->color_key || $row->priceList->currency_id != $row->currency_id) {
                throw new DomainException('A devizás kisker ár HUF forráskalkulációja vagy árlistája hiányzik. Újraszámítás szükséges.');
            }
            $calculation = $this->foreignCalculator->calculate($row->pricingProject, $source, $row->priceList);
            if ($this->foreignInputsChanged($row, $calculation)) {
                throw new DomainException('A devizás kisker kalkuláció bemenetei megváltoztak. Előbb futtasd a devizás kisker újraszámítást; a korábbi ár megmarad.');
            }

            return;
        }
        $cost = PricingCalculationRow::query()->where('pricing_project_id', $row->pricing_project_id)
            ->where('product_id', $row->product_id)->where('color_key', $row->color_key)
            ->where('price_type', PricingCalculationRow::TYPE_COST)->where('price_list_key', 0)
            ->where('currency_id', $row->currency_id)
            ->with(['product', 'supplier', 'currency'])->first();

        if (! $cost || ! $row->priceList) {
            throw new DomainException('A kisker ár forráskalkulációja vagy árlistája hiányzik. Előbb számítsd újra a bekerülési értéket és a HUF kisker árakat.');
        }

        $calculation = $this->calculator->calculate($row->pricingProject, $cost, $row->priceList);
        if ($this->calculationInputsChanged($row, $cost, $calculation)) {
            throw new DomainException('A kisker kalkuláció bemenetei megváltoztak. Előbb futtasd a HUF kisker árak újraszámítását; a korábbi végleges ár megmarad.');
        }
    }

    public function foreignInputsChanged(PricingCalculationRow $row, array $calculation): bool
    {
        foreach (['source_retail_row_id' => 0, 'source_retail_price' => 4, 'retail_exchange_rate' => 6,
            'base_price' => 4, 'multiplier' => 6, 'unrounded_price' => 4, 'calculated_price' => 4] as $field => $precision) {
            if (! $this->sameNumber($row->{$field}, $calculation[$field] ?? null, $precision)) {
                return true;
            }
        }

        return $this->roundingChanged($row->calculation_snapshot['rounding'] ?? [], $calculation['snapshot']['rounding'] ?? []);
    }

    public function foreignApprovalInputsChanged(PricingCalculationRow $row, array $calculation): bool
    {
        $approval = $row->calculation_snapshot['approval'] ?? null;
        if (! is_array($approval)) {
            return false;
        }
        if (($approval['source_retail_row_id'] ?? null) != $calculation['source_retail_row_id']) {
            return true;
        }
        foreach ($calculation['snapshot']['inputs'] as $field => $value) {
            if (! $this->sameNumber($approval['inputs'][$field] ?? null, $value, in_array($field, ['multiplier', 'retail_exchange_rate'], true) ? 6 : 4)) {
                return true;
            }
        }

        return ! $this->sameNumber($approval['calculated_price'] ?? null, $calculation['calculated_price'], 4)
            || $this->roundingChanged($approval['rounding'] ?? [], $calculation['snapshot']['rounding'] ?? []);
    }

    private function sameNumber(mixed $left, mixed $right, int $precision = 6): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        if (! is_numeric($left) || ! is_numeric($right)) {
            return false;
        }

        return BigDecimal::of((string) $left)->toScale($precision, RoundingMode::HALF_UP)->compareTo(
            BigDecimal::of((string) $right)->toScale($precision, RoundingMode::HALF_UP),
        ) === 0;
    }
}

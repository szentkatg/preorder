<?php

namespace App\Filament\Support\Concerns;

use App\Filament\Support\PricingGrid;
use App\Models\PricingCalculationRow;
use Filament\Support\Livewire\Partials\PartialsComponentHook;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

trait HasIncrementalPricingGrid
{
    /** IDs from the last rendered page, never hydrated rows in the Livewire snapshot. */
    #[Locked]
    public array $pricingVisibleRowIds = [];

    public function capturePricingVisibleRows(): void
    {
        $records = $this->getTableRecords();
        $records = method_exists($records, 'getCollection') ? $records->getCollection() : $records;
        $this->pricingVisibleRowIds = $records->modelKeys();
    }

    public function pricingCellPartialName(int $id, string $column): string
    {
        return 'pricing-cell-'.$id.'-'.sha1($column);
    }

    /** Update existing cells without rerunning the sorted/paginated table query. */
    public function refreshPricingRows(?array $rowIds = null, ?array $productIds = null): void
    {
        abort_unless(static::canAccess(), 403);
        Gate::authorize('viewAny', PricingCalculationRow::class);
        unset($this->averageRetailMultiplier, $this->averageCostBasedMultiplier, $this->averageNetNetMultiplier,
            $this->averageDistributorNetNetMultiplier);
        $this->flushCachedTableRecords();
        $table = $this->getTable();
        $query = $table->getQuery()->whereKey($this->pricingVisibleRowIds)
            ->when($rowIds !== null, fn ($query) => $query->whereKey($rowIds))
            ->when($productIds !== null, fn ($query) => $query->whereIn('product_id', $productIds));
        $columns = $table->getVisibleColumns();
        foreach ($columns as $column) {
            $column->applyRelationshipAggregates($query);
            $column->applyEagerLoading($query);
        }
        $records = $query->get();
        $rows = [];
        foreach ($columns === [] ? [] : $records as $record) {
            $rows[] = ['anchor' => $this->pricingCellPartialName($record->id, array_key_first($columns)),
                'attention' => method_exists($this, 'pricingEditableRow')
                    ? PricingGrid::needsAttention($record, $this) : $record->needsPricingAttention()];
        }
        $headers = [];
        foreach ($columns as $name => $column) {
            $attributes = $column->getExtraHeaderAttributes();
            if (array_key_exists('data-pricing-average-multiplier', $attributes)) {
                $headers[$name] = array_intersect_key($attributes, array_flip(['data-pricing-average-multiplier', 'aria-description']));
            }
        }
        $this->dispatch('pricing-grid-refreshed', componentId: $this->getId(), rows: $rows, headers: $headers);

        app(PartialsComponentHook::class)->renderPartial($this, function () use ($records, $columns): array {
            $partials = [];
            foreach ($records as $record) {
                $index = array_search($record->id, $this->pricingVisibleRowIds);
                $loop = (object) ['index' => $index, 'iteration' => $index + 1,
                    'first' => $index === 0, 'last' => $index === count($this->pricingVisibleRowIds) - 1,
                    'count' => count($this->pricingVisibleRowIds), 'remaining' => count($this->pricingVisibleRowIds) - $index - 1,
                    'even' => ($index + 1) % 2 === 0, 'odd' => ($index + 1) % 2 === 1, 'depth' => 1, 'parent' => null];
                foreach ($columns as $column) {
                    $cell = clone $column;
                    $cell->record($record)->recordKey((string) $record->id)->rowLoop($loop);
                    $partials[$this->pricingCellPartialName($record->id, $cell->getName())] = view('filament.pricing.incremental-cell', [
                        'column' => $cell, 'record' => $record,
                    ]);
                }
            }

            return $partials;
        });
    }
}

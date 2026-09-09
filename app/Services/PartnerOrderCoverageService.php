<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\OrderSheetType;
use App\Models\PartnerAddress;
use Illuminate\Support\Collection;

class PartnerOrderCoverageService
{
    public function build(?int $seasonId = null): array
    {
        $flatColumns = $this->buildFlatColumns();

        $partnerUser = auth('partner')->user();

        $addresses = $partnerUser
            ->accessibleAddressesQuery()
            ->with([
                'partner',
                'brands',
                'orderSheetTypes',
            ])
            ->where('active', true)
            ->whereHas('partner', fn ($query) => $query->where('active', true))
            ->orderBy('partner_id')
            ->orderBy('name')
            ->get();

        $orders = $partnerUser
            ->accessibleOrdersQuery()
            ->withSum('items as quantity', 'quantity')
            ->when($seasonId, fn ($query) => $query->where('season_id', $seasonId))
            ->get()
            ->keyBy(fn ($order) => $this->orderKey(
                (int) $order->partner_address_id,
                (int) $order->brand_id,
                (int) $order->order_sheet_type_id
            ));

        $rows = $addresses
            ->map(fn (PartnerAddress $address) => $this->buildRow($address, $flatColumns, $orders))
            ->values();

        $totals = $this->buildTotals($flatColumns, $rows);

        return [
            'brands' => $this->buildBrandColumns($flatColumns),
            'flat_columns' => $flatColumns->values()->all(),
            'rows' => $rows->all(),
            'totals' => $totals,
        ];
    }

    protected function buildFlatColumns(): Collection
    {
        $brands = Brand::query()
            ->orderBy('name')
            ->get();

        $types = OrderSheetType::query()
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return $brands
            ->flatMap(function ($brand) use ($types) {
                return $types->map(function ($type) use ($brand) {
                    return [
                        'brand_id' => (int) $brand->id,
                        'brand' => $brand->name,
                        'order_sheet_type_id' => (int) $type->id,
                        'order_sheet' => $type->translate('name'),
                    ];
                });
            })
            ->values()
            ->map(function (array $column, int $index) {
                $column['index'] = $index;

                return $column;
            });
    }

    protected function buildBrandColumns(Collection $flatColumns): array
    {
        return $flatColumns
            ->groupBy('brand_id')
            ->map(function (Collection $columns, int|string $brandId) {
                return [
                    'id' => (int) $brandId,
                    'name' => $columns->first()['brand'],
                    'columns' => $columns
                        ->map(fn (array $column) => [
                            'index' => (int) $column['index'],
                            'brand_id' => (int) $column['brand_id'],
                            'order_sheet_type_id' => (int) $column['order_sheet_type_id'],
                            'order_sheet' => $column['order_sheet'],
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    protected function buildRow(
        PartnerAddress $address,
        Collection $flatColumns,
        Collection $orders
    ): array {
        $availableBrandIds = $address->brands
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $availableTypeIds = $address->orderSheetTypes
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $cells = $flatColumns
            ->map(function (array $column) use (
                $address,
                $orders,
                $availableBrandIds,
                $availableTypeIds
            ) {
                $brandId = (int) $column['brand_id'];
                $typeId = (int) $column['order_sheet_type_id'];

                $enabled = in_array($brandId, $availableBrandIds, true)
                    && in_array($typeId, $availableTypeIds, true);

                $order = $enabled
                    ? $orders->get($this->orderKey((int) $address->id, $brandId, $typeId))
                    : null;

                $quantity = $enabled ? (int) ($order?->quantity ?? 0) : null;

                return [
                    'index' => (int) $column['index'],
                    'status' => $this->cellStatus($enabled, (bool) $order, $quantity),
                    'enabled' => $enabled,
                    'order_exists' => (bool) $order,
                    'quantity' => $quantity,
                    'order_id' => $order?->id,
                    'partner_address_id' => (int) $address->id,
                    'brand_id' => $brandId,
                    'order_sheet_type_id' => $typeId,
                ];
            })
            ->values();

        $brandTotals = $cells
            ->filter(fn (array $cell): bool => (bool) ($cell['enabled'] ?? false))
            ->groupBy('brand_id')
            ->map(fn (Collection $brandCells): int =>
                (int) $brandCells->sum(fn (array $cell): int => (int) ($cell['quantity'] ?? 0))
            )
            ->all();

        return [
            'partner_id' => (int) $address->partner_id,
            'partner_code' => $address->partner?->erp_partner_code,
            'partner_name' => $address->partner?->name,
            'address_id' => (int) $address->id,
            'address_name' => $address->name,
            'address' => collect([
                $address->zip,
                $address->city,
                $address->street,
            ])->filter()->implode(' '),
            'cells' => $cells->all(),
            'brand_totals' => $brandTotals,
            'grand_total' => array_sum($brandTotals),
        ];
    }

    protected function buildTotals(Collection $flatColumns, Collection $rows): array
    {
        $columnTotals = [];
        $columnFilledCounts = [];

        foreach ($flatColumns as $column) {
            $index = (int) $column['index'];
            $quantityTotal = 0;
            $filledCount = 0;

            foreach ($rows as $row) {
                $cell = $row['cells'][$index] ?? null;

                if (! $cell || ! ($cell['enabled'] ?? false)) {
                    continue;
                }

                $quantity = (int) ($cell['quantity'] ?? 0);

                $quantityTotal += $quantity;

                if ($quantity > 0) {
                    $filledCount++;
                }
            }

            $columnTotals[$index] = $quantityTotal;
            $columnFilledCounts[$index] = $filledCount;
        }

        $brandTotals = [];
        $brandFilledCounts = [];

        foreach ($flatColumns as $column) {
            $index = (int) $column['index'];
            $brandId = (int) $column['brand_id'];

            $brandTotals[$brandId] = ($brandTotals[$brandId] ?? 0) + ($columnTotals[$index] ?? 0);
            $brandFilledCounts[$brandId] = ($brandFilledCounts[$brandId] ?? 0) + ($columnFilledCounts[$index] ?? 0);
        }

        return [
            'columns' => $columnTotals,
            'filled_counts' => $columnFilledCounts,
            'brands' => $brandTotals,
            'brand_filled_counts' => $brandFilledCounts,
            'grand_total' => array_sum($columnTotals),
            'grand_filled_count' => array_sum($columnFilledCounts),
        ];
    }

    protected function cellStatus(bool $enabled, bool $orderExists, ?int $quantity): string
    {
        if (! $enabled) {
            return 'disabled';
        }

        if (! $orderExists) {
            return 'missing';
        }

        if ((int) $quantity === 0) {
            return 'empty';
        }

        return 'filled';
    }

    protected function orderKey(
        int $addressId,
        int $brandId,
        int $typeId
    ): string {
        return "{$addressId}-{$brandId}-{$typeId}";
    }
}

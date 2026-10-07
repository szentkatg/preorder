<?php

namespace App\Services\Pricing;

use App\Models\Currency;
use App\Models\PricingCalculationError;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\ProductPurchasePrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CostCalculationService
{
    public function __construct(
        private readonly LandedCostCalculator $landedCostCalculator,
    ) {}

    /**
     * @return array{
     *     run_id: string,
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     candidate_groups: int,
     *     products_without_active_purchase_price: int,
     *     errors: array<int, string>
     * }
     */
    public function recalculate(PricingProject $project): array
    {
        $project->loadMissing('season');
        $runId = (string) Str::uuid();

        $hufCurrency = Currency::query()
            ->where('code', 'HUF')
            ->first();

        if (! $hufCurrency) {
            throw new RuntimeException('Hiányzó HUF pénznem.');
        }

        $productsWithoutActivePurchasePrice = Product::query()
            ->where('season_id', $project->season_id)
            ->where('active', true)
            ->whereDoesntHave(
                'purchasePrices',
                fn ($query) => $query->where('active', true)
            )
            ->with('itemMainGroup')
            ->get();

        $purchasePrices = ProductPurchasePrice::query()
            ->where('active', true)
            ->whereHas('product', function ($query) use ($project): void {
                $query
                    ->where('season_id', $project->season_id)
                    ->where('active', true);
            })
            ->with([
                'product.itemMainGroup',
                'product.season',
                'color',
                'supplier',
                'currency',
            ])
            ->get();

        $purchasePriceGroups = $purchasePrices
            ->groupBy(
                fn (ProductPurchasePrice $purchasePrice): string =>
                    $purchasePrice->product_id . '|'
                    . ((int) ($purchasePrice->color_id ?? 0))
            );

        $result = [
            'run_id' => $runId,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'candidate_groups' => $purchasePriceGroups->count(),
            'products_without_active_purchase_price' =>
                $productsWithoutActivePurchasePrice->count(),
            'errors' => [],
        ];

        DB::transaction(function () use ($purchasePriceGroups, $productsWithoutActivePurchasePrice, $project, $hufCurrency, $runId, &$result): void {
            foreach ($productsWithoutActivePurchasePrice as $product) {
                PricingCalculationError::query()->create([
                    'run_id' => $runId,
                    'pricing_project_id' => $project->id,
                    'price_type' => PricingCalculationRow::TYPE_COST,
                    'product_id' => $product->id,
                    'severity' => PricingCalculationError::SEVERITY_WARNING,
                    'message' => 'Nincs aktív beszerzési ár a termékhez.',
                    'context' => [
                        'model_code' => $product->model_code,
                        'product_name' => $product->name_hu,
                        'item_main_group' => $product->itemMainGroup?->name_hu,
                    ],
                ]);
            }

            foreach ($purchasePriceGroups as $purchasePrices) {
                $candidates = [];
                $candidateErrors = [];

                foreach ($purchasePrices as $purchasePrice) {
                    try {
                        $calculation = $this->landedCostCalculator
                            ->calculate($project, $purchasePrice);

                        $candidates[] = [
                            'purchase_price_record' => $purchasePrice,
                            'calculation' => $calculation,
                        ];
                    } catch (Throwable $exception) {
                        $message = $this->candidateErrorMessage($purchasePrice, $exception);

                        $candidateErrors[] = [
                            'purchase_price' => $purchasePrice,
                            'message' => $message,
                            'exception' => $exception,
                        ];

                        $result['errors'][] = $message;
                    }
                }

                if ($candidates === []) {
                    foreach ($candidateErrors as $candidateError) {
                        /** @var ProductPurchasePrice $failedPurchasePrice */
                        $failedPurchasePrice = $candidateError['purchase_price'];

                        PricingCalculationError::query()->create([
                            'run_id' => $runId,
                            'pricing_project_id' => $project->id,
                            'price_type' => PricingCalculationRow::TYPE_COST,
                            'product_id' => $failedPurchasePrice->product_id,
                            'color_id' => $failedPurchasePrice->color_id,
                            'product_purchase_price_id' => $failedPurchasePrice->getKey(),
                            'supplier_id' => $failedPurchasePrice->supplier_id,
                            'severity' => PricingCalculationError::SEVERITY_ERROR,
                            'message' => $candidateError['message'],
                            'context' => [
                                'model_code' => $failedPurchasePrice->product?->model_code,
                                'color_code' => $failedPurchasePrice->color?->code,
                                'supplier_code' => $failedPurchasePrice->supplier?->erp_partner_code,
                                'supplier_name' => $failedPurchasePrice->supplier?->short_name
                                    ?: $failedPurchasePrice->supplier?->name,
                                'currency' => $failedPurchasePrice->currency?->code,
                                'purchase_price' => $failedPurchasePrice->purchase_price,
                                'exception' => $candidateError['exception']->getMessage(),
                            ],
                        ]);
                    }

                    $result['skipped']++;

                    continue;
                }

                usort(
                    $candidates,
                    fn (array $left, array $right): int =>
                        $left['calculation']['landed_cost_huf']
                        <=> $right['calculation']['landed_cost_huf']
                );

                /** @var ProductPurchasePrice $selectedPurchasePrice */
                $selectedPurchasePrice = $candidates[0]['purchase_price_record'];
                $selectedCalculation = $candidates[0]['calculation'];

                $row = PricingCalculationRow::query()
                    ->where('pricing_project_id', $project->id)
                    ->where('product_id', $selectedPurchasePrice->product_id)
                    ->where('color_key', (int) ($selectedPurchasePrice->color_id ?? 0))
                    ->where('price_list_key', 0)
                    ->where('price_type', PricingCalculationRow::TYPE_COST)
                    ->where('currency_id', $hufCurrency->id)
                    ->first();

                $wasRecentlyCreated = false;

                if (! $row) {
                    $row = new PricingCalculationRow();
                    $row->pricing_project_id = $project->id;
                    $row->product_id = $selectedPurchasePrice->product_id;
                    $row->color_id = $selectedPurchasePrice->color_id;
                    $row->price_list_id = null;
                    $row->price_type = PricingCalculationRow::TYPE_COST;
                    $row->currency_id = $hufCurrency->id;
                    $row->status = PricingCalculationRow::STATUS_CALCULATED;
                    $wasRecentlyCreated = true;
                }

                $row->product_purchase_price_id = $selectedPurchasePrice->getKey();
                $row->supplier_id = $selectedPurchasePrice->supplier_id;
                $row->purchase_currency_id = $selectedPurchasePrice->currency_id;
                $row->purchase_price = $selectedCalculation['purchase_price'];
                $row->exchange_rate = $selectedCalculation['exchange_rate'];
                $row->shipping_cost_percent = $selectedCalculation['shipping_cost_percent'];
                $row->customs_percent = $selectedCalculation['customs_percent'];
                $row->candidate_count = count($candidates);
                $row->calculated_price = $selectedCalculation['landed_cost_huf'];
                $row->calculation_snapshot = $this->snapshot(
                    $project,
                    $selectedPurchasePrice,
                    $selectedCalculation,
                    $candidates
                );

                $row->save();

                if ($wasRecentlyCreated) {
                    $result['created']++;
                } else {
                    $result['updated']++;
                }
            }
        });

        return $result;
    }

    /**
     * @param array<int, array{
     *     purchase_price_record: ProductPurchasePrice,
     *     calculation: array<string, mixed>
     * }> $candidates
     * @return array<string, mixed>
     */
    private function snapshot(
        PricingProject $project,
        ProductPurchasePrice $selectedPurchasePrice,
        array $selectedCalculation,
        array $candidates,
    ): array {
        $product = $selectedPurchasePrice->product;
        $supplier = $selectedPurchasePrice->supplier;
        $currency = $selectedPurchasePrice->currency;

        return [
            'calculated_at' => now()->toISOString(),
            'pricing_project' => [
                'id' => $project->id,
                'name' => $project->name,
                'season_id' => $project->season_id,
                'season' => $project->season?->name,
            ],
            'formula' => 'purchase_price * exchange_rate * (1 + shipping_cost_percent / 100) * (1 + customs_percent / 100)',
            'selection_rule' => 'Több aktív beszerzési ár esetén a legalacsonyabb HUF bekerülési érték kerül kiválasztásra.',
            'selected_purchase_price' => [
                'id' => $selectedPurchasePrice->getKey(),
                'purchase_price' => $selectedCalculation['purchase_price'],
                'currency' => $currency?->code,
                'exchange_rate' => $selectedCalculation['exchange_rate'],
                'shipping_cost_percent' => $selectedCalculation['shipping_cost_percent'],
                'customs_percent' => $selectedCalculation['customs_percent'],
                'landed_cost_huf' => $selectedCalculation['landed_cost_huf'],
            ],
            'product' => [
                'id' => $product?->id,
                'model_code' => $product?->model_code,
                'name_hu' => $product?->name_hu,
                'item_main_group_id' => $product?->item_main_group_id,
                'item_main_group' => $product?->itemMainGroup?->name_hu,
                'material_composition' => $product?->material_composition,
            ],
            'color' => [
                'id' => $selectedPurchasePrice->color?->id,
                'code' => $selectedPurchasePrice->color?->code,
                'name_hu' => $selectedPurchasePrice->color?->name_hu,
            ],
            'supplier' => [
                'id' => $supplier?->supplier_id,
                'erp_partner_code' => $supplier?->erp_partner_code,
                'name' => $supplier?->name,
                'short_name' => $supplier?->short_name,
                'country_code' => $supplier?->country_code,
            ],
            'compared_candidates' => array_map(
                fn (array $candidate): array => $this->candidateSnapshot($candidate),
                $candidates
            ),
        ];
    }

    /**
     * @param array{
     *     purchase_price_record: ProductPurchasePrice,
     *     calculation: array<string, mixed>
     * } $candidate
     * @return array<string, mixed>
     */
    private function candidateSnapshot(array $candidate): array
    {
        /** @var ProductPurchasePrice $purchasePrice */
        $purchasePrice = $candidate['purchase_price_record'];
        $calculation = $candidate['calculation'];

        return [
            'product_purchase_price_id' => $purchasePrice->getKey(),
            'supplier_id' => $purchasePrice->supplier_id,
            'supplier_code' => $purchasePrice->supplier?->erp_partner_code,
            'supplier_name' => $purchasePrice->supplier?->short_name
                ?: $purchasePrice->supplier?->name,
            'supplier_country_code' => $purchasePrice->supplier?->country_code,
            'purchase_price' => $calculation['purchase_price'],
            'currency' => $purchasePrice->currency?->code,
            'exchange_rate' => $calculation['exchange_rate'],
            'shipping_cost_percent' => $calculation['shipping_cost_percent'],
            'customs_percent' => $calculation['customs_percent'],
            'landed_cost_huf' => $calculation['landed_cost_huf'],
        ];
    }

    private function candidateErrorMessage(
        ProductPurchasePrice $purchasePrice,
        Throwable $exception,
    ): string {
        return sprintf(
            '%s / %s / beszerzési ár ID %s: %s',
            $purchasePrice->product?->model_code ?? 'ismeretlen modell',
            $purchasePrice->color?->code ?? 'általános szín',
            $purchasePrice->getKey(),
            $exception->getMessage()
        );
    }
}

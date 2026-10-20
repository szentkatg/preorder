<?php

namespace App\Services\Pricing;

use App\Models\PriceList;
use App\Models\PricingCalculationError;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DistributorCalculationService
{
    public function __construct(private readonly DistributorCalculator $calculator, private readonly DerivedPriceRowWriter $writer) {}

    public function recalculate(PricingProject $project, ?int $listId = null): array
    {
        Gate::authorize('update', $project);
        Gate::authorize('create', PricingCalculationRow::class);
        Gate::authorize('update', new PricingCalculationRow);

        return DB::transaction(function () use ($project, $listId): array {
            $project = PricingProject::query()->lockForUpdate()->findOrFail($project->id);
            if (! $project->isOpenForPricing()) {
                throw new DomainException('Lezárt árprojekt nem számítható újra.');
            }
            $lists = PriceList::query()->where('type', 'distributor')->where('active', true)
                ->whereHas('currency', fn ($query) => $query->where('code', 'EUR'))
                ->when($listId !== null, fn ($query) => $query->whereKey($listId))
                ->with(['currency', 'sourceWholesalePriceList'])->orderBy('id')->get();
            if ($lists->isEmpty()) {
                throw new DomainException('Nincs megfelelő aktív EUR disztribútori árlista.');
            }
            foreach ($lists as $list) {
                $source = $list->sourceWholesalePriceList;
                if (! $source || ! $source->active || $source->type !== 'wholesale' || $source->currency_id != $list->currency_id) {
                    throw new DomainException($list->code.': aktív EUR nagyker forrásárlista szükséges.');
                }
                if (! $this->sources($project, $list)->exists()) {
                    throw new DomainException($source->code.': előbb számítsd ki a projekt nagyker árait.');
                }
            }
            $result = ['run_id' => (string) Str::uuid(), 'price_lists' => $lists->count(), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'requires_review' => 0];
            foreach ($lists as $list) {
                foreach ($this->sources($project, $list)->with(['product.itemMainGroup', 'supplier', 'currency', 'priceList'])->lockForUpdate()->get() as $source) {
                    try {
                        $calculation = $this->calculator->calculate($project, $source, $list);
                    } catch (DomainException $exception) {
                        PricingCalculationRow::where('pricing_project_id', $project->id)->where('product_id', $source->product_id)
                            ->where('color_key', $source->color_key)->where('price_list_key', $list->id)
                            ->where('price_type', 'distributor')->where('currency_id', $list->currency_id)->update(['requires_review' => true]);
                        $result['skipped']++;
                        PricingCalculationError::create(['run_id' => $result['run_id'], 'pricing_project_id' => $project->id,
                            'price_type' => 'distributor', 'product_id' => $source->product_id, 'color_id' => $source->color_id,
                            'supplier_id' => $source->supplier_id, 'severity' => PricingCalculationError::SEVERITY_ERROR,
                            'message' => $list->code.' / '.$source->product->model_code.': '.$exception->getMessage(),
                            'context' => ['price_list_id' => $list->id, 'source_wholesale_row_id' => $source->id]]);

                        continue;
                    }
                    $saved = $this->writer->save($project, $source, $list, $calculation, $this->calculator, $result['run_id']);
                    $result[$saved['created'] ? 'created' : 'updated']++;
                    $result['requires_review'] += (int) $saved['requires_review'];
                }
            }

            return $result;
        });
    }

    private function sources(PricingProject $project, PriceList $list): Builder
    {
        return PricingCalculationRow::query()->where('pricing_project_id', $project->id)->where('price_type', 'wholesale')
            ->where('price_list_id', $list->source_wholesale_price_list_id)->where('currency_id', $list->currency_id)
            ->whereHas('product', fn ($query) => $query->forPricingProject($project->id)->where('active', true)->where('season_id', $project->season_id));
    }
}

<?php

namespace App\Services\Pricing;

use App\Models\PriceList;
use App\Models\PricingCalculationError;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class OutletCalculationService
{
    public function __construct(private readonly OutletCalculator $calculator, private readonly DerivedPriceRowWriter $writer) {}

    public function lists(PricingProject $project, string $currency): Collection
    {
        return PriceList::query()->where('type', 'outlet')->where('active', true)
            ->whereHas('currency', fn ($query) => $query->where('code', $currency)->where('active', true))
            ->whereExists(function ($query) use ($project): void {
                $query->selectRaw('1')->from('pricing_parameters')->where('pricing_project_id', $project->id)
                    ->where('parameter_type', PricingParameter::TYPE_OUTLET_DISCOUNT)->where('active', true)
                    ->where(fn ($query) => $query->whereNull('price_list_id')->orWhereColumn('price_list_id', 'price_lists.id'))
                    ->where(fn ($query) => $query->whereNull('currency_id')->orWhereColumn('currency_id', 'price_lists.currency_id'))
                    ->where(fn ($query) => $query->whereNotNull('currency_id')->orWhereNotNull('price_list_id'));
            })->with(['currency', 'sourceRetailPriceList'])->orderBy('code')->get();
    }

    public function recalculate(PricingProject $project, string $currency): array
    {
        Gate::authorize('update', $project);
        Gate::authorize('create', PricingCalculationRow::class);
        Gate::authorize('update', new PricingCalculationRow);

        return DB::transaction(function () use ($project, $currency): array {
            $project = PricingProject::lockForUpdate()->findOrFail($project->id);
            if (! $project->isOpenForPricing()) {
                throw new DomainException('Lezárt árprojekt nem számítható újra.');
            }
            $lists = $this->lists($project, $currency);
            if ($lists->isEmpty()) {
                throw new DomainException('Nincs aktív outlet árlista és a pénznemhez rendelt outlet kedvezmény paraméter.');
            }
            $result = ['run_id' => (string) Str::uuid(), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'requires_review' => 0, 'below_minimum' => 0];
            foreach ($lists as $list) {
                if (! $list->sourceRetailPriceList?->active || $list->sourceRetailPriceList->type !== 'retail'
                    || $list->sourceRetailPriceList->currency_id != $list->currency_id) {
                    throw new DomainException($list->code.': azonos pénznemű aktív normál kisker forrásárlista szükséges.');
                }
                $sources = PricingCalculationRow::query()->where('pricing_project_id', $project->id)->where('price_type', 'retail')
                    ->where('price_list_id', $list->source_retail_price_list_id)->where('currency_id', $list->currency_id)
                    ->whereHas('product', fn ($query) => $query->where('active', true)->where('season_id', $project->season_id))
                    ->with(['product', 'supplier', 'currency', 'priceList'])->lockForUpdate()->get();
                if ($sources->isEmpty()) {
                    throw new DomainException($list->code.': előbb számítsd ki a forrás kisker árakat.');
                }
                foreach ($sources as $source) {
                    try {
                        $calculation = $this->calculator->calculate($project, $source, $list);
                    } catch (DomainException $exception) {
                        $source->outletRows()->where('price_list_id', $list->id)->update(['requires_review' => true]);
                        $result['skipped']++;
                        PricingCalculationError::create(['run_id' => $result['run_id'], 'pricing_project_id' => $project->id,
                            'price_type' => 'outlet', 'product_id' => $source->product_id, 'color_id' => $source->color_id,
                            'supplier_id' => $source->supplier_id, 'severity' => PricingCalculationError::SEVERITY_ERROR,
                            'message' => $list->code.' / '.$source->product->model_code.': '.$exception->getMessage(),
                            'context' => ['price_list_id' => $list->id, 'source_retail_row_id' => $source->id]]);

                        continue;
                    }
                    $saved = $this->writer->save($project, $source, $list, $calculation, $this->calculator, $result['run_id']);
                    $result[$saved['created'] ? 'created' : 'updated']++;
                    $result['requires_review'] += (int) $saved['requires_review'];
                    $row = $source->outletRows()->where('price_list_id', $list->id)->firstOrFail();
                    $result['below_minimum'] += (int) ! $row->outletDiscountIsSufficient();
                }
            }

            return $result;
        });
    }
}

<?php

namespace App\Services\Pricing;

use App\Models\ExchangeRate;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\PricingRoundingRule;
use App\Models\Product;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** A single-product, atomic cost -> retail -> wholesale -> distributor/outlet workflow. */
class ProductPricingService
{
    public function open(PricingProject $project, Product $product): array
    {
        $this->authorize($project, $product);
        $purchases = $product->purchasePrices()->where('active', true)->with(['currency', 'supplier', 'color'])->orderBy('product_purchase_price_id')->get();
        $rows = $this->rows($project, $product)->with(['color', 'priceList.currency'])->get();
        $lists = $this->lists($project, $product);
        $fields = [];
        foreach ($purchases->unique('currency_id') as $purchase) {
            $selectedId = $rows->where('price_type', 'cost')->firstWhere('purchase_currency_id', $purchase->currency_id)?->product_purchase_price_id;
            $effectivePurchase = $purchases->firstWhere('product_purchase_price_id', $selectedId) ?? $purchase;
            foreach (['shipping_cost_percent' => 'Szállítás %', 'customs_percent' => 'Vám %'] as $type => $label) {
                $parameter = app(PricingParameterResolver::class)->resolve($project->id, $type, $product, $effectivePurchase->supplier?->country_code,
                    currencyId: $purchase->currency_id, requireUnique: true);
                $fields[$type.'_'.$purchase->currency_id] = ['label' => $label.' ('.$purchase->currency->code.')',
                    'type' => $type, 'currency_id' => $purchase->currency_id, 'price_list_id' => null, 'value' => $parameter?->value ?? 0,
                    'editable' => Gate::allows('create', PricingParameter::class) && Gate::allows('update', new PricingParameter)];
            }
        }
        $selectedPurchase = $purchases->firstWhere('product_purchase_price_id', $rows->firstWhere('price_type', 'cost')?->product_purchase_price_id) ?? $purchases->first();
        foreach ($lists as $list) {
            $type = $this->parameterType($list);
            $parameter = app(PricingParameterResolver::class)->resolve($project->id, $type, $product,
                $selectedPurchase?->supplier?->country_code, $list->id, $list->currency_id, requireUnique: true);
            $fields['list_'.$list->id] = ['label' => $list->code.' – '.match ($type) {
                'retail_multiplier' => 'Kisker szorzó', 'country_multiplier' => 'Országszorzó',
                'wholesale_margin' => 'Nagyker szorzó', default => 'Kedvezmény %',
            }, 'type' => $type, 'currency_id' => $list->currency_id, 'price_list_id' => $list->id, 'value' => $parameter?->value,
                'editable' => Gate::allows('create', PricingParameter::class) && Gate::allows('update', new PricingParameter)];
        }
        $sources = [];
        $huf = $lists->filter(fn ($list) => $list->type === 'retail' && $list->currency->code === 'HUF');
        foreach ($lists->filter(fn ($list) => $list->type === 'retail' && $list->currency->code !== 'HUF') as $list) {
            $sourceId = $rows->firstWhere('price_list_id', $list->id)?->source_retail_row_id;
            $sources[$list->id] = $rows->firstWhere('id', $sourceId)?->price_list_id ?? ($huf->count() === 1 ? $huf->first()->id : null);
        }

        return ['product' => $product->only(['id', 'model_code', 'name_hu']), 'fields' => $fields,
            'original_prices' => $rows->where('price_type', '!=', 'cost')->mapWithKeys(fn ($row) => [$row->price_list_id.'_'.$row->color_key => $row->final_price])->all(),
            'exchange_rates' => ExchangeRate::where('season_id', $project->season_id)->where('active', true)->with('currency')->get()
                ->map(fn ($rate) => ['currency' => $rate->currency?->code, 'rate' => $rate->rate_to_huf])->all(),
            'purchases' => $purchases->map(fn ($p) => ['id' => $p->getKey(), 'supplier' => $p->supplier?->name,
                'currency' => $p->currency?->code, 'color' => $p->color?->name_hu, 'editable' => Gate::allows('update', $p)])->all(),
            'huf_sources' => $huf->pluck('code', 'id')->all(),
            'foreign_lists' => $lists->filter(fn ($list) => $list->type === 'retail' && $list->currency->code !== 'HUF')->pluck('code', 'id')->all(),
            'data' => ['purchase_prices' => $purchases->mapWithKeys(fn ($p) => [$p->getKey() => $this->inputValue($p->purchase_price)])->all(),
                'parameters' => collect($fields)->map(fn ($field) => $this->inputValue($field['value']))->all(),
                'sources' => $sources,
                'manual' => $rows->where('price_type', '!=', 'cost')->mapWithKeys(fn ($row) => [$row->price_list_id.'_'.$row->color_key => $row->manual_price])->all()],
            'initial_preview' => ['costs' => $rows->where('price_type', 'cost')->map(fn ($row) => ['color' => $row->color?->name_hu ?? 'Modell', 'price' => $row->final_price])->values()->all(),
                'prices' => $rows->filter(fn ($row) => $row->priceList && $row->price_type !== 'cost')->map(fn ($row) => [
                    'key' => $row->price_list_id.'_'.$row->color_key, 'list' => $row->priceList->code, 'currency' => $row->priceList->currency?->code,
                    'color' => $row->color?->name_hu ?? 'Modell', 'calculated' => $row->calculated_price, 'final' => $row->final_price,
                    'manual' => $row->manual_price, 'status' => $row->status])->values()->all()],
            'fingerprint' => $this->fingerprint($project, $product)];
    }

    public function fingerprint(PricingProject $project, Product $product): string
    {
        return hash('sha256', json_encode([
            $project->fresh()->getAttributes(), $product->fresh()->getAttributes(),
            $product->purchasePrices()->orderBy('product_purchase_price_id')->get()->toArray(),
            PricingParameter::where('pricing_project_id', $project->id)->orderBy('id')->get()->toArray(),
            PricingRoundingRule::where('pricing_project_id', $project->id)->orderBy('id')->get()->toArray(),
            ExchangeRate::where('season_id', $project->season_id)->orderBy('id')->get()->toArray(),
            PriceList::orderBy('id')->get()->toArray(), $this->rows($project, $product)->orderBy('id')->get()->toArray(),
        ], JSON_THROW_ON_ERROR));
    }

    /** Previews use the real calculators and validations inside a transaction that is ALWAYS rolled back. */
    public function preview(PricingProject $project, Product $product, array $input, string $fingerprint): array
    {
        DB::beginTransaction();
        try {
            return $this->apply($project, $product, $input, $fingerprint);
        } finally {
            DB::rollBack();
        }
    }

    public function approve(PricingProject $project, Product $product, array $input, string $fingerprint): array
    {
        return DB::transaction(fn () => $this->apply($project, $product, $input, $fingerprint));
    }

    private function apply(PricingProject $project, Product $product, array $input, string $fingerprint): array
    {
        $project = PricingProject::lockForUpdate()->findOrFail($project->id);
        $product = Product::lockForUpdate()->findOrFail($product->id);
        $product->purchasePrices()->lockForUpdate()->get();
        $this->authorize($project, $product);
        if (! hash_equals($this->fingerprint($project, $product), $fingerprint)) {
            throw new DomainException('A termék, a paraméterek vagy az árak időközben megváltoztak. Nyisd meg újra az árkezelőt.');
        }
        $settings = $this->open($project, $product);
        $input = $this->validate($input, $settings);
        foreach ($input['purchase_prices'] as $id => $value) {
            $purchase = $product->purchasePrices()->where('active', true)->findOrFail($id);
            if ((float) $purchase->purchase_price !== (float) $value) {
                Gate::authorize('update', $purchase);
                $purchase->update(['purchase_price' => $value]);
                // A master purchase price can be used by another open project as well.
                PricingCalculationRow::where('product_id', $product->id)
                    ->where('pricing_project_id', '!=', $project->id)->whereHas('pricingProject', fn ($q) => $q->openForPricing())
                    ->update(['requires_review' => true]);
            }
        }
        foreach ($settings['fields'] as $key => $field) {
            $value = $input['parameters'][$key];
            if ($field['value'] !== null && (float) $value === (float) $field['value']) {
                continue;
            }
            $attributes = ['pricing_project_id' => $project->id, 'product_id' => $product->id,
                'scope_type' => 'product', 'parameter_type' => $field['type'], 'price_list_id' => $field['price_list_id'],
                'currency_id' => $field['currency_id'], 'supplier_country_code' => null, 'item_main_group_id' => null];
            $matches = PricingParameter::where($attributes)->get();
            if ($matches->count() > 1) {
                throw new DomainException('Több modellszintű paraméter van: '.$field['label']);
            }
            $parameter = $matches->first() ?? new PricingParameter($attributes);
            Gate::authorize($parameter->exists ? 'update' : 'create', $parameter->exists ? $parameter : PricingParameter::class);
            $parameter->fill(['value' => $value, 'active' => true])->save();
        }
        $run = app(CostCalculationService::class)->recalculate($project, $product->id);
        $this->assertRun($run);
        if ($run['products_without_active_purchase_price'] || ! $run['candidate_groups']) {
            throw new DomainException('Nincs érvényes aktív beszerzési ár a termékhez.');
        }
        $this->rows($project, $product)->where('price_type', 'cost')->get()->each(fn ($row) => $row->update(['requires_review' => false]));
        $lists = $this->lists($project, $product);
        $result = [];
        foreach ($lists->filter(fn ($l) => $l->type === 'retail' && $l->currency->code === 'HUF') as $list) {
            $this->assertRun(app(HufRetailCalculationService::class)->recalculate($project, $list, $product->id));
            $this->approveList($project, $product, $list, $input['manual'], $result);
        }
        foreach ($lists->filter(fn ($l) => $l->type === 'retail' && $l->currency->code !== 'HUF') as $list) {
            $source = $lists->firstWhere('id', $input['sources'][$list->id] ?? null);
            if (! $source || $source->type !== 'retail' || $source->currency->code !== 'HUF') {
                throw new DomainException($list->code.': válassz HUF forrásárlistát.');
            }
            $this->assertRun(app(ForeignRetailCalculationService::class)->recalculate($project, $source, $list, productId: $product->id));
            $this->approveList($project, $product, $list, $input['manual'], $result);
        }
        foreach (['wholesale' => WholesaleCalculator::class, 'distributor' => DistributorCalculator::class, 'outlet' => OutletCalculator::class] as $type => $calculatorClass) {
            foreach ($lists->where('type', $type) as $list) {
                $sourceListId = match ($type) {
                    'wholesale' => $list->retail_price_list_id, 'distributor' => $list->source_wholesale_price_list_id,
                    'outlet' => $list->source_retail_price_list_id,
                };
                $sources = $this->rows($project, $product)->where('price_list_id', $sourceListId)
                    ->where('price_type', $type === 'distributor' ? 'wholesale' : 'retail')->with(['product', 'supplier', 'currency', 'priceList'])->get();
                if ($sources->isEmpty()) {
                    throw new DomainException($list->code.': hiányzó forráskalkuláció.');
                }
                $calculator = app($calculatorClass);
                foreach ($sources as $source) {
                    app(DerivedPriceRowWriter::class)->save($project, $source, $list, $calculator->calculate($project, $source, $list), $calculator, (string) Str::uuid());
                }
                $this->approveList($project, $product, $list, $input['manual'], $result);
            }
        }

        return ['costs' => $this->rows($project, $product)->where('price_type', 'cost')->with(['color', 'supplier', 'purchaseCurrency'])->get()
            ->map(fn ($row) => ['color' => $row->color?->name_hu ?? 'Modell', 'price' => $row->final_price,
                'supplier' => $row->supplier?->name, 'purchase' => $row->purchase_price, 'currency' => $row->purchaseCurrency?->code,
                'rate' => $row->exchange_rate, 'shipping' => $row->shipping_cost_percent, 'customs' => $row->customs_percent])->all(), 'prices' => $result];
    }

    private function approveList(PricingProject $project, Product $product, PriceList $list, array $manual, array &$result): void
    {
        $service = app(match ($list->type) {
            'retail' => RetailPriceReviewService::class, 'wholesale' => WholesalePriceReviewService::class,
            'distributor' => DistributorPriceReviewService::class, 'outlet' => OutletPriceReviewService::class,
        });
        foreach ($this->rows($project, $product)->where('price_list_id', $list->id)->with('color')->get() as $row) {
            $key = $list->id.'_'.$row->color_key;
            if (isset($manual[$key]) && $manual[$key] !== '') {
                $row = $service->setManualPrice($row, $manual[$key]);
                $row = $service->approveCurrentPrice($row);
            } else {
                $row = $service->acceptCalculatedPrice($row);
            }
            $result[] = ['key' => $key, 'list' => $list->code, 'currency' => $list->currency->code,
                'color' => $row->color?->name_hu ?? 'Modell', 'calculated' => $row->calculated_price,
                'final' => $row->final_price, 'manual' => $row->manual_price, 'status' => $row->status];
        }
    }

    private function validate(array $input, array $settings): array
    {
        $rules = ['purchase_prices' => ['required', 'array:'.implode(',', array_keys($settings['data']['purchase_prices']))],
            'parameters' => ['required', 'array:'.implode(',', array_keys($settings['fields']))], 'sources' => ['array'], 'manual' => ['array']];
        foreach ($settings['data']['purchase_prices'] as $id => $value) {
            $rules['purchase_prices.'.$id] = ['required', 'numeric', 'gt:0', 'max:99999999999.9999'];
        }
        foreach ($settings['fields'] as $key => $field) {
            $discount = in_array($field['type'], ['distributor_discount', 'outlet_discount'], true);
            $percent = $discount || in_array($field['type'], ['shipping_cost_percent', 'customs_percent'], true);
            $rules['parameters.'.$key] = ['required', 'numeric', $percent ? 'min:0' : 'gt:0', $discount ? 'lt:100' : 'max:999999'];
        }
        foreach ($input['manual'] ?? [] as $key => $value) {
            if (! preg_match('/^([1-9][0-9]*)_([0-9]+)$/D', (string) $key) || ! isset($settings['fields']['list_'.explode('_', $key)[0]])) {
                throw new DomainException('Érvénytelen manuális árlista vagy szín.');
            }
            $rules['manual.'.$key] = ['nullable', 'numeric', 'gt:0', 'max:99999999999.9999'];
        }
        foreach (['purchase_prices', 'parameters', 'manual'] as $section) {
            foreach ($input[$section] ?? [] as $key => $value) {
                if (is_string($value)) {
                    $input[$section][$key] = trim($value) === '' ? null : str_replace(',', '.', trim($value));
                }
            }
        }

        return Validator::make($input, $rules)->validate() + ['sources' => [], 'manual' => []];
    }

    private function authorize(PricingProject $project, Product $product): void
    {
        Gate::authorize('viewAny', PricingCalculationRow::class);
        Gate::authorize('update', $project);
        Gate::authorize('create', PricingCalculationRow::class);
        Gate::authorize('update', new PricingCalculationRow);
        if (! $project->isOpenForPricing() || ! $product->active || $product->season_id != $project->season_id) {
            throw new DomainException('Csak nem lezárt árprojekt aktív, azonos szezonú terméke kezelhető.');
        }
    }

    private function assertRun(array $result): void
    {
        if ($result['skipped']) {
            throw new DomainException('A termék nem számítható végig: '.implode(' ', array_slice($result['errors'] ?? [], 0, 3)));
        }
    }

    private function rows(PricingProject $project, Product $product)
    {
        return PricingCalculationRow::where('pricing_project_id', $project->id)->where('product_id', $product->id);
    }

    private function lists(PricingProject $project, Product $product): Collection
    {
        $existing = $this->rows($project, $product)->pluck('price_list_id')->filter()->all();
        $country = $this->rows($project, $product)->where('price_type', 'cost')->with('supplier')->first()?->supplier?->country_code
            ?? $product->purchasePrices()->where('active', true)->with('supplier')->first()?->supplier?->country_code;

        return PriceList::where('active', true)->whereIn('type', ['retail', 'wholesale', 'distributor', 'outlet'])
            ->whereHas('currency', fn ($q) => $q->where('active', true))->with('currency')->orderBy('id')->get()
            ->filter(fn ($list) => in_array($list->id, $existing) || (app(PricingParameterResolver::class)->resolve(
                $project->id, $this->parameterType($list), $product, $country, priceListId: $list->id, currencyId: $list->currency_id)
                && app(PricingRoundingService::class)->resolve($project->id, $list->type, $list->id, $list->currency_id)));
    }

    private function parameterType(PriceList $list): string
    {
        return match ($list->type) {
            'retail' => $list->currency->code === 'HUF' ? 'retail_multiplier' : 'country_multiplier',
            'wholesale' => 'wholesale_margin', 'distributor' => 'distributor_discount', 'outlet' => 'outlet_discount',
        };
    }

    private function inputValue(mixed $value): ?string
    {
        return $value === null ? null : (str_contains((string) $value, '.') ? rtrim(rtrim((string) $value, '0'), '.') : (string) $value);
    }
}

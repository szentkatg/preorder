<?php

namespace App\Services;

use App\Models\Language;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Season;
use App\Models\Translation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PartnerPriceListService
{
    public const LANGUAGES = ['HU', 'EN'];

    public const CATALOG_KEY_SQL = "COALESCE(NULLIF(products.catalog_group_name_hu, ''), products.catalog_group_name_en, '')";

    public static function canRead(): bool
    {
        foreach ([Product::class, PriceList::class, PriceListItem::class] as $model) {
            if (Gate::denies('viewAny', $model) || Gate::denies('view', new $model)) {
                return false;
            }
        }

        return true;
    }

    public function validateConfiguration(array $input): array
    {
        abort_unless(self::canRead(), 403);
        $rules = [
            'season_id' => ['required', 'integer', Rule::exists('seasons', 'id')],
            'price_list_id' => ['required', 'integer', Rule::exists('price_lists', 'id')->where('active', true)->whereIn('type', ['wholesale', 'distributor'])],
            'retail_price_list_id' => ['nullable', 'integer', Rule::exists('price_lists', 'id')->where('active', true)->where('type', 'retail')],
            'language_id' => ['required', 'integer', Rule::exists('languages', 'id')->where('active', true)],
            'format' => ['required', Rule::in(['xlsx', 'pdf'])],
            'brand_ids' => ['present', 'array', 'max:1000'],
            'brand_ids.*' => ['integer', Rule::exists('brands', 'id')->where('active', true)],
            'item_main_group_ids' => ['present', 'array', 'max:1000'],
            'item_main_group_ids.*' => ['integer', Rule::exists('item_main_groups', 'id')->where('active', true)],
            'catalog_groups' => ['present', 'array', 'max:1000'],
            'catalog_groups.*' => ['string', 'max:255'],
        ];
        $data = Validator::make(['data' => $input], collect($rules)->mapWithKeys(fn ($rule, $key) => ['data.'.$key => $rule])->all())->validate()['data'];
        $list = PriceList::findOrFail($data['price_list_id']);
        Gate::authorize('view', $list);
        $language = Language::findOrFail($data['language_id']);
        if (! in_array(strtoupper($language->code), self::LANGUAGES, true)) {
            throw ValidationException::withMessages(['data.language_id' => 'Ehhez a nyelvhez még nincs árlista-sablon.']);
        }
        if (! empty($data['retail_price_list_id'])) {
            $retail = PriceList::findOrFail($data['retail_price_list_id']);
            Gate::authorize('view', $retail);
            if ($retail->currency_id != $list->currency_id) {
                throw ValidationException::withMessages(['data.retail_price_list_id' => 'A nettó és a kisker árlista pénzneme egyezzen meg. Nem végzünk automatikus átváltást.']);
            }
        }
        foreach (['season_id', 'price_list_id', 'language_id'] as $key) {
            $data[$key] = (int) $data[$key];
        }
        $data['retail_price_list_id'] = empty($data['retail_price_list_id']) ? null : (int) $data['retail_price_list_id'];
        foreach (['brand_ids', 'item_main_group_ids'] as $key) {
            $data[$key] = array_values(array_unique(array_map('intval', $data[$key])));
        }
        $data['catalog_groups'] = array_values(array_unique($data['catalog_groups']));
        $data['locale'] = strtolower($language->code);

        return $data;
    }

    public function productsQuery(?array $configuration): Builder
    {
        $query = Product::query()->select('products.*')->with(['brand', 'itemMainGroup', 'orderSheetType']);
        if (! $configuration) {
            return $query->whereRaw('1 = 0');
        }
        $query->where('products.active', true)->where('products.season_id', $configuration['season_id']);
        foreach (['brand_ids' => 'brand_id', 'item_main_group_ids' => 'item_main_group_id'] as $key => $column) {
            $query->when($configuration[$key], fn ($query) => $query->whereIn($column, $configuration[$key]));
        }
        $query->when($configuration['catalog_groups'], fn ($query) => $query->whereIn(DB::raw(self::CATALOG_KEY_SQL), $configuration['catalog_groups']));
        foreach (['export_net_price' => $configuration['price_list_id'], 'export_retail_price' => $configuration['retail_price_list_id']] as $alias => $listId) {
            $query->selectSub(PriceListItem::query()->select('net_price')->whereColumn('product_id', 'products.id')
                ->where('season_id', $configuration['season_id'])->where('price_list_id', $listId ?? 0), $alias);
        }

        return $query;
    }

    public function catalogGroups(?int $seasonId, array $brands = [], array $mainGroups = []): array
    {
        return Product::query()->where('active', true)->when($seasonId, fn ($query) => $query->where('season_id', $seasonId))
            ->when($brands, fn ($query) => $query->whereIn('brand_id', $brands))
            ->when($mainGroups, fn ($query) => $query->whereIn('item_main_group_id', $mainGroups))
            ->selectRaw(self::CATALOG_KEY_SQL.' AS catalog_key')->distinct()->orderBy('catalog_key')
            ->pluck('catalog_key')->filter(fn ($value) => $value !== '')->mapWithKeys(fn ($value) => [$value => $value])->all();
    }

    public function document(array $configuration, array $selectedIds): array
    {
        $configuration = $this->validateConfiguration($configuration);
        if ($selectedIds === [] || count($selectedIds) > 10000 || collect($selectedIds)->contains(fn ($id) => (! is_int($id) && ! is_string($id)) || ! ctype_digit((string) $id) || (int) $id < 1)) {
            throw ValidationException::withMessages(['selection' => 'Jelölj ki legalább egy terméket (legfeljebb 10 000-et).']);
        }
        $selectedIds = array_values(array_unique(array_map('strval', $selectedIds)));
        $products = $this->productsQuery($configuration)->whereKey($selectedIds)
            ->orderBy('brand_id')->orderBy('order_sheet_type_id')->orderBy('catalog_group_sort')
            ->orderBy('catalog_sort')->orderBy('model_code')->get();
        if ($products->count() !== count($selectedIds)) {
            throw ValidationException::withMessages(['selection' => 'A kijelölés már nem egyezik a szűrt, aktív termékekkel. Töltsd újra a terméklistát.']);
        }
        foreach ($products as $product) {
            Gate::authorize('view', $product);
        }
        $prices = PriceListItem::where('season_id', $configuration['season_id'])->whereIn('product_id', $selectedIds)
            ->whereIn('price_list_id', array_filter([$configuration['price_list_id'], $configuration['retail_price_list_id']]))->get();
        foreach ($prices as $price) {
            Gate::authorize('view', $price);
        }
        $language = Language::findOrFail($configuration['language_id']);
        $locale = strtolower($language->code);
        $translations = Translation::where('language_id', $language->id)->whereIn('entity', ['brand', 'item_main_group', 'order_sheet_type', 'season'])
            ->where('field', 'name')->get()->mapWithKeys(fn ($row) => [$row->entity.'|'.$row->entity_code => $row->value])->all();
        $label = static fn ($model, string $entity) => trim((string) ($translations[$entity.'|'.$model?->code]
            ?? ($locale === 'en' ? ($model?->name_en ?: $model?->name_hu ?: $model?->name) : ($model?->name_hu ?: $model?->name ?: $model?->name_en)) ?? $model?->code));
        $list = PriceList::with('currency')->findOrFail($configuration['price_list_id']);
        $season = Season::findOrFail($configuration['season_id']);
        $captions = Lang::get('price_list', [], $locale);
        $sections = [];
        $missing = 0;
        foreach ($products as $product) {
            $brand = $label($product->brand, 'brand');
            $collection = $label($product->orderSheetType, 'order_sheet_type');
            $sectionKey = ($product->brand_id ?? 0).'|'.($product->order_sheet_type_id ?? 0);
            if (! isset($sections[$sectionKey])) {
                $sections[$sectionKey] = ['name' => trim($brand.' '.$collection) ?: $captions['title'], 'rows' => []];
            }
            $net = $product->export_net_price === null ? null : (float) $product->export_net_price;
            $retail = $product->export_retail_price === null ? null : (float) $product->export_retail_price;
            $missing += $net === null || ($configuration['retail_price_list_id'] !== null && $retail === null) ? 1 : 0;
            $delivery = $product->promised_delivery_date ? CarbonImmutable::parse($product->promised_delivery_date)->startOfDay() : null;
            $sections[$sectionKey]['rows'][] = ['id' => $product->id, 'code' => $product->model_code,
                'name' => $locale === 'en' ? ($product->name_en ?: $product->name_hu) : ($product->name_hu ?: $product->name_en),
                'main_group' => $label($product->itemMainGroup, 'item_main_group'),
                'catalog_group' => $locale === 'en' ? ($product->catalog_group_name_en ?: $product->catalog_group_name_hu) : ($product->catalog_group_name_hu ?: $product->catalog_group_name_en),
                'net_price' => $net, 'retail_price' => $retail, 'delivery_date' => $delivery, 'catalog_page' => $product->catalog_page];
        }

        return ['sections' => array_values($sections), 'locale' => $locale, 'captions' => $captions,
            'season' => $label($season, 'season'), 'season_code' => $season->code, 'currency' => $list->currency?->code,
            'price_list_code' => $list->code, 'retail_price_list_code' => $configuration['retail_price_list_id'] ? PriceList::findOrFail($configuration['retail_price_list_id'])->code : null,
            'show_retail' => $configuration['retail_price_list_id'] !== null, 'net_label' => $list->type === 'distributor' ? $captions['net_distributor'] : $captions['net_wholesale'],
            'missing_prices' => $missing, 'product_count' => $products->count()];
    }
}

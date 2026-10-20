<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\ItemMainGroup;
use App\Models\OrderSheetType;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\RoundingRule;
use App\Models\Season;
use App\Models\SizeRange;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProductBulkUpdateService
{
    /** Only fields exposed by ProductForm, never IDs, timestamps or internal pairing flags. */
    public static function fields(): array
    {
        return [
            'season_id' => ['label' => 'Szezon', 'type' => 'relation', 'model' => Season::class, 'required' => true],
            'item_main_group_id' => ['label' => 'Főcsoport', 'type' => 'relation', 'model' => ItemMainGroup::class, 'required' => true],
            'size_range_id' => ['label' => 'Méretsor', 'type' => 'relation', 'model' => SizeRange::class, 'required' => true],
            'model_code' => ['label' => 'Modellkód', 'type' => 'text', 'required' => true],
            'name_hu' => ['label' => 'Név HU', 'type' => 'text', 'required' => true],
            'name_en' => ['label' => 'Név EN', 'type' => 'text'],
            'material_composition' => ['label' => 'Anyagösszetétel', 'type' => 'textarea'],
            'brand_id' => ['label' => 'Márka', 'type' => 'relation', 'model' => Brand::class, 'required' => true],
            'order_sheet_type_id' => ['label' => 'Rendelőlap típusa', 'type' => 'relation', 'model' => OrderSheetType::class, 'required' => true],
            'rounding_rule_id' => ['label' => 'Gyártási kerekítés', 'type' => 'relation', 'model' => RoundingRule::class],
            'promised_delivery_date' => ['label' => 'Ígért szállítási határidő', 'type' => 'date'],
            'catalog_group_name_hu' => ['label' => 'Katalóguscsoport HU', 'type' => 'text'],
            'catalog_group_name_en' => ['label' => 'Katalóguscsoport EN', 'type' => 'text'],
            'catalog_group_sort' => ['label' => 'Katalóguscsoport sorrend', 'type' => 'integer', 'required' => true],
            'catalog_sort' => ['label' => 'Katalógus sorrend', 'type' => 'integer', 'unsigned' => true],
            'catalog_page' => ['label' => 'Katalógus oldal', 'type' => 'integer'],
            'active' => ['label' => 'Aktív', 'type' => 'boolean', 'required' => true],
        ];
    }

    public static function options(string $field): array
    {
        $definition = self::fields()[$field] ?? [];
        if (($definition['type'] ?? null) !== 'relation') {
            return [];
        }

        return $definition['model']::query()->get()->mapWithKeys(function ($record): array {
            $name = method_exists($record, 'translate') ? $record->translate('name') : ($record->name ?? $record->code);
            $label = $record->code && $record->code !== $name ? $record->code.' — '.$name : $name;

            return [$record->getKey() => $label];
        })->sort()->all();
    }

    public static function valueRules(string $field): array
    {
        $definition = self::fields()[$field];
        $rules = [($definition['required'] ?? false) ? 'required' : 'nullable'];
        if ($definition['type'] === 'relation') {
            $model = new $definition['model'];

            return [...$rules, 'integer', Rule::exists($model->getTable(), $model->getKeyName())];
        }

        return [...$rules, ...match ($definition['type']) {
            'boolean' => ['boolean'],
            'date' => ['date_format:Y-m-d'],
            'integer' => ['integer', ($definition['unsigned'] ?? false) ? 'min:0' : 'min:-2147483648',
                ($definition['unsigned'] ?? false) ? 'max:4294967295' : 'max:2147483647'],
            'textarea' => ['string', 'max:16000'],
            default => ['string', 'max:255'],
        }];
    }

    public function values(array $changes): array
    {
        $changes = array_values($changes);
        Validator::make(['changes' => $changes], [
            'changes' => ['required', 'array', 'min:1', 'max:'.count(self::fields())],
            'changes.*' => ['array:field,value'],
            'changes.*.field' => ['required', 'string', 'distinct', Rule::in(array_keys(self::fields()))],
        ])->validate();
        $values = [];
        foreach ($changes as $key => $change) {
            $field = $change['field'];
            $value = $change['value'] ?? null;
            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }
            Validator::make(['changes' => [$key => ['value' => $value]]],
                ['changes.'.$key.'.value' => self::valueRules($field)], [],
                ['changes.'.$key.'.value' => self::fields()[$field]['label']])->validate();
            $values[$field] = $value;
        }

        return $values;
    }

    public static function selectionToken(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        return hash_hmac('sha256', 'product-bulk:'.auth()->id().':'.implode(',', $ids), (string) config('app.key'));
    }

    public function update(array $productIds, array $changes, ?string $selectionToken = null): array
    {
        Gate::authorize('viewAny', Product::class);
        Gate::authorize('update', new Product);
        $values = $this->values($changes);
        Validator::make(['ids' => $productIds], ['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['required', 'integer', 'min:1']])->validate();
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($selectionToken !== null && ! hash_equals(self::selectionToken($ids), $selectionToken)) {
            throw new DomainException('A kijelölés az ablak megnyitása óta megváltozott. Nyisd meg újra a módosítást; semmit sem mentettünk.');
        }

        return DB::transaction(function () use ($ids, $values): array {
            $affectsPricing = array_intersect(array_keys($values), ['season_id', 'item_main_group_id', 'material_composition']) !== [];
            // Follow pricing writers' lock order: projects, products, then rows.
            // In particular, publication cannot close a project between checking
            // its state and marking its working calculations for review.
            $projectIds = $affectsPricing ? DB::table('pricing_project_products')->whereIn('product_id', $ids)->pluck('pricing_project_id')
                ->merge(PricingCalculationRow::whereIn('product_id', $ids)->pluck('pricing_project_id'))->unique()->all() : [];
            $projects = PricingProject::whereKey($projectIds)->orderBy('id')->lockForUpdate()->get();
            $products = Product::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($products->count() !== count($ids)) {
                throw new DomainException('A kijelölt termékek egyike már nem található. Frissítsd a listát; semmit sem módosítottunk.');
            }
            if ($affectsPricing) {
                // Current reads also see a concurrent assignment under MySQL
                // REPEATABLE READ. Never acquire a new project lock out of order.
                $currentIds = DB::table('pricing_project_products')->whereIn('product_id', $ids)->orderBy('id')->lockForUpdate()->pluck('pricing_project_id')
                    ->merge(PricingCalculationRow::whereIn('product_id', $ids)->orderBy('id')->lockForUpdate()->pluck('pricing_project_id'))->unique();
                if ($currentIds->diff($projects->modelKeys())->isNotEmpty()) {
                    throw new DomainException('Az árprojekt-hozzárendelés közben megváltozott. Frissítsd a listát; semmit sem módosítottunk.');
                }
            }
            foreach ($products as $product) {
                Gate::authorize('update', $product);
                $product->fill($values);
                if ($product->isDirty('season_id') && $product->pricingProjects()->where('season_id', '!=', $product->season_id)->exists()) {
                    throw new DomainException($product->model_code.': a szezon nem egyezik a hozzá rendelt árprojekttel. Előbb rendezd az árprojekt-hozzárendelést.');
                }
            }
            // Prevent duplicate identities introduced by the batch, including
            // two selected records being changed to the same season/model code.
            $identities = $products->countBy(fn ($product) => $product->season_id.':'.mb_strtolower($product->model_code));
            foreach ($products as $product) {
                $identity = $product->season_id.':'.mb_strtolower($product->model_code);
                if ($product->isDirty(['season_id', 'model_code']) && ($identities[$identity] > 1
                    || Product::where('season_id', $product->season_id)->where('model_code', $product->model_code)->whereNotIn('id', $ids)->exists())) {
                    throw new DomainException($product->model_code.': a módosítás azonos szezonban ismétlődő modellkódot eredményezne. Semmit sem módosítottunk.');
                }
            }
            $updated = 0;
            $pricingInputsChanged = [];
            foreach ($products as $product) {
                if (! $product->isDirty()) {
                    continue;
                }
                if ($product->isDirty(['item_main_group_id', 'material_composition', 'season_id'])) {
                    $pricingInputsChanged[] = $product->id;
                }
                if (! $product->save()) {
                    throw new DomainException('Az egyik termék mentése nem sikerült. Egyetlen módosítást sem mentettünk.');
                }
                $updated++;
            }
            // These fields change parameter resolution. Never silently leave
            // approved working prices current; published/closed prices stay intact.
            $marked = $pricingInputsChanged === [] ? 0 : PricingCalculationRow::whereIn('product_id', $pricingInputsChanged)
                ->whereIn('pricing_project_id', $projects->filter(fn ($project) => $project->isOpenForPricing())->modelKeys())
                ->where('requires_review', false)->update(['requires_review' => true]);

            return ['selected' => count($ids), 'updated' => $updated, 'unchanged' => count($ids) - $updated, 'marked_for_review' => $marked];
        }, 3);
    }
}

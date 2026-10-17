<?php

namespace App\Filament\Support;

use App\Models\Brand;
use App\Models\ItemMainGroup;
use App\Models\Product;
use App\Models\Supplier;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AdminTableFilters
{
    public static function configure(Table $table, string $modelClass): void
    {
        $model = new $modelClass;
        $existing = $table->getFilters();
        $filters = [];
        $path = self::productPath($model);
        if ($path !== null) {
            foreach (['item_main_group_id' => ['Főcsoport', ItemMainGroup::class, 'name_hu'],
                'brand_id' => ['Márka', Brand::class, 'name']] as $field => [$label, $class, $name]) {
                $aliases = $field === 'brand_id' ? ['brand_id', 'brand'] : ['item_main_group_id', 'itemMainGroup', 'item_main_group'];
                foreach ($aliases as $alias) {
                    if (($existing[$alias] ?? null) instanceof SelectFilter) {
                        $existing[$alias]->multiple()->searchable();

                        continue 2;
                    }
                }
                $filters[] = SelectFilter::make('common_'.$field)->label($label)->multiple()->searchable()
                    ->options(fn () => $class::query()->orderBy($name)->pluck($name, 'id')->all())
                    ->query(fn (Builder $query, array $data) => self::productFilter($query, $path, $field, $data['values'] ?? [], $model->isFillable($field)));
            }
            $filters[] = SelectFilter::make('common_catalog_group')->label('Katalóguscsoport')->multiple()->searchable()
                ->options(fn () => Product::query()->whereNotNull('catalog_group_name_hu')->where('catalog_group_name_hu', '!=', '')
                    ->distinct()->orderBy('catalog_group_name_hu')->pluck('catalog_group_name_hu', 'catalog_group_name_hu')->all())
                ->query(fn (Builder $query, array $data) => self::productFilter($query, $path, 'catalog_group_name_hu', $data['values'] ?? []));
        }
        $supplierPath = $model->isFillable('supplier_id') ? '' : ($path !== null ? ($path === '' ? 'purchasePrices' : $path.'.purchasePrices') : null);
        if ($supplierPath !== null && ! isset($existing['supplier_id'])) {
            $filters[] = SelectFilter::make('common_supplier')->label('Beszállító')->multiple()->searchable()
                ->options(fn () => Supplier::query()->orderBy('name')->pluck('name', 'supplier_id')->all())
                ->query(function (Builder $query, array $data) use ($supplierPath): Builder {
                    $values = $data['values'] ?? [];
                    if (! $values) {
                        return $query;
                    }

                    return $supplierPath === '' ? $query->whereIn('supplier_id', $values)
                        : $query->whereHas($supplierPath, fn ($related) => $related->whereIn('supplier_id', $values)->where('active', true));
                });
        }
        if ($model->isFillable('status') && ! isset($existing['status'])) {
            $labels = ['calculated' => 'Kalkulált ár', 'reviewed' => 'Átnézett', 'approved' => 'Jóváhagyott',
                'modified_approved' => 'Módosított jóváhagyott', 'approved_round_2' => '2. körös jóváhagyott',
                'approved_round_3' => '3. körös jóváhagyott', 'draft' => 'Tervezet', 'active' => 'Aktív', 'closed' => 'Lezárt'];
            $filters[] = SelectFilter::make('common_status')->label('Státusz')->multiple()
                ->options(fn () => $modelClass::query()->whereNotNull('status')->distinct()->pluck('status')
                    ->mapWithKeys(fn ($status) => [$status => $labels[$status] ?? $status])->all())
                ->query(fn (Builder $query, array $data) => empty($data['values']) ? $query : $query->whereIn('status', $data['values']));
        }
        if (($existing['status'] ?? null) instanceof SelectFilter) {
            $existing['status']->multiple();
        }
        foreach (['active' => 'Aktív', 'requires_review' => 'Felülvizsgálandó'] as $field => $label) {
            if ($model->isFillable($field) && ! isset($existing[$field])) {
                $filters[] = TernaryFilter::make($field)->label($label);
            }
        }
        $table->pushFilters($filters)->filtersFormMaxHeight('70vh');
    }

    private static function productPath(Model $model): ?string
    {
        if ($model instanceof Product) {
            return '';
        }
        if (method_exists($model, 'product')) {
            return 'product';
        }
        foreach (['color', 'sku', 'items'] as $relation) {
            if (method_exists($model, $relation) && method_exists($model->{$relation}()->getRelated(), 'product')) {
                return $relation.'.product';
            }
        }
        if (method_exists($model, 'products')) {
            return 'products';
        }

        return null;
    }

    private static function productFilter(Builder $query, string $path, string $field, array $values, bool $direct = false): Builder
    {
        if (! $values) {
            return $query;
        }

        return $path === '' ? $query->whereIn($field, $values)
            : $query->where(function ($q) use ($path, $field, $values, $direct): void {
                $q->whereHas($path, fn ($product) => $product->whereIn($field, $values));
                if ($direct) {
                    $q->orWhereIn($field, $values);
                }
            });
    }
}

<?php

namespace App\Services\Pricing;

use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PricingProjectProductService
{
    public const REMOVE_WARNING = 'A kijelölt termékek kikerülnek az árprojektből, és minden hozzájuk tartozó kalkuláció törlődik (minden szín, árlista és pénznem). A már publikált árlisták változatlanul megmaradnak. A terméktörzs, a beszerzési árak és a paraméterek nem törlődnek. Újraszámításkor a kivett termékek nem kerülnek vissza automatikusan.';

    public static function authorize(PricingProject $project): void
    {
        Gate::authorize('viewAny', PricingProject::class);
        Gate::authorize('view', $project);
        Gate::authorize('update', $project);
        Gate::authorize('viewAny', Product::class);
        if (! $project->isOpenForPricing()) {
            throw new DomainException('Lezárt árprojekt termékei nem módosíthatók.');
        }
    }

    public function candidates(PricingProject $project): Builder
    {
        return Product::query()->where('season_id', $project->season_id)->whereDoesntHave('pricingProjects');
    }

    public function add(PricingProject $project, array $productIds): int
    {
        return DB::transaction(function () use ($project, $productIds): int {
            $project = PricingProject::lockForUpdate()->findOrFail($project->id);
            self::authorize($project);
            $ids = array_values(array_unique($productIds));
            if ($ids === []) {
                throw new DomainException('Jelölj ki legalább egy terméket.');
            }
            // Every assignment writer locks the product, so two projects cannot claim the same candidate.
            $products = Product::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($products->count() !== count($ids)) {
                throw new DomainException('A kijelölt termékek egyike már nem található. Frissítsd a listát.');
            }
            foreach ($products as $product) {
                Gate::authorize('view', $product);
                // A locking read sees a concurrent winner even under MySQL REPEATABLE READ.
                // Do not join/lock the other project here: that would invert our lock order.
                $assignment = DB::table('pricing_project_products')->where('product_id', $product->id)->lockForUpdate()->first();
                if ($product->season_id != $project->season_id || $assignment !== null) {
                    throw new DomainException($product->model_code.': eltérő szezon vagy már egy árprojekthez tartozik. Egyetlen terméket sem vettünk fel.');
                }
            }
            $project->products()->attach($products->modelKeys());

            return $products->count();
        });
    }

    public function remove(PricingProject $project, array $productIds): int
    {
        return DB::transaction(function () use ($project, $productIds): int {
            $project = PricingProject::lockForUpdate()->findOrFail($project->id);
            self::authorize($project);
            Gate::authorize('deleteAny', PricingCalculationRow::class);
            $ids = array_values(array_unique($productIds));
            $products = Product::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($products->count() !== count($ids) || $project->products()->whereKey($ids)->count() !== count($ids)) {
                throw new DomainException('A kijelölés megváltozott. Frissítsd a listát.');
            }
            foreach ($products as $product) {
                Gate::authorize('view', $product);
            }
            $rows = $project->calculationRows()->whereIn('product_id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($rows as $row) {
                $row->setRelation('pricingProject', $project);
                Gate::authorize('delete', $row);
            }
            foreach ($rows as $row) {
                abort_unless($row->delete(), 409, 'Nem törölhető minden kalkuláció. Semmit sem módosítottunk.');
            }
            $project->products()->detach($ids);

            return $products->count();
        });
    }
}

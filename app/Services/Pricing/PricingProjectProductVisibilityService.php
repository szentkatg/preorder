<?php

namespace App\Services\Pricing;

use App\Models\PricingProject;
use App\Models\Product;
use App\Services\ProductBulkUpdateService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class PricingProjectProductVisibilityService
{
    public static function canChange(PricingProject $project): bool
    {
        return Gate::allows('view', $project) && Gate::allows('viewAny', Product::class)
            && Gate::allows('update', new Product);
    }

    private function authorize(PricingProject $project): void
    {
        Gate::authorize('view', $project);
        Gate::authorize('viewAny', Product::class);
        Gate::authorize('update', new Product);
    }

    /** Visibility is master data, so this operation deliberately allows closed projects. */
    public function updateSelected(PricingProject $project, array $productIds, bool $visible, ?string $selectionToken = null): array
    {
        $this->authorize($project);
        Validator::make(['ids' => $productIds], ['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['required', 'integer', 'min:1']])->validate();
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($selectionToken !== null && ! hash_equals(ProductBulkUpdateService::selectionToken($ids), $selectionToken)) {
            throw new DomainException('A kijelölés megváltozott. Nyisd meg újra a műveletet; semmit sem módosítottunk.');
        }

        return DB::transaction(function () use ($project, $ids, $visible): array {
            // Same lock order as publication and project membership changes.
            $project = PricingProject::lockForUpdate()->findOrFail($project->id);
            $this->authorize($project);
            $products = Product::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            $members = DB::table('pricing_project_products')->where('pricing_project_id', $project->id)->whereIn('product_id', $ids)
                ->orderBy('product_id')->lockForUpdate()->pluck('product_id')->all();
            if ($products->count() !== count($ids) || count($members) !== count($ids)) {
                throw new DomainException('A kijelölt termékek egyike már nem az árprojekthez tartozik. Frissítsd a listát; semmit sem módosítottunk.');
            }
            foreach ($products as $product) {
                Gate::authorize('update', $product);
            }
            $updated = 0;
            foreach ($products as $product) {
                $product->visible_to_partners = $visible;
                if ($product->isDirty('visible_to_partners')) {
                    if (! $product->save()) {
                        throw new DomainException('Egy termék láthatósága nem menthető. Semmit sem módosítottunk.');
                    }
                    $updated++;
                }
            }

            return ['selected' => count($ids), 'updated' => $updated, 'unchanged' => count($ids) - $updated];
        });
    }

    public function makeAllVisible(PricingProject $project): array
    {
        $this->authorize($project);

        return DB::transaction(function () use ($project): array {
            $project = PricingProject::lockForUpdate()->findOrFail($project->id);
            $this->authorize($project);
            $ids = $project->products()->orderBy('products.id')->pluck('products.id')->all();

            return $ids === [] ? ['selected' => 0, 'updated' => 0, 'unchanged' => 0] : $this->updateSelected($project, $ids, true);
        });
    }
}

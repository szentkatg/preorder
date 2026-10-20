<?php

namespace App\Services\Pricing;

use App\Models\Currency;
use App\Models\PricingCalculationError;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class PricingProjectCalculationService
{
    public static function canCalculate(PricingProject $project): bool
    {
        return $project->isOpenForPricing() && Gate::allows('viewAny', PricingCalculationRow::class)
            && Gate::allows('update', $project) && Gate::allows('create', PricingCalculationRow::class)
            && Gate::allows('update', new PricingCalculationRow);
    }

    public function calculate(PricingProject $project, array $sources = []): array
    {
        Gate::authorize('viewAny', PricingCalculationRow::class);
        Gate::authorize('update', $project);
        Gate::authorize('create', PricingCalculationRow::class);
        Gate::authorize('update', new PricingCalculationRow);

        return DB::transaction(function () use ($project, $sources): array {
            $project = PricingProject::lockForUpdate()->findOrFail($project->id);
            if (! $project->isOpenForPricing()) {
                throw new DomainException('Lezárt árprojekt nem számítható újra.');
            }
            if (! Currency::where('code', 'HUF')->exists()) {
                throw new DomainException('A bekerülési érték számításához hiányzik a HUF pénznem.');
            }
            $resolver = app(ForeignRetailPriceListResolver::class);
            $targets = $resolver->targets()->keyBy('id');
            $huf = $resolver->sources()->keyBy('id');
            foreach ($sources as $target => $source) {
                if (! isset($targets[$target]) || ! is_scalar($source) || ! ctype_digit((string) $source) || ! isset($huf[$source])) {
                    throw new DomainException('Érvénytelen HUF/devizás árlistapárosítás. Nyisd meg újra a számítást.');
                }
            }
            $result = ['run_id' => (string) Str::uuid(), 'products' => 0, 'prices' => 0, 'failed' => 0, 'requires_review' => 0, 'errors' => []];
            Product::forPricingProject($project->id)->where('season_id', $project->season_id)->where('active', true)->orderBy('id')->chunkById(100,
                function ($products) use ($project, $sources, &$result): void {
                    foreach ($products as $product) {
                        try {
                            // Each model is atomic: a broken dependency cannot leave a half-calculated model.
                            $preview = app(ProductPricingService::class)->recalculate($project, $product, $sources);
                            $result['products']++;
                            $result['prices'] += count($preview['costs']) + count($preview['prices']);
                        } catch (DomainException $exception) {
                            $result['failed']++;
                            $message = $product->model_code.': '.$exception->getMessage();
                            $result['errors'][] = $message;
                            PricingCalculationRow::where('pricing_project_id', $project->id)->where('product_id', $product->id)
                                ->update(['requires_review' => true]);
                            PricingCalculationError::create(['run_id' => $result['run_id'], 'pricing_project_id' => $project->id,
                                'product_id' => $product->id, 'price_type' => PricingCalculationError::TYPE_PROJECT, 'severity' => PricingCalculationError::SEVERITY_ERROR,
                                'message' => $message, 'context' => ['operation' => 'calculate_all_prices']]);
                        }
                    }
                });
            $result['requires_review'] = PricingCalculationRow::where('pricing_project_id', $project->id)
                ->where('requires_review', true)->whereHas('product', fn ($query) => $query->forPricingProject($project->id)->where('active', true)->where('season_id', $project->season_id))->count();

            return $result;
        });
    }
}

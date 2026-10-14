<?php

namespace App\Services\Pricing;

use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use Illuminate\Support\Facades\Gate;

class CostCalculationAuthorization
{
    public static function canRecalculate(PricingProject $project): bool
    {
        return $project->isOpenForPricing() && Gate::allows('update', $project)
            && Gate::allows('create', PricingCalculationRow::class)
            && Gate::allows('update', new PricingCalculationRow);
    }

    public static function authorizeRecalculation(PricingProject $project): void
    {
        Gate::authorize('update', $project);
        Gate::authorize('create', PricingCalculationRow::class);
        Gate::authorize('update', new PricingCalculationRow);
        abort_unless($project->isOpenForPricing(), 403, 'Lezárt árprojekt nem számítható újra.');
    }

    public static function canAdjustParameter(PricingCalculationRow $row): bool
    {
        return $row->price_type === PricingCalculationRow::TYPE_COST
            && $row->pricingProject
            && Gate::allows('update', $row)
            && self::canRecalculate($row->pricingProject)
            && Gate::allows('create', PricingParameter::class)
            && Gate::allows('update', new PricingParameter);
    }

    public static function authorizeParameterAdjustment(PricingCalculationRow $row): void
    {
        Gate::authorize('update', $row);
        abort_unless($row->price_type === PricingCalculationRow::TYPE_COST && $row->pricingProject, 403);
        self::authorizeRecalculation($row->pricingProject);
        Gate::authorize('create', PricingParameter::class);
        Gate::authorize('update', new PricingParameter);
    }
}

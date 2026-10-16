<?php

namespace App\Models\Concerns;

use App\Models\PricingProject;
use DomainException;
use Illuminate\Support\Facades\DB;

trait ProtectsClosedPricingProjectDeletion
{
    public function delete()
    {
        if (! $this->exists) {
            return parent::delete();
        }

        return DB::transaction(function () {
            $project = PricingProject::whereKey($this->pricing_project_id)->lockForUpdate()->first();
            if (! $project?->isOpenForPricing()) {
                throw new DomainException('Lezárt árprojekt rekordja nem törölhető.');
            }

            return parent::delete();
        });
    }
}

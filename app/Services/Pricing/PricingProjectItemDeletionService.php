<?php

namespace App\Services\Pricing;

use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PricingProjectItemDeletionService
{
    public const WARNING = 'A kiválasztott modell–szín tételek összes kalkulációs sora törlődik ebből az árprojektből, minden árlistán és pénznemben (bekerülési érték, kisker-, outlet-, nagyker- és disztribútori ár). A már publikált árlisták ettől változatlanul megmaradnak. A terméktörzs és a beszerzési árak nem törlődnek; egy későbbi újraszámítás ismét létrehozhatja a kalkulációkat. A művelet nem vonható vissza.';

    /** @param array<int, int|string> $rowIds */
    public function delete(PricingProject $project, array $rowIds, bool $bulk = false): int
    {
        return DB::transaction(function () use ($project, $rowIds, $bulk): int {
            $project = PricingProject::lockForUpdate()->findOrFail($project->id);
            Gate::authorize('update', $project);
            abort_unless($project->isOpenForPricing(), 403, 'Lezárt árprojekt tételei nem törölhetők.');
            if ($bulk) {
                Gate::authorize('deleteAny', PricingCalculationRow::class);
            }

            $ids = array_values(array_unique($rowIds));
            if ($ids === []) {
                return 0;
            }
            $selected = $project->calculationRows()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($selected->count() === count($ids), 404, 'A kijelölt sorok megváltoztak vagy más árprojekthez tartoznak.');
            $groups = $selected->unique(fn ($row) => $row->product_id.':'.$row->color_key);
            $rows = $project->calculationRows()->where(function (Builder $query) use ($groups): void {
                foreach ($groups as $group) {
                    $query->orWhere(fn (Builder $query) => $query->where('product_id', $group->product_id)->where('color_key', $group->color_key));
                }
            })->orderBy('id')->lockForUpdate()->get();

            // Check the entire expanded selection before the first destructive write.
            foreach ($rows as $row) {
                $row->setRelation('pricingProject', $project);
                Gate::authorize('delete', $row);
            }
            foreach ($rows as $row) {
                abort_unless($row->delete(), 409, 'A tétel nem törölhető; egyetlen módosítást sem mentettünk.');
            }

            // Deliberately never write price_list_items or publication snapshots.
            return $rows->count();
        });
    }
}

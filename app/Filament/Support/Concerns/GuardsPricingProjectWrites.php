<?php

namespace App\Filament\Support\Concerns;

use App\Exceptions\PricingParameterConflictException;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait GuardsPricingProjectWrites
{
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $project = PricingProject::lockForUpdate()->findOrFail($data['pricing_project_id'] ?? 0);
            abort_unless($project->isOpenForPricing(), 403, 'Lezárt árprojekthez nem hozható létre új rekord.');
            if ($this->getModel() === PricingCalculationRow::class) {
                $this->validateProjectProduct($project, $data['product_id'] ?? null);
            }

            try {
                return parent::handleRecordCreation($data);
            } catch (PricingParameterConflictException $exception) {
                throw ValidationException::withMessages(['data.value' => $exception->getMessage()]);
            }
        });
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $ids = array_unique([$record->pricing_project_id, $data['pricing_project_id'] ?? $record->pricing_project_id]);
            $projects = PricingProject::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($projects->count() === count($ids) && $projects->every(fn ($project) => $project->isOpenForPricing()), 403, 'Lezárt árprojekt adatai nem módosíthatók.');
            $stored = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            abort_unless($stored->pricing_project_id == $record->pricing_project_id, 409, 'A rekord időközben megváltozott; frissítsd az oldalt.');
            if ($stored instanceof PricingCalculationRow) {
                $this->validateProjectProduct($projects->firstWhere('id', $data['pricing_project_id'] ?? $stored->pricing_project_id),
                    $data['product_id'] ?? $stored->product_id);
            }

            try {
                return parent::handleRecordUpdate($stored, $data);
            } catch (PricingParameterConflictException $exception) {
                throw ValidationException::withMessages(['data.value' => $exception->getMessage()]);
            }
        });
    }

    private function validateProjectProduct(PricingProject $project, mixed $productId): void
    {
        if (! $project->products()->whereKey($productId)->where('season_id', $project->season_id)->exists()) {
            throw ValidationException::withMessages(['data.product_id' => 'Előbb vedd fel a terméket az árprojektbe.']);
        }
    }
}

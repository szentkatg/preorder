<?php

namespace App\Filament\Support\Concerns;

use App\Models\PricingProject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

trait GuardsPricingProjectWrites
{
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $project = PricingProject::lockForUpdate()->findOrFail($data['pricing_project_id'] ?? 0);
            abort_unless($project->isOpenForPricing(), 403, 'Lezárt árprojekthez nem hozható létre új rekord.');

            return parent::handleRecordCreation($data);
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

            return parent::handleRecordUpdate($stored, $data);
        });
    }
}

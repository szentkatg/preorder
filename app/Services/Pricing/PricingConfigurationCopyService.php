<?php

namespace App\Services\Pricing;

use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\PricingRoundingRule;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PricingConfigurationCopyService
{
    public function canCopy(string $model): bool
    {
        $this->assertModel($model);

        return Gate::allows('viewAny', $model) && Gate::allows('create', $model)
            && Gate::allows('viewAny', PricingProject::class);
    }

    public function targetOptions(string $model, Collection $selected): array
    {
        if (! $this->canCopy($model)) {
            return [];
        }

        return PricingProject::openForPricing()->whereNotIn('id', $selected->pluck('pricing_project_id')->unique())
            ->with('season')->orderBy('name')->get()
            ->filter(fn ($project) => Gate::allows('view', $project) && Gate::allows('update', $project))
            ->mapWithKeys(fn ($project) => [$project->id => $project->name.' — '.$project->season?->name])->all();
    }

    public function preview(string $model, array $ids, int $targetId): array
    {
        $plan = $this->plan($model, $ids, $targetId);
        $conflicts = collect($plan['operations'])->filter(fn ($operation) => $operation['existing']->isNotEmpty());
        $ambiguous = $conflicts->filter(fn ($operation) => $operation['existing']->count() > 1)->count();

        return ['selected' => count($plan['operations']), 'new' => count($plan['operations']) - $conflicts->count(),
            'conflicts' => $conflicts->count(), 'ambiguous' => $ambiguous,
            'can_overwrite' => $ambiguous === 0 && $conflicts->every(fn ($operation) => $operation['existing']->every(fn ($row) => Gate::allows('update', $row))),
            'token' => $this->fingerprint($plan)];
    }

    public function copy(string $model, array $ids, int $targetId, string $token, ?string $mode = null): array
    {
        return DB::transaction(function () use ($model, $ids, $targetId, $token, $mode): array {
            $plan = $this->plan($model, $ids, $targetId, lock: true);
            if (! hash_equals($this->fingerprint($plan), $token)) {
                throw new DomainException('A forrás vagy a célprojekt adatai megváltoztak. Frissítsd az előnézetet, és erősítsd meg újra a másolást.');
            }
            if (! in_array($mode, [null, 'skip', 'overwrite'], true)) {
                throw new DomainException('Érvénytelen ütközéskezelési mód.');
            }
            foreach ($plan['operations'] as $operation) {
                if ($operation['existing']->isEmpty()) {
                    continue;
                }
                if ($mode === null) {
                    throw new DomainException('Ütközés van: válassz felülírást vagy a meglévő sorok kihagyását.');
                }
                if ($mode === 'overwrite') {
                    if ($operation['existing']->count() > 1) {
                        throw new DomainException('A célprojektben több azonos számítási kulcsú sor van. Ezek csak kihagyhatók; felülírás előtt szüntesd meg a duplikációt.');
                    }
                    Gate::authorize('update', $operation['existing']->first());
                }
            }

            $prices = PricingCalculationRow::where('pricing_project_id', $targetId)->where('price_type', '!=', PricingCalculationRow::TYPE_COST);
            $before = (clone $prices)->where('requires_review', true)->count();
            $result = ['created' => 0, 'overwritten' => 0, 'skipped' => 0, 'unchanged' => 0, 'marked_for_review' => 0];
            $affectsPrices = false;
            foreach ($plan['operations'] as $operation) {
                $existing = $operation['existing']->first();
                if ($existing && $mode === 'skip') {
                    $result['skipped']++;

                    continue;
                }
                $row = $existing ?? new $model;
                $wasActive = (bool) $row->active;
                $row->fill($operation['attributes']);
                if ($existing && ! $row->isDirty()) {
                    $result['unchanged']++;

                    continue;
                }
                $calculationChanged = $row->isDirty(array_diff(array_keys($operation['attributes']), ['name']));
                $affectsPrices = $affectsPrices || ($calculationChanged && ($wasActive || $row->active));
                if (! $row->save()) {
                    throw new DomainException('Egy sor nem menthető. Egyetlen másolást sem véglegesítettünk.');
                }
                $result[$existing ? 'overwritten' : 'created']++;
            }
            if ($affectsPrices) {
                // Conservative invalidation only: never recalculate, approve or overwrite prices.
                (clone $prices)->where('requires_review', false)->update(['requires_review' => true]);
            }
            $result['marked_for_review'] = (clone $prices)->where('requires_review', true)->count() - $before;

            return $result;
        });
    }

    private function plan(string $model, array $ids, int $targetId, bool $lock = false): array
    {
        $this->assertModel($model);
        Gate::authorize('viewAny', $model);
        Gate::authorize('create', $model);
        Gate::authorize('viewAny', PricingProject::class);
        foreach ($ids as $id) {
            if (! is_scalar($id) || ! ctype_digit((string) $id) || (int) $id <= 0) {
                throw new DomainException('Érvénytelen forráskijelölés.');
            }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw new DomainException('Jelölj ki legalább egy másolandó sort.');
        }
        $sources = $model::whereKey($ids)->orderBy('id')->get();
        $projectIds = $sources->pluck('pricing_project_id')->push($targetId)->unique()->sort()->values();
        $projects = PricingProject::whereKey($projectIds)->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $target = $projects->get($targetId);
        abort_unless($target, 404);
        Gate::authorize('view', $target);
        Gate::authorize('update', $target);
        if (! $target->isOpenForPricing()) {
            throw new DomainException('Lezárt árprojektbe nem másolható adat.');
        }
        $sources = $model::whereKey($ids)->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($sources->count() !== count($ids)) {
            throw new DomainException('A kijelölt forrássorok egy része már nem létezik. Frissítsd a listát.');
        }
        foreach ($sources as $source) {
            Gate::authorize('view', $source);
            $sourceProject = $projects->get($source->pricing_project_id);
            if (! $sourceProject || $source->pricing_project_id == $targetId) {
                throw new DomainException('Másik célárprojektet válassz. Ha a forrás megváltozott, frissítsd az előnézetet.');
            }
            Gate::authorize('view', $sourceProject);
        }
        $targetRows = $model::where('pricing_project_id', $targetId)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $targets = $model === PricingParameter::class ? null : $targetRows->groupBy(fn ($row) => $this->key($row));
        $seen = [];
        $operations = [];
        foreach ($sources as $source) {
            $key = $this->key($source);
            $duplicateSource = $source instanceof PricingParameter
                ? $source->matchingParametersQuery(withinProject: false)->whereKey($sources->modelKeys())->whereKeyNot($source->id)
                    ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->first() !== null
                : isset($seen[$key]);
            if ($duplicateSource) {
                throw new DomainException('A kijelölésben több azonos számítási kulcsú forrássor van. Kulcsonként csak egy sort jelölj ki.');
            }
            $seen[$key] = true;
            $existing = $source instanceof PricingParameter
                ? $source->matchingParametersQuery(withinProject: false)->whereKey($targetRows->modelKeys())
                    ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()
                : $targets->get($key, collect());
            $operations[] = ['source' => $source, 'attributes' => ['pricing_project_id' => $targetId] + $this->attributes($source),
                'existing' => $existing];
        }

        return compact('model', 'projects', 'targetId', 'operations');
    }

    private function attributes(Model $row): array
    {
        $attributes = $row->only(array_diff($row->getFillable(), ['pricing_project_id']));
        if ($row instanceof PricingParameter) {
            if (! in_array($row->scope_type, [PricingParameter::SCOPE_GLOBAL, PricingParameter::SCOPE_SUPPLIER_COUNTRY,
                PricingParameter::SCOPE_ITEM_MAIN_GROUP, PricingParameter::SCOPE_MATERIAL_COMPOSITION, PricingParameter::SCOPE_PRODUCT], true)) {
                throw new DomainException('Ismeretlen paraméterszűkítés a forrás- vagy célsorban.');
            }
            $attributes = array_replace($attributes, $row->scopeAttributes());
        }

        return $attributes;
    }

    private function key(Model $row): string
    {
        $attributes = $this->attributes($row);
        $fields = $row instanceof PricingParameter
            ? ['parameter_type', 'scope_type', 'supplier_country_code', 'item_main_group_id', 'material_composition', 'product_id', 'price_list_id', 'currency_id']
            : ['price_type', 'price_list_id', 'currency_id'];

        return json_encode(array_map(fn ($field) => $attributes[$field] ?? null, $fields), JSON_THROW_ON_ERROR);
    }

    private function fingerprint(array $plan): string
    {
        $state = [auth()->id(), $plan['model'], $plan['targetId'], $plan['projects']->map->getAttributes()->all()];
        foreach ($plan['operations'] as $operation) {
            $state[] = [$operation['source']->getAttributes(), $operation['existing']->map->getAttributes()->all()];
        }

        return hash_hmac('sha256', json_encode($state, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function assertModel(string $model): void
    {
        if (! in_array($model, [PricingParameter::class, PricingRoundingRule::class], true)) {
            throw new DomainException('Csak árképzési paraméterek és árkerekítési szabályok másolhatók.');
        }
    }
}

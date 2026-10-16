<?php

namespace App\Filament\Support\Concerns;

use App\Models\AdminUiPreference;
use App\Models\PricingProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

trait HasPricingProjectContext
{
    #[Locked]
    public ?int $pricingProjectId = null;

    #[Locked]
    public bool $pricingArchive = false;

    protected function initialisePricingProjectContext(): void
    {
        $saved = AdminUiPreference::query()->where('user_id', auth()->id())
            ->where('preference_key', 'pricing_workspace_project_context')->value('value');
        // value() does not necessarily apply JSON model casts in all database drivers.
        if (is_string($saved)) {
            $saved = json_decode($saved, true);
        }
        $saved = is_array($saved) ? $saved : [];
        $this->pricingArchive = ($saved['archive'] ?? false) === true;
        $id = $saved['project_id'] ?? null;
        $this->pricingProjectId = (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0 ? (int) $id : null;
        $autoSelected = false;
        if (! $this->pricingSelectedProject) {
            $this->pricingProjectId = null;
            $options = $this->pricingProjectOptions;
            if ($options->count() === 1) {
                $this->pricingProjectId = (int) $options->first()->id;
                $autoSelected = true;
            }
        }
        unset($this->pricingSelectedProject);
        if ($autoSelected) {
            $this->savePricingProjectContext();
        }
    }

    #[Computed]
    public function pricingProjectOptions(): Collection
    {
        return $this->pricingContextProjectsQuery()->with('season')->orderBy('name')->orderBy('id')->get();
    }

    #[Computed]
    public function pricingSelectedProject(): ?PricingProject
    {
        return $this->pricingProjectId
            ? $this->pricingContextProjectsQuery()->with('season')->find($this->pricingProjectId) : null;
    }

    public function selectPricingProject(mixed $id): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($id === null || $id === '' || (is_scalar($id) && ctype_digit((string) $id)), 422);
        $project = filled($id) ? $this->pricingContextProjectsQuery()->find((int) $id) : null;
        abort_if(filled($id) && ! $project, 422, 'Az árprojekt ebben a nézetben nem választható.');
        $this->clearPricingContextInteractions();
        $this->pricingProjectId = $project ? (int) $project->id : null;
        $this->savePricingProjectContext();
        $this->refreshPricingContextTable();
    }

    public function setPricingArchive(bool $archive): void
    {
        abort_unless(static::canAccess(), 403);
        $this->clearPricingContextInteractions();
        $this->pricingArchive = $archive;
        $this->pricingProjectId = null;
        unset($this->pricingProjectOptions, $this->pricingSelectedProject);
        if ($this->pricingProjectOptions->count() === 1) {
            $this->pricingProjectId = (int) $this->pricingProjectOptions->first()->id;
        }
        $this->savePricingProjectContext();
        $this->refreshPricingContextTable();
    }

    public function pricingWorkspaceIsEditable(): bool
    {
        return ! $this->pricingArchive && ($this->pricingSelectedProject?->isOpenForPricing() ?? false);
    }

    protected function scopePricingProjectContext(Builder $query): Builder
    {
        return $query->where('pricing_project_id', $this->pricingProjectId ?? 0)
            ->whereHas('pricingProject', function (Builder $projectQuery): void {
                $this->pricingArchive
                    ? $projectQuery->where('status', PricingProject::STATUS_CLOSED)
                    : $projectQuery->openForPricing();
            });
    }

    private function pricingContextProjectsQuery(): Builder
    {
        return $this->pricingArchive
            ? PricingProject::query()->where('status', PricingProject::STATUS_CLOSED)
            : PricingProject::query()->openForPricing();
    }

    private function savePricingProjectContext(): void
    {
        AdminUiPreference::query()->updateOrCreate([
            'user_id' => auth()->id(), 'preference_key' => 'pricing_workspace_project_context',
        ], ['value' => ['project_id' => $this->pricingProjectId, 'archive' => $this->pricingArchive]]);
    }

    protected function clearPricingContextInteractions(): void
    {
        if ($this->mountedActions) {
            $this->unmountAction();
        }
        if (method_exists($this, 'clearPendingParameterAdjustment')) {
            $this->clearPendingParameterAdjustment();
        }
        $this->selectedTableRecords = $this->deselectedTableRecords = [];
        $this->isTrackingDeselectedTableRecords = false;
        unset($this->cachedSelectedTableRecords);
        $this->deselectAllTableRecords();
    }

    protected function refreshPricingContextTable(): void
    {
        unset($this->outletPriceLists);
        unset($this->pricingSelectedProject);
        if (method_exists($this, 'loadPricingDecimalPlaces')) {
            $this->pricingDecimalPlaces = $this->loadPricingDecimalPlaces();
        }
        if (method_exists($this, 'averageRetailMultiplier')) {
            unset($this->averageRetailMultiplier);
        }
        if (method_exists($this, 'averageCostBasedMultiplier')) {
            unset($this->averageCostBasedMultiplier);
        }
        if (method_exists($this, 'averageNetNetMultiplier')) {
            unset($this->averageNetNetMultiplier);
        }
        $this->tableFilters = [];
        $this->tableColumnSearches = [];
        $this->tableSearch = '';
        $this->resetTable();
    }
}

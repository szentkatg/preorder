<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PricingProjects\PricingProjectResource;
use App\Filament\Support\Concerns\HasPricingProjectContext;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Services\Pricing\PricingWorkflowSummary;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

class PricingWorkflow extends Page
{
    use HasPageShield { canAccess as protected canAccessUsingPagePermission; }
    use HasPricingProjectContext;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Árképzés';

    protected static ?string $navigationLabel = 'Árképzés áttekintése';

    protected static ?string $title = 'Árképzés lépésről lépésre';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.pricing-workflow';

    public static function canAccess(): bool
    {
        return static::canAccessUsingPagePermission() && Gate::allows('viewAny', PricingCalculationRow::class)
            && Gate::allows('viewAny', PricingProject::class) && Gate::allows('view', new PricingProject);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->initialisePricingProjectContext();
    }

    public static function stepDefinitions(): array
    {
        return [
            'cost' => ['title' => 'Bekerülési értékek', 'page' => PricingCostCalculations::class,
                'description' => 'Beszerzési ár, árfolyam, szállítás és vám ellenőrzése. Ez a többi ár alapja.'],
            'huf' => ['title' => 'HUF kisker és outlet árak', 'page' => PricingHufRetailCalculations::class,
                'description' => 'HUF kisker szorzó és kerekítés, kézi árak és outlet kedvezmények jóváhagyása.'],
            'foreign' => ['title' => 'Devizás kisker és outlet árak', 'page' => PricingForeignRetailCalculations::class,
                'description' => 'Devizás kisker árak a HUF kisker árból; ország-/pénznemszorzók és outlet árak. A darabszám minden pénznemet tartalmaz.'],
            'wholesale' => ['title' => 'Nagyker árak', 'page' => PricingWholesaleCalculations::class,
                'description' => 'Nettó HUF és EUR nagyker árak az azonos pénznemű bruttó kisker árak alapján.'],
            'distributor' => ['title' => 'Disztribútori árak', 'page' => PricingDistributorCalculations::class,
                'description' => 'EUR disztribútori árak a nagyker árból, kedvezménnyel és kerekítéssel.'],
            'overview' => ['title' => 'Teljes áráttekintés', 'page' => PricingProductOverview::class,
                'description' => 'Végső, csak olvasható ellenőrzés. Itt modellenként/színenként számoljuk a tételeket; bármely függő ár függővé teszi a tételt.'],
        ];
    }

    #[Computed]
    public function workflowSteps(): array
    {
        abort_unless(static::canAccess(), 403);
        $project = $this->pricingSelectedProject;
        $summary = $project ? app(PricingWorkflowSummary::class)->forProject($project) : [];
        $steps = [];
        foreach (self::stepDefinitions() as $key => $definition) {
            $canOpen = $project && $definition['page']::canAccess();
            $steps[$key] = $definition + ['counts' => $summary[$key] ?? null,
                'url' => $canOpen ? $definition['page']::getUrl() : null,
                'countUrls' => $canOpen ? collect(['total', 'approved', 'pending'])
                    ->mapWithKeys(fn ($state) => [$state => $this->pricingStepUrl($definition['page'], $state)])->all() : []];
        }

        return $steps;
    }

    public function openPricingStep(string $step, ?string $state = null): void
    {
        abort_unless(static::canAccess(), 403);
        $definition = self::stepDefinitions()[$step] ?? null;
        abort_unless($definition && $definition['page']::canAccess() && $this->pricingSelectedProject, 403);
        abort_unless($state === null || in_array($state, ['total', 'approved', 'pending'], true), 422);
        // Re-save on click as another browser tab may have changed the shared context.
        $this->selectPricingProject($this->pricingProjectId);
        $this->redirect($state === null ? $definition['page']::getUrl() : $this->pricingStepUrl($definition['page'], $state), navigate: true);
    }

    protected function pricingStepUrl(string $page, string $state): string
    {
        return $page::getUrl([
            'workflowProject' => $this->pricingProjectId,
            'workflowArchive' => $this->pricingArchive ? '1' : '0',
            'tableFilters' => ['workflow_state' => ['value' => $state === 'total' ? '' : $state]],
        ]);
    }

    public function projectDetailsUrl(): ?string
    {
        return $this->pricingSelectedProject && Gate::allows('view', $this->pricingSelectedProject)
            ? PricingProjectResource::getUrl('view', ['record' => $this->pricingProjectId]) : null;
    }

    protected function clearPricingContextInteractions(): void
    {
        if ($this->mountedActions) {
            $this->unmountAction();
        }
    }

    protected function refreshPricingContextTable(): void
    {
        unset($this->pricingSelectedProject, $this->workflowSteps);
    }
}

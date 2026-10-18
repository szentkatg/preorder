<?php

namespace App\Filament\Pages;

use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class PricingProductWorkspace extends PricingProductOverview
{
    protected static ?string $pagePermissionKey = null;

    protected static ?string $navigationLabel = 'Termékenkénti árkezelés';

    protected static ?string $title = 'Termékenkénti teljes árkezelés';

    protected static ?int $navigationSort = 35;

    public function mount(): void
    {
        parent::mount();
        if (! request()->hasAny(['project', 'product'])) {
            return;
        }
        $projectId = request()->query('project');
        $productId = request()->query('product');
        abort_unless(is_scalar($projectId) && ctype_digit((string) $projectId)
            && is_scalar($productId) && ctype_digit((string) $productId), 404);
        $project = PricingProject::findOrFail((int) $projectId);
        $this->pricingArchive = $project->status === PricingProject::STATUS_CLOSED;
        $this->pricingProjectId = $project->id;
        unset($this->pricingSelectedProject, $this->pricingProjectOptions);
        abort_unless($this->canEditProductPricing(), 403);
        $cost = $this->scopePricingProjectContext(PricingCalculationRow::query())->where('price_type', 'cost')
            ->where('product_id', (int) $productId)
            ->whereHas('product', fn ($query) => $query->where('active', true)->where('season_id', $project->season_id))->first();
        abort_unless($cost, 404);
        Gate::authorize('view', $cost);
        $this->selectPricingProject($project->id);
        // Filament opens this lazily via wire:init, after the table and action are booted.
        $this->defaultAction = 'editProductPricing';
        $this->defaultActionArguments = [];
        $this->defaultActionContext = ['table' => true, 'recordKey' => (string) $cost->id];
    }

    public function pricingWorkspaceDescription(): string
    {
        return 'Kattints egy termék sorára: egy ablakban módosíthatod a beszerzési árait, a modellszintű paramétereit és az összes függő manuális árat. Bevitel közben csak előnézet készül. A végén egy gomb ment és hagy jóvá mindent. Üres manuális ár esetén az új kalkulált árat fogadjuk el; kitöltött mezőnél a megadott árat tartjuk meg. A beszerzési ár törzsadat; a százalékok és szorzók csak erre az árprojektre és modellre érvényesek.';
    }

    protected function pricingPreferencePrefix(): string
    {
        return 'pricing_product_workspace';
    }

    public function table(Table $table): Table
    {
        return parent::table($table)->recordAction(fn () => $this->canEditProductPricing() ? 'editProductPricing' : null)
            ->recordActions([$this->makeProductPricingEditorAction('editProductPricing')->label('Teljes árkezelés')]);
    }
}

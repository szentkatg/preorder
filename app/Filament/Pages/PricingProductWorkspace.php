<?php

namespace App\Filament\Pages;

use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use App\Services\Pricing\ProductPricingService;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class PricingProductWorkspace extends PricingProductOverview
{
    protected static ?string $pagePermissionKey = null;

    protected static ?string $navigationLabel = 'Termékenkénti árkezelés';

    protected static ?string $title = 'Termékenkénti teljes árkezelés';

    protected static ?int $navigationSort = 35;

    #[Locked]
    public ?int $pricingEditorProductId = null;

    #[Locked]
    public string $pricingEditorFingerprint = '';

    #[Locked]
    public array $pricingEditorSettings = [];

    #[Locked]
    public array $pricingEditorPreview = [];

    #[Locked]
    public ?string $pricingEditorError = null;

    public array $pricingEditor = [];

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
        return parent::table($table)->recordAction(fn () => $this->canEditProductPricing() ? 'editProductPricing' : null)->recordActions([
            Action::make('editProductPricing')->label('Teljes árkezelés')->icon('heroicon-o-adjustments-horizontal')
                ->visible(fn () => $this->canEditProductPricing())
                ->authorize(fn () => $this->canEditProductPricing())
                ->modalHeading(fn () => ($this->pricingEditorSettings['product']['model_code'] ?? '').' – '.($this->pricingEditorSettings['product']['name_hu'] ?? 'Termék árkezelése'))
                ->modalWidth(Width::Screen)->modalSubmitActionLabel('Minden ár mentése és jóváhagyása')->modalCancelActionLabel('Mégse')
                ->modalSubmitAction(fn ($action) => $action->disabled(fn () => $this->pricingEditorError !== null))
                ->mountUsing(function (PricingCalculationRow $record): void {
                    Gate::authorize('view', $record);
                    $settings = app(ProductPricingService::class)->open($this->pricingSelectedProject, $record->product);
                    $this->pricingEditorProductId = $record->product_id;
                    $this->pricingEditorFingerprint = $settings['fingerprint'];
                    $this->pricingEditor = $settings['data'];
                    $this->pricingEditorPreview = $settings['initial_preview'];
                    unset($settings['data'], $settings['fingerprint'], $settings['initial_preview']);
                    $this->pricingEditorSettings = $settings;
                    $this->refreshProductPricingPreview();
                })
                ->modalContent(fn () => view('filament.pricing.product-workspace-editor', ['page' => $this]))
                ->action(function (Action $action): void {
                    abort_unless($this->canEditProductPricing() && $this->pricingEditorProductId, 403);
                    try {
                        app(ProductPricingService::class)->approve($this->pricingSelectedProject,
                            Product::findOrFail($this->pricingEditorProductId), $this->pricingEditor, $this->pricingEditorFingerprint);
                    } catch (DomainException|ValidationException $exception) {
                        $this->pricingEditorError = $exception->getMessage();
                        Notification::make()->title('Nem menthető az árkezelés')->body($exception->getMessage())->danger()->send();
                        $action->halt();
                    }
                    Notification::make()->title('A termék összes ára mentve és jóváhagyva')->success()->send();
                    $this->pricingEditorProductId = null;
                    unset($this->overviewLists);
                    $this->resetTable();
                }),
        ]);
    }

    public function canEditProductPricing(): bool
    {
        return static::canAccess() && $this->pricingWorkspaceIsEditable()
            && Gate::allows('update', $this->pricingSelectedProject) && Gate::allows('create', PricingCalculationRow::class)
            && Gate::allows('update', new PricingCalculationRow);
    }

    public function updatedPricingEditor(): void
    {
        $this->refreshProductPricingPreview();
    }

    public function refreshProductPricingPreview(): void
    {
        abort_unless($this->canEditProductPricing() && $this->pricingEditorProductId, 403);
        $this->pricingEditorError = null;
        try {
            $this->pricingEditorPreview = app(ProductPricingService::class)->preview($this->pricingSelectedProject,
                Product::findOrFail($this->pricingEditorProductId), $this->pricingEditor, $this->pricingEditorFingerprint);
            foreach ($this->pricingEditorPreview['prices'] as $price) {
                if (! array_key_exists($price['key'], $this->pricingEditor['manual'])) {
                    $this->pricingEditor['manual'][$price['key']] = null;
                }
            }
        } catch (DomainException|ValidationException $exception) {
            $this->pricingEditorError = $exception->getMessage();
        }
    }
}

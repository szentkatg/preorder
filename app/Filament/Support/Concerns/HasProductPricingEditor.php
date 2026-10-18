<?php

namespace App\Filament\Support\Concerns;

use App\Filament\Pages\PricingProductWorkspace;
use App\Filament\Support\ProductPricingNavigation;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use App\Services\Pricing\ProductPricingService;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** One lazy editor on the originating list; no navigation or table-state reset. */
trait HasProductPricingEditor
{
    #[Locked]
    public ?int $pricingEditorSourceRowId = null;

    #[Locked]
    public ?int $pricingEditorProjectId = null;

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

    public function productPricingAction(): Action
    {
        return $this->makeProductPricingEditorAction('productPricing')
            ->record(fn (array $arguments) => $this->productPricingSourceRecord($arguments['record_id'] ?? null));
    }

    protected function makeProductPricingEditorAction(string $name): Action
    {
        return Action::make($name)->label('Termékenkénti árkezelés')->icon('heroicon-o-adjustments-horizontal')
            ->visible(fn (PricingCalculationRow $record) => $this->canEditProductPricing($record))
            ->authorize(fn (PricingCalculationRow $record) => $this->canEditProductPricing($record))
            ->modalHeading(fn () => ($this->pricingEditorSettings['product']['model_code'] ?? '').' – '.($this->pricingEditorSettings['product']['name_hu'] ?? 'Termék árkezelése'))
            ->modalWidth(Width::Screen)->modalSubmitActionLabel('Minden ár mentése és jóváhagyása')->modalCancelActionLabel('Mégse')
            ->modalSubmitAction(fn ($action) => $action->disabled(fn () => $this->pricingEditorError !== null))
            ->mountUsing(function (PricingCalculationRow $record, Action $action): void {
                $record = $this->productPricingSourceRecord($record->id);
                abort_unless($this->canEditProductPricing($record), 403);
                try {
                    $settings = app(ProductPricingService::class)->open($record->pricingProject, $record->product);
                } catch (DomainException|ValidationException $exception) {
                    Notification::make()->title('Nem nyitható meg az árkezelés')->body($exception->getMessage())->warning()->send();
                    $action->halt();
                }
                $this->pricingEditorSourceRowId = $record->id;
                $this->pricingEditorProjectId = $record->pricing_project_id;
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
                $project = $this->productPricingEditorProject();
                $previousLists = method_exists($this, 'overviewLists') ? $this->overviewLists->modelKeys() : null;
                try {
                    app(ProductPricingService::class)->approve($project, Product::findOrFail($this->pricingEditorProductId),
                        $this->pricingEditor, $this->pricingEditorFingerprint);
                } catch (DomainException|ValidationException $exception) {
                    $this->pricingEditorError = $exception->getMessage();
                    Notification::make()->title('Nem menthető az árkezelés')->body($exception->getMessage())->danger()->send();
                    $action->halt();
                }
                Notification::make()->title('A termék összes ára mentve és jóváhagyva')->success()->send();
                unset($this->overviewLists, $this->outletPriceLists, $this->averageRetailMultiplier,
                    $this->averageCostBasedMultiplier, $this->averageNetNetMultiplier, $this->averageDistributorNetNetMultiplier);
                if ($previousLists !== null && $previousLists !== $this->overviewLists->modelKeys()) {
                    // Newly created price lists add overview columns without resetting filters or pagination.
                    $this->bootedInteractsWithTable();
                }
                // Unlike resetTable(), this preserves filters, sort, pagination, selection and column layout.
                $this->flushCachedTableRecords();
            });
    }

    public function canEditProductPricing(?PricingCalculationRow $record = null): bool
    {
        if (! $this->productPricingOriginCanAccess()) {
            return false;
        }
        if ($record) {
            return ProductPricingNavigation::canEdit($record)
                && (! method_exists($this, 'pricingWorkspaceIsEditable') || $this->pricingWorkspaceIsEditable());
        }
        $project = method_exists($this, 'pricingSelectedProject') ? $this->pricingSelectedProject : null;

        return $project?->isOpenForPricing() && PricingProductWorkspace::canAccess()
            && $this->pricingWorkspaceIsEditable() && Gate::allows('update', $project)
            && Gate::allows('create', PricingCalculationRow::class) && Gate::allows('update', new PricingCalculationRow);
    }

    protected function productPricingOriginCanAccess(): bool
    {
        return method_exists($this, 'getResource') ? static::getResource()::canViewAny() : static::canAccess();
    }

    protected function productPricingSourceRecord(mixed $id, string $ability = 'view'): PricingCalculationRow
    {
        abort_unless($this->productPricingOriginCanAccess(), 403);
        abort_unless(is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0, 422);
        // Use the actual originating page's project/currency/resource scope, not a global ID lookup.
        $record = $this->getTable()->getQuery()->with(['pricingProject', 'product'])->find((int) $id);
        abort_unless($record, 404);
        Gate::authorize($ability, $record);

        return $record;
    }

    private function productPricingEditorProject(): PricingProject
    {
        abort_unless($this->pricingEditorSourceRowId && $this->getMountedAction()
            && in_array($this->getMountedAction()->getName(), ['productPricing', 'editProductPricing'], true), 403);
        abort_unless((int) $this->getMountedAction()->getRecord()?->getKey() === $this->pricingEditorSourceRowId, 403);
        $record = $this->productPricingSourceRecord($this->pricingEditorSourceRowId);
        abort_unless((int) $record->product_id === $this->pricingEditorProductId && (int) $record->pricing_project_id === $this->pricingEditorProjectId
            && $this->canEditProductPricing($record), 403);

        return $record->pricingProject;
    }

    public function updatedPricingEditor(): void
    {
        $this->refreshProductPricingPreview();
    }

    public function refreshProductPricingPreview(): void
    {
        $project = $this->productPricingEditorProject();
        $this->pricingEditorError = null;
        try {
            $this->pricingEditorPreview = app(ProductPricingService::class)->preview($project,
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

    public function unmountAction(bool $canCancelParentActions = true): void
    {
        parent::unmountAction($canCancelParentActions);
        if (! $this->mountedActions) {
            $this->pricingEditorSourceRowId = $this->pricingEditorProjectId = $this->pricingEditorProductId = null;
            $this->pricingEditorFingerprint = '';
            $this->pricingEditorError = null;
            $this->pricingEditorSettings = $this->pricingEditorPreview = $this->pricingEditor = [];
        }
    }
}

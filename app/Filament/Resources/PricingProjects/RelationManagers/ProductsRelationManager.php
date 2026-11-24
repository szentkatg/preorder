<?php

namespace App\Filament\Resources\PricingProjects\RelationManagers;

use App\Filament\Resources\PricingProjects\PricingProjectResource;
use App\Models\PricingCalculationRow;
use App\Models\Product;
use App\Services\Pricing\PricingProjectProductService;
use App\Services\Pricing\PricingProjectProductVisibilityService;
use App\Services\ProductBulkUpdateService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Árprojekt termékei';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('model_code')->label('Modellkód')->searchable()->sortable(),
            TextColumn::make('name_hu')->label('Név')->searchable()->sortable(),
            TextColumn::make('exclusivity')->label('Exkluzivitás')->searchable()->sortable()->toggleable(),
            TextColumn::make('brand.code')->label('Márka')
                ->formatStateUsing(fn ($state, $record) => $record->brand?->translate('name') ?: $state),
            TextColumn::make('orderSheetType.code')->label('Rendelőlap típusa')
                ->formatStateUsing(fn ($state, $record) => $record->orderSheetType?->translate('name') ?: $state),
            TextColumn::make('material_composition')->label('Anyagösszetétel'),
            IconColumn::make('active')->label('Aktív')->boolean(),
            IconColumn::make('visible_to_partners')->label('Partnerportálon látható')->boolean()->sortable()->toggleable(),
        ])->defaultPaginationPageOption(100)->paginationPageOptions([50, 100, 250, 500, 'all'])->headerActions([
            Action::make('selectProducts')->label('Termékek felvétele')->icon('heroicon-o-plus')
                ->visible(fn () => $this->getOwnerRecord()->isOpenForPricing() && Gate::allows('update', $this->getOwnerRecord())
                    && Gate::allows('viewAny', Product::class))
                ->url(fn () => PricingProjectResource::getUrl('products', ['record' => $this->getOwnerRecord()])),
        ])->toolbarActions([
            BulkAction::make('setPartnerVisibility')->label('Partneroldali láthatóság állítása')->icon('heroicon-o-eye')
                ->authorize(fn () => PricingProjectProductVisibilityService::canChange($this->getOwnerRecord()))
                ->authorizeIndividualRecords(false)->requiresConfirmation()
                ->modalHeading('Kijelölt termékek partneroldali láthatósága')
                ->modalDescription(fn () => 'A kijelölt '.count($this->visibilitySelectedIds()).' db termék láthatóságát állítjuk. Az árak, az Aktív jelző és a szezon láthatósága változatlan marad. A termék tényleges eléréséhez az aktív állapot és az elérhető szezon is szükséges.')
                ->modalSubmitActionLabel('Láthatóság módosítása')->modalCancelActionLabel('Mégse')
                ->schema([
                    Hidden::make('selection_token')->default(fn () => ProductBulkUpdateService::selectionToken($this->visibilitySelectedIds()))
                        ->required()->rules(['string', 'size:64']),
                    Select::make('visible_to_partners')->label('Partnerportálon látható')
                        ->options([1 => 'Igen – látható', 0 => 'Nem – rejtett'])->required()->rules(['boolean']),
                ])
                ->action(function (array $data, Collection $records, BulkAction $action): void {
                    try {
                        $result = app(PricingProjectProductVisibilityService::class)->updateSelected($this->getOwnerRecord(),
                            $this->visibilitySelectedIds($records), (bool) $data['visible_to_partners'], $data['selection_token']);
                    } catch (DomainException|ValidationException $exception) {
                        Notification::make()->danger()->title('A láthatóság nem módosítható')->body($exception->getMessage())->send();
                        $action->halt();

                        return;
                    }
                    Notification::make()->success()->title('Partneroldali láthatóság módosítva')
                        ->body('Kijelölt: '.$result['selected'].', módosított: '.$result['updated'].', változatlan: '.$result['unchanged'].'.')->send();
                    $this->deselectAllTableRecords();
                }),
            BulkAction::make('removeProducts')->label('Kijelölt termékek kivétele')->color('danger')->requiresConfirmation()
                ->modalDescription(PricingProjectProductService::REMOVE_WARNING)
                ->visible(fn () => ! $this->isReadOnly() && $this->getOwnerRecord()->isOpenForPricing()
                    && Gate::allows('update', $this->getOwnerRecord()) && Gate::allows('deleteAny', PricingCalculationRow::class))
                ->action(function (Collection $records, BulkAction $action): void {
                    try {
                        $ids = $this->isTrackingDeselectedTableRecords ? $records->modelKeys() : $this->selectedTableRecords;
                        $count = app(PricingProjectProductService::class)->remove($this->getOwnerRecord(), $ids);
                    } catch (DomainException $exception) {
                        Notification::make()->title('A termékek nem vehetők ki')->body($exception->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }
                    Notification::make()->title($count.' termék kivéve az árprojektből')->success()->send();
                    $this->deselectAllTableRecords();
                }),
        ]);
    }

    private function visibilitySelectedIds(?Collection $records = null): array
    {
        return $this->isTrackingDeselectedTableRecords
            ? ($records ?? $this->getSelectedTableRecords())->modelKeys() : $this->selectedTableRecords;
    }
}

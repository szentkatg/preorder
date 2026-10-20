<?php

namespace App\Filament\Resources\PricingProjects\RelationManagers;

use App\Filament\Resources\PricingProjects\PricingProjectResource;
use App\Models\PricingCalculationRow;
use App\Models\Product;
use App\Services\Pricing\PricingProjectProductService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Árprojekt termékei';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('model_code')->label('Modellkód')->searchable()->sortable(),
            TextColumn::make('name_hu')->label('Név')->searchable()->sortable(),
            TextColumn::make('brand.code')->label('Márka')
                ->formatStateUsing(fn ($state, $record) => $record->brand?->translate('name') ?: $state),
            TextColumn::make('orderSheetType.code')->label('Rendelőlap típusa')
                ->formatStateUsing(fn ($state, $record) => $record->orderSheetType?->translate('name') ?: $state),
            TextColumn::make('material_composition')->label('Anyagösszetétel'),
            IconColumn::make('active')->label('Aktív')->boolean(),
        ])->defaultPaginationPageOption(100)->paginationPageOptions([50, 100, 250, 500, 'all'])->headerActions([
            Action::make('selectProducts')->label('Termékek felvétele')->icon('heroicon-o-plus')
                ->visible(fn () => $this->getOwnerRecord()->isOpenForPricing() && Gate::allows('update', $this->getOwnerRecord())
                    && Gate::allows('viewAny', Product::class))
                ->url(fn () => PricingProjectResource::getUrl('products', ['record' => $this->getOwnerRecord()])),
        ])->toolbarActions([
            BulkAction::make('removeProducts')->label('Kijelölt termékek kivétele')->color('danger')->requiresConfirmation()
                ->modalDescription(PricingProjectProductService::REMOVE_WARNING)
                ->visible(fn () => ! $this->isReadOnly() && $this->getOwnerRecord()->isOpenForPricing()
                    && Gate::allows('update', $this->getOwnerRecord()) && Gate::allows('deleteAny', PricingCalculationRow::class))
                ->action(function (Collection $records, BulkAction $action): void {
                    try {
                        $ids = $this->isTrackingDeselectedTableRecords ? $records->modelKeys() : $this->selectedTableRecords;
                        $count = app(PricingProjectProductService::class)->remove($this->getOwnerRecord(), $ids);
                    } catch (\DomainException $exception) {
                        Notification::make()->title('A termékek nem vehetők ki')->body($exception->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }
                    Notification::make()->title($count.' termék kivéve az árprojektből')->success()->send();
                    $this->deselectAllTableRecords();
                }),
        ]);
    }
}

<?php

namespace App\Filament\Resources\PricingProjects\Pages;

use App\Filament\Resources\PricingProjects\PricingProjectResource;
use App\Filament\Support\AdminResourceTable;
use App\Services\Pricing\PricingProjectProductService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class SelectPricingProjectProducts extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = PricingProjectResource::class;

    protected static ?string $title = 'Termékek felvétele az árprojektbe';

    protected string $view = 'filament.resources.pricing-projects.select-products';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless($this->record->isOpenForPricing(), 403);
        PricingProjectProductService::authorize($this->record);
    }

    public function hydrate(): void
    {
        $this->record = $this->record->fresh();
        abort_unless($this->record?->isOpenForPricing(), 403);
        PricingProjectProductService::authorize($this->record);
    }

    public function table(Table $table): Table
    {
        return AdminResourceTable::configureColumnManager($table)
            ->query(fn () => app(PricingProjectProductService::class)->candidates($this->getRecord()))
            ->columns([
                TextColumn::make('model_code')->label('Modellkód')->searchable()->sortable(),
                TextColumn::make('name_hu')->label('Név')->searchable()->sortable(),
                TextColumn::make('brand.code')->label('Márka')->sortable()
                    ->formatStateUsing(fn ($state, $record) => $record->brand?->translate('name') ?: $state),
                TextColumn::make('orderSheetType.code')->label('Rendelőlap típusa')->sortable()
                    ->formatStateUsing(fn ($state, $record) => $record->orderSheetType?->translate('name') ?: $state),
                TextColumn::make('material_composition')->label('Anyagösszetétel')->searchable()->sortable(),
                IconColumn::make('active')->label('Aktív')->boolean(),
            ])
            ->filters([
                SelectFilter::make('brand_id')->label('Márka')->relationship('brand', 'code')->multiple()->searchable()->preload()
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->translate('name') ?: $record->code),
                SelectFilter::make('order_sheet_type_id')->label('Rendelőlap típusa')->relationship('orderSheetType', 'code')->multiple()->searchable()->preload()
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->translate('name') ?: $record->code),
            ])
            ->defaultSort('model_code')->defaultPaginationPageOption(100)->paginationPageOptions([50, 100, 250, 500])
            ->toolbarActions([
                BulkAction::make('addSelectedProducts')->label('Kijelölt termékek felvétele')
                    ->icon('heroicon-o-plus')->requiresConfirmation()
                    ->modalDescription('Csak a kijelölt, még egyetlen árprojekthez sem rendelt termékek kerülnek ebbe a projektbe. Az árak kiszámítása külön művelet.')
                    ->action(function (Collection $records, BulkAction $action): void {
                        try {
                            $ids = $this->isTrackingDeselectedTableRecords ? $records->modelKeys() : $this->selectedTableRecords;
                            $count = app(PricingProjectProductService::class)->add($this->getRecord(), $ids);
                        } catch (DomainException $exception) {
                            Notification::make()->title('A termékek nem vehetők fel')->body($exception->getMessage())->danger()->send();
                            $action->halt();

                            return;
                        }
                        Notification::make()->title($count.' termék felvéve')->success()->send();
                        $this->deselectAllTableRecords();
                        $this->resetTable();
                    }),
            ])->emptyStateHeading('Nincs felvehető termék')
            ->emptyStateDescription('A kiválasztott szezon termékei már árprojekthez tartoznak, vagy nem felelnek meg a szűrésnek.');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('back')->label('Vissza az árprojekthez')
            ->url(fn () => PricingProjectResource::getUrl('edit', ['record' => $this->getRecord()]))];
    }
}

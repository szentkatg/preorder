<?php

namespace App\Filament\Resources\PricingProjects\RelationManagers;

use App\Filament\Resources\PricingCalculationRows\Schemas\PricingCalculationRowForm;
use App\Filament\Resources\PricingCalculationRows\Tables\PricingCalculationRowsTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\PricingProjectItemDeletionService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class CalculationRowsRelationManager extends RelationManager
{
    protected static string $relationship = 'calculationRows';

    protected static ?string $title = 'Árkalkulációs sorok';

    public function form(Schema $schema): Schema
    {
        return PricingCalculationRowForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            PricingCalculationRowsTable::configure($table),
            PricingCalculationRow::class,
            withViewAction: false,
        )->recordActions([
            DeleteAction::make()
                ->label('Tétel törlése minden kalkulációból')
                ->modalHeading('Tétel törlése az árprojekt összes árlistájából')
                ->modalDescription(PricingProjectItemDeletionService::WARNING)
                ->authorize(fn (PricingCalculationRow $record): bool => ! $this->isReadOnly()
                    && $this->getOwnerRecord()->isOpenForPricing()
                    && Gate::allows('update', $this->getOwnerRecord()) && Gate::allows('delete', $record))
                ->using(function (PricingCalculationRow $record): bool {
                    app(PricingProjectItemDeletionService::class)->delete($this->getOwnerRecord(), [$record->id]);

                    return true;
                }),
        ])->toolbarActions([
            BulkActionGroup::make([
                DeleteBulkAction::make()
                    ->label('Kijelölt tételek törlése minden kalkulációból')
                    ->modalHeading('Kijelölt tételek törlése az összes kalkulációból')
                    ->modalDescription(PricingProjectItemDeletionService::WARNING)
                    ->authorizeIndividualRecords(false)
                    ->authorize(fn (): bool => ! $this->isReadOnly() && $this->getOwnerRecord()->isOpenForPricing()
                        && Gate::allows('update', $this->getOwnerRecord()) && Gate::allows('deleteAny', PricingCalculationRow::class))
                    ->action(function (Collection $records, DeleteBulkAction $action): void {
                        app(PricingProjectItemDeletionService::class)->delete($this->getOwnerRecord(), $records->modelKeys(), bulk: true);
                        $action->success();
                    }),
            ]),
        ]);
    }
}

<?php

namespace App\Filament\Resources\PricingParameters\Tables;

use App\Exceptions\PricingParameterConflictException;
use App\Filament\Resources\PricingParameters\Schemas\PricingParameterForm;
use App\Filament\Support\Actions\CopyPricingConfigurationAction;
use App\Models\PricingParameter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PricingParametersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pricingProject.name')->label('Árprojekt'),
                TextColumn::make('parameter_type')->label('Paraméter')->badge(),
                TextColumn::make('scope_type')->label('Szűkítés')->badge(),
                TextColumn::make('supplier_country_code')->label('Ország'),
                TextColumn::make('itemMainGroup.name')->label('Főcsoport'),
                TextColumn::make('material_composition')->label('Anyagösszetétel'),
                TextColumn::make('product.model_code')->label('Modell'),
                TextColumn::make('priceList.code')->label('Árlista'),
                TextColumn::make('currency.code')->label('Pénznem'),
                TextColumn::make('value')->label('Érték'),
                IconColumn::make('active')->label('Aktív')->boolean(),
            ])
            ->filters([
                SelectFilter::make('pricing_project_id')->label('Árprojekt')->relationship('pricingProject', 'name')
                    ->multiple()->searchable()->preload(),
                SelectFilter::make('parameter_type')->label('Paraméter')->options(PricingParameterForm::parameterTypeOptions())
                    ->multiple()->searchable(),
                SelectFilter::make('scope_type')->label('Szűkítés')->options(PricingParameterForm::scopeTypeOptions())
                    ->multiple()->searchable(),
            ])
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
            ->recordActions([EditAction::make()->using(function (PricingParameter $record, array $data, EditAction $action): PricingParameter {
                try {
                    $record->update($data);
                } catch (PricingParameterConflictException $exception) {
                    Notification::make()->danger()->title('A paraméter nem menthető')->body($exception->getMessage())->send();
                    $action->halt();
                }

                return $record;
            })])
            ->toolbarActions([
                BulkActionGroup::make([
                    CopyPricingConfigurationAction::make(PricingParameter::class),
                    DeleteBulkAction::make()->authorizeIndividualRecords(fn ($record) => $record->pricingProject?->isOpenForPricing() ?? false),
                ]),
            ]);
    }
}

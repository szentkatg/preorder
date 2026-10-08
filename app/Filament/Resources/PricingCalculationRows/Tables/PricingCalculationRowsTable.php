<?php

namespace App\Filament\Resources\PricingCalculationRows\Tables;

use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Services\Pricing\CostCalculationService;
use App\Services\Pricing\PricingParameterAdjustmentService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;

class PricingCalculationRowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pricingProject.name')->label('Árprojekt'),
                TextColumn::make('product.catalog_group_name_hu')
                    ->label('Katalógus csoport')
                    ->toggleable(),
                TextColumn::make('product.itemMainGroup.name_hu')
                    ->label('Főcsoport')
                    ->toggleable(),
                TextColumn::make('product.model_code')->label('Modell kód'),
                TextColumn::make('product.name_hu')
                    ->label('Modell név')
                    ->toggleable(),
                TextColumn::make('product.material_composition')
                    ->label('Anyagösszetétel')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('color.name_hu')->label('Szín'),
                TextColumn::make('supplier.short_name')->label('Beszállító'),
                TextColumn::make('supplier.country_code')
                    ->label('Besz. ország')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('purchase_price')->label('Besz. ár')->numeric(decimalPlaces: 4),
                TextColumn::make('purchaseCurrency.code')->label('Besz. deviza'),
                TextColumn::make('exchange_rate')
                    ->label('Árfolyam')
                    ->numeric(decimalPlaces: 4)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextInputColumn::make('shipping_cost_percent')
                    ->label('Száll. %')
                    ->type('number')
                    ->inputMode('decimal')
                    ->step('0.01')
                    ->suffix('%', true)
                    ->tooltip('Írd át az értéket, majd válaszd ki lent, milyen szűkítéssel legyen érvényes.')
                    ->rules(['required', 'numeric', 'min:0'])
                    ->disabled(
                        fn (PricingCalculationRow $record): bool =>
                            $record->price_type !== PricingCalculationRow::TYPE_COST
                    )
                    ->updateStateUsing(function (PricingCalculationRow $record, mixed $state, mixed $livewire): mixed {
                        if (method_exists($livewire, 'startInlineCostPercentParameterAdjustment')) {
                            $livewire->startInlineCostPercentParameterAdjustment(
                                (int) $record->getKey(),
                                PricingParameter::TYPE_SHIPPING_COST_PERCENT,
                                $state,
                            );
                        }

                        return $state;
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextInputColumn::make('customs_percent')
                    ->label('Vám %')
                    ->type('number')
                    ->inputMode('decimal')
                    ->step('0.01')
                    ->suffix('%', true)
                    ->tooltip('Írd át az értéket, majd válaszd ki lent, milyen szűkítéssel legyen érvényes.')
                    ->rules(['required', 'numeric', 'min:0'])
                    ->disabled(
                        fn (PricingCalculationRow $record): bool =>
                            $record->price_type !== PricingCalculationRow::TYPE_COST
                    )
                    ->updateStateUsing(function (PricingCalculationRow $record, mixed $state, mixed $livewire): mixed {
                        if (method_exists($livewire, 'startInlineCostPercentParameterAdjustment')) {
                            $livewire->startInlineCostPercentParameterAdjustment(
                                (int) $record->getKey(),
                                PricingParameter::TYPE_CUSTOMS_PERCENT,
                                $state,
                            );
                        }

                        return $state;
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('candidate_count')
                    ->label('Vizsgált aktív árak')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('priceList.code')
                    ->label('Árlista')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('price_type')
                    ->label('Ártípus')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('currency.code')
                    ->label('Pénznem')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('calculated_price')->label('Kalkulált ár')->numeric(decimalPlaces: 2),
                TextColumn::make('manual_price')
                    ->label('Manuális ár')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('final_price')->label('Végleges ár')->numeric(decimalPlaces: 2),
                TextColumn::make('status')->label('Státusz')->badge(),
            ])
            ->filters([
                SelectFilter::make('pricing_project_id')
                    ->label('Árprojekt')
                    ->relationship('pricingProject', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('price_type')
                    ->label('Ártípus')
                    ->options([
                        PricingCalculationRow::TYPE_COST => 'Bekerülési érték',
                        PricingCalculationRow::TYPE_RETAIL => 'Kisker',
                        PricingCalculationRow::TYPE_WHOLESALE => 'Nagyker',
                        PricingCalculationRow::TYPE_DISTRIBUTOR => 'Disztribútor',
                    ])
                    ->default(PricingCalculationRow::TYPE_COST),

                SelectFilter::make('status')
                    ->label('Státusz')
                    ->options([
                        PricingCalculationRow::STATUS_CALCULATED => 'Kalkulált ár',
                        PricingCalculationRow::STATUS_REVIEWED => 'Átnézett ár',
                        PricingCalculationRow::STATUS_APPROVED => 'Jóváhagyott ár',
                        PricingCalculationRow::STATUS_MODIFIED_APPROVED => 'Módosított jóváhagyott ár',
                        PricingCalculationRow::STATUS_APPROVED_ROUND_2 => '2. körös jóváhagyott',
                        PricingCalculationRow::STATUS_APPROVED_ROUND_3 => '3. körös jóváhagyott',
                    ]),
            ])
            ->defaultSort('updated_at', 'desc')
            ->reorderableColumns()
            ->columnManagerColumns(3)
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
            ->recordActions([
                Action::make('calculationDetails')
                    ->label('Részletek')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->modalHeading('Kalkuláció részletei')
                    ->modalWidth(Width::Screen)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Bezárás')
                    ->modalContent(
                        fn (PricingCalculationRow $record) => view(
                            'filament.pricing.calculation-details',
                            ['record' => $record]
                        )
                    ),
                self::parameterAction(
                    'updateShippingCostPercent',
                    'Szállítás %',
                    PricingParameter::TYPE_SHIPPING_COST_PERCENT,
                    'shipping_cost_percent'
                )
                    ->icon('heroicon-o-truck')
                    ->visible(
                        fn (PricingCalculationRow $record): bool =>
                            $record->price_type === PricingCalculationRow::TYPE_COST
                    ),
                self::parameterAction(
                    'updateCustomsPercent',
                    'Vám %',
                    PricingParameter::TYPE_CUSTOMS_PERCENT,
                    'customs_percent'
                )
                    ->icon('heroicon-o-receipt-percent')
                    ->visible(
                        fn (PricingCalculationRow $record): bool =>
                            $record->price_type === PricingCalculationRow::TYPE_COST
                    ),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    private static function parameterAction(
        string $name,
        string $label,
        string $parameterType,
        string $rowAttribute,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-pencil-square')
            ->modalHeading($label)
            ->modalDescription('A módosítás az árképzési paraméterek közé kerül mentésre a kiválasztott szűkítési szinten, majd a rendszer újraszámolja az árprojekt bekerülési értékeit.')
            ->fillForm(
                fn (PricingCalculationRow $record): array => [
                    'scope_type' => self::defaultParameterScope($record),
                    'value' => (float) $record->{$rowAttribute},
                ]
            )
            ->schema([
                Select::make('scope_type')
                    ->label('Milyen szinten legyen érvényes?')
                    ->options(
                        fn (PricingCalculationRow $record): array =>
                            self::parameterScopeOptions($record)
                    )
                    ->required(),

                TextInput::make('value')
                    ->label('Új érték')
                    ->numeric()
                    ->minValue(0)
                    ->step('0.01')
                    ->suffix('%')
                    ->required(),
            ])
            ->action(function (array $data, PricingCalculationRow $record) use ($parameterType): void {
                try {
                    $parameter = app(PricingParameterAdjustmentService::class)
                        ->updateCostPercentParameter(
                            $record,
                            $parameterType,
                            $data['scope_type'],
                            (float) $data['value'],
                        );

                    $result = app(CostCalculationService::class)
                        ->recalculate($record->pricingProject);

                    Notification::make()
                        ->title('Paraméter mentve és újraszámolva')
                        ->body(
                            self::parameterScopeLabel($parameter) .
                            ' | Frissítve: ' . $result['updated'] .
                            ', létrehozva: ' . $result['created'] .
                            ', kihagyott: ' . $result['skipped'] .
                            ', futás: ' . $result['run_id']
                        )
                        ->success()
                        ->send();
                } catch (InvalidArgumentException $exception) {
                    Notification::make()
                        ->title('A paraméter nem menthető')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * @return array<string, string>
     */
    private static function parameterScopeOptions(PricingCalculationRow $record): array
    {
        $record->loadMissing(['product.itemMainGroup', 'supplier']);

        $options = [
            PricingParameter::SCOPE_GLOBAL => 'Globális - teljes árprojekt',
        ];

        if (filled($record->supplier?->country_code)) {
            $options[PricingParameter::SCOPE_SUPPLIER_COUNTRY] =
                'Beszállító ország - ' . $record->supplier->country_code;
        }

        if (filled($record->product?->item_main_group_id)) {
            $options[PricingParameter::SCOPE_ITEM_MAIN_GROUP] =
                'Főcsoport - ' . (
                    $record->product->itemMainGroup?->name_hu
                    ?: $record->product->itemMainGroup?->code
                    ?: $record->product->item_main_group_id
                );
        }

        if (filled($record->product_id)) {
            $options[PricingParameter::SCOPE_PRODUCT] =
                'Modell - ' . ($record->product?->model_code ?: $record->product_id);
        }

        return self::appendParameterScopeCounts(
            $options,
            self::parameterScopeCounts($record, array_keys($options)),
        );
    }

    /**
     * @param  array<int, string>  $scopeTypes
     * @return array<string, int>
     */
    private static function parameterScopeCounts(PricingCalculationRow $record, array $scopeTypes): array
    {
        $counts = [];

        foreach ($scopeTypes as $scopeType) {
            $counts[$scopeType] = self::parameterScopeAffectedRowCount($record, $scopeType);
        }

        return $counts;
    }

    /**
     * @param  array<string, string>  $options
     * @param  array<string, int>  $counts
     * @return array<string, string>
     */
    private static function appendParameterScopeCounts(array $options, array $counts): array
    {
        foreach ($options as $scopeType => $label) {
            if (array_key_exists($scopeType, $counts)) {
                $options[$scopeType] = $label . ' (' . $counts[$scopeType] . ' sor)';
            }
        }

        return $options;
    }

    private static function parameterScopeAffectedRowCount(PricingCalculationRow $record, string $scopeType): int
    {
        $record->loadMissing(['product', 'supplier']);

        $query = PricingCalculationRow::query()
            ->where('pricing_project_id', $record->pricing_project_id)
            ->where('price_type', PricingCalculationRow::TYPE_COST);

        match ($scopeType) {
            PricingParameter::SCOPE_GLOBAL => null,
            PricingParameter::SCOPE_SUPPLIER_COUNTRY => $query->whereHas(
                'supplier',
                fn ($supplierQuery) => $supplierQuery->where('country_code', $record->supplier?->country_code)
            ),
            PricingParameter::SCOPE_ITEM_MAIN_GROUP => $query->whereHas(
                'product',
                fn ($productQuery) => $productQuery->where('item_main_group_id', $record->product?->item_main_group_id)
            ),
            PricingParameter::SCOPE_PRODUCT => $query->where('product_id', $record->product_id),
            default => null,
        };

        return $query->count();
    }

    private static function defaultParameterScope(PricingCalculationRow $record): string
    {
        $options = self::parameterScopeOptions($record);

        return array_key_exists(PricingParameter::SCOPE_SUPPLIER_COUNTRY, $options)
            ? PricingParameter::SCOPE_SUPPLIER_COUNTRY
            : PricingParameter::SCOPE_GLOBAL;
    }

    private static function parameterScopeLabel(PricingParameter $parameter): string
    {
        return match ($parameter->scope_type) {
            PricingParameter::SCOPE_SUPPLIER_COUNTRY =>
                'Beszállító ország: ' . $parameter->supplier_country_code,
            PricingParameter::SCOPE_ITEM_MAIN_GROUP =>
                'Főcsoport ID: ' . $parameter->item_main_group_id,
            PricingParameter::SCOPE_PRODUCT =>
                'Modell ID: ' . $parameter->product_id,
            default => 'Globális paraméter',
        };
    }
}

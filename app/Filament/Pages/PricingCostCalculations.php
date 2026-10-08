<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PricingCalculationRows\Tables\PricingCalculationRowsTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Services\Pricing\CostCalculationService;
use App\Services\Pricing\PricingParameterAdjustmentService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class PricingCostCalculations extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon =
        'heroicon-o-calculator';

    protected static string|\UnitEnum|null $navigationGroup = 'Árképzés';

    protected static ?string $navigationLabel = 'Bekerülési értékek';

    protected static ?string $title = 'Bekerülési értékek';

    protected static ?int $navigationSort = 35;

    protected string $view = 'filament.pages.pricing-cost-calculations';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $pendingParameterAdjustment = null;

    public ?string $pendingParameterScope = null;

    public function startInlineCostPercentParameterAdjustment(
        int $recordId,
        string $parameterType,
        mixed $value,
    ): void {
        if (! is_numeric($value) || (float) $value < 0) {
            Notification::make()
                ->title('Érvénytelen érték')
                ->body('A százalékos érték csak 0 vagy annál nagyobb szám lehet.')
                ->danger()
                ->send();

            $this->resetTable();

            return;
        }

        if (! in_array($parameterType, [
            PricingParameter::TYPE_SHIPPING_COST_PERCENT,
            PricingParameter::TYPE_CUSTOMS_PERCENT,
        ], true)) {
            Notification::make()
                ->title('Ismeretlen paraméter')
                ->danger()
                ->send();

            $this->resetTable();

            return;
        }

        $record = PricingCalculationRow::query()
            ->with(['pricingProject', 'product.itemMainGroup', 'supplier'])
            ->find($recordId);

        if (! $record || $record->price_type !== PricingCalculationRow::TYPE_COST) {
            Notification::make()
                ->title('A paraméter innen nem módosítható')
                ->body('Paramétert csak bekerülési érték sorból lehet módosítani.')
                ->danger()
                ->send();

            $this->resetTable();

            return;
        }

        $scopeOptions = self::parameterScopeOptions($record);
        $scopeCounts = self::parameterScopeCounts($record, array_keys($scopeOptions));

        $this->pendingParameterAdjustment = [
            'record_id' => $record->getKey(),
            'parameter_type' => $parameterType,
            'parameter_label' => self::parameterTypeLabel($parameterType),
            'value' => round((float) $value, 4),
            'model_code' => $record->product?->model_code,
            'product_name' => $record->product?->name_hu,
            'supplier_name' => $record->supplier?->short_name ?: $record->supplier?->name,
            'scope_options' => self::appendParameterScopeCounts($scopeOptions, $scopeCounts),
            'scope_counts' => $scopeCounts,
        ];

        $this->pendingParameterScope = self::defaultParameterScope($scopeOptions);

        Notification::make()
            ->title('Válaszd ki a szűkítést')
            ->body('Az érték még nincs mentve. A táblázat felett válaszd ki, milyen szinten legyen érvényes, majd indítsd az újraszámítást.')
            ->info()
            ->send();
    }

    public function confirmPendingParameterAdjustment(): void
    {
        if (! $this->pendingParameterAdjustment) {
            return;
        }

        $scopeOptions = $this->pendingParameterAdjustment['scope_options'] ?? [];

        if (
            ! is_array($scopeOptions) ||
            ! is_string($this->pendingParameterScope) ||
            ! array_key_exists($this->pendingParameterScope, $scopeOptions)
        ) {
            Notification::make()
                ->title('Válassz érvényes szűkítési szintet')
                ->danger()
                ->send();

            return;
        }

        $record = PricingCalculationRow::query()
            ->with(['pricingProject', 'product.itemMainGroup', 'supplier'])
            ->find($this->pendingParameterAdjustment['record_id']);

        if (! $record || ! $record->pricingProject) {
            Notification::make()
                ->title('A sor már nem található')
                ->danger()
                ->send();

            $this->clearPendingParameterAdjustment();
            $this->resetTable();

            return;
        }

        try {
            $parameter = app(PricingParameterAdjustmentService::class)
                ->updateCostPercentParameter(
                    $record,
                    $this->pendingParameterAdjustment['parameter_type'],
                    $this->pendingParameterScope,
                    (float) $this->pendingParameterAdjustment['value'],
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

            $this->clearPendingParameterAdjustment();
            $this->resetTable();
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('A paraméter nem menthető')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function cancelPendingParameterAdjustment(): void
    {
        $this->clearPendingParameterAdjustment();
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $table = PricingCalculationRowsTable::configure($table)
            ->query(
                PricingCalculationRow::query()
                    ->where('price_type', PricingCalculationRow::TYPE_COST)
                    ->with([
                        'pricingProject',
                        'product.itemMainGroup',
                        'color',
                        'supplier',
                        'purchaseCurrency',
                        'currency',
                    ])
            )
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query
                    ->where('price_type', PricingCalculationRow::TYPE_COST)
            );

        return AdminResourceTable::configure(
            $table,
            PricingCalculationRow::class,
            withViewAction: false,
        );
    }

    private function clearPendingParameterAdjustment(): void
    {
        $this->pendingParameterAdjustment = null;
        $this->pendingParameterScope = null;
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

        return $options;
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

    /**
     * @param  array<string, string>  $options
     */
    private static function defaultParameterScope(array $options): string
    {
        return array_key_exists(PricingParameter::SCOPE_SUPPLIER_COUNTRY, $options)
            ? PricingParameter::SCOPE_SUPPLIER_COUNTRY
            : PricingParameter::SCOPE_GLOBAL;
    }

    private static function parameterTypeLabel(string $parameterType): string
    {
        return match ($parameterType) {
            PricingParameter::TYPE_SHIPPING_COST_PERCENT => 'Szállítási költség %',
            PricingParameter::TYPE_CUSTOMS_PERCENT => 'Vám %',
            default => 'Paraméter',
        };
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

<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminResourceTable;
use App\Filament\Support\Concerns\HasPricingProjectContext;
use App\Filament\Support\Concerns\HasPricingTablePreferences;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\PricingOverviewQuery;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

class PricingProductOverview extends Page implements HasTable
{
    use HasPageShield { canAccess as protected canAccessUsingPagePermission; }
    use HasPricingProjectContext;
    use HasPricingTablePreferences, InteractsWithTable {
        HasPricingTablePreferences::loadTableColumnsFromSession insteadof InteractsWithTable;
        HasPricingTablePreferences::persistTableColumns insteadof InteractsWithTable;
        HasPricingTablePreferences::persistHasReorderedTableColumns insteadof InteractsWithTable;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static string|\UnitEnum|null $navigationGroup = 'Árképzés';

    protected static ?string $navigationLabel = 'Teljes áráttekintés';

    protected static ?string $title = 'Termékek összes ára és paraméterei';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.pricing-cost-calculations';

    public static function canAccess(): bool
    {
        return static::canAccessUsingPagePermission() && Gate::allows('viewAny', PricingCalculationRow::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->initialisePricingProjectContext();
        $this->pricingColumnWidths = $this->loadPricingColumnWidths();
        $this->pricingDecimalPlaces = $this->loadPricingDecimalPlaces();
    }

    #[Computed]
    public function overviewLists(): Collection
    {
        return PriceList::whereIn('id', PricingCalculationRow::where('pricing_project_id', $this->pricingProjectId ?? 0)->select('price_list_id'))
            ->with('currency')->orderBy('code')->get();
    }

    protected function refreshPricingContextTable(): void
    {
        unset($this->overviewLists);
        $this->pricingDecimalPlaces = $this->loadPricingDecimalPlaces();
        $this->tableFilters = $this->tableColumnSearches = [];
        $this->tableSearch = '';
        $this->resetTable();
    }

    public function pricingWorkspaceDescription(): string
    {
        return 'Csak olvasható végső áttekintés, modellenként/színenként. Kizárólag végleges árakat mutatunk, a kerekítés köztes lépései nélkül. Bekerülési szorzó = az adott ár HUF értéke / HUF bekerülési érték; a mentett devizás kisker árfolyamot használjuk. A kereskedelmi szorzók iránya: bruttó kisker / nettó nagyker, illetve bruttó kisker / nettó disztribútor és nettó nagyker / nettó disztribútor. Hiányzó adatnál nem helyettesítünk nullával vagy más pénznem árával.';
    }

    protected function pricingPreferencePrefix(): string
    {
        return 'pricing_product_overview';
    }

    protected function getHeaderActions(): array
    {
        return [$this->pricingDecimalPlacesAction()];
    }

    protected function pricingDecimalColumns(): array
    {
        $columns = ['purchase_price' => 'Beszerzési ár', 'exchange_rate' => 'Beszerzési árfolyam',
            'shipping_cost_percent' => 'Szállítás %', 'customs_percent' => 'Vám %', 'final_price' => 'Bekerülési HUF'];
        foreach ($this->overviewLists as $list) {
            foreach (app(PricingOverviewQuery::class)->columns($list) as $name => $column) {
                if (! in_array($column['kind'], ['status', 'review'], true)) {
                    $columns[$name] = $column['label'];
                }
            }
        }

        return $columns;
    }

    public function table(Table $table): Table
    {
        $query = $this->scopePricingProjectContext(PricingCalculationRow::query()->where('price_type', 'cost')->where('price_list_key', 0)
            ->whereHas('product', fn ($q) => $q->where('active', true))->with(['product.itemMainGroup', 'product.brand', 'color', 'supplier', 'purchaseCurrency', 'pricingProject']))
            ->select('pricing_calculation_rows.*');
        $columns = [TextColumn::make('product.model_code')->label('Modell kód'), TextColumn::make('product.name_hu')->label('Modell név'),
            TextColumn::make('product.itemMainGroup.name_hu')->label('Főcsoport'), TextColumn::make('product.catalog_group_name_hu')->label('Katalóguscsoport'),
            TextColumn::make('product.brand.name')->label('Márka'), TextColumn::make('color.name_hu')->label('Szín'),
            TextColumn::make('supplier.erp_partner_code')->label('Beszállító kód'), TextColumn::make('supplier.name')->label('Beszállító'),
            TextColumn::make('purchaseCurrency.code')->label('Beszerzési pénznem')];
        foreach (['purchase_price' => 'Beszerzési ár', 'exchange_rate' => 'Beszerzési árfolyam', 'shipping_cost_percent' => 'Szállítás %',
            'customs_percent' => 'Vám %', 'final_price' => 'Bekerülési HUF'] as $name => $label) {
            $columns[] = TextColumn::make($name)->label($label)->numeric()->alignEnd()
                ->formatStateUsing(fn ($state) => $this->formatPricingDecimal($state, $name));
        }
        $expressions = [];
        foreach ($this->overviewLists as $list) {
            foreach (app(PricingOverviewQuery::class)->columns($list) as $name => $definition) {
                $query->selectSub(clone $definition['query'], $name);
                $expressions[$name] = $definition;
                $column = TextColumn::make($name)->label($definition['label'])->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: in_array($definition['kind'], ['status', 'review'], true));
                if ($definition['kind'] === 'review') {
                    $column->getStateUsing(fn ($record) => $record->{$name} === null ? null : (bool) $record->{$name})
                        ->formatStateUsing(fn ($state) => $state === null ? '—' : ($state ? 'Igen' : 'Nem'))
                        ->color(fn ($state) => $state ? 'danger' : 'success');
                } elseif ($definition['kind'] === 'status') {
                    $column->badge()->formatStateUsing(fn ($state) => match ($state) {
                        'calculated' => 'Kalkulált', 'reviewed' => 'Átnézett', 'approved' => 'Jóváhagyott',
                        'modified_approved' => 'Módosított jóváhagyott', 'approved_round_2' => '2. körös jóváhagyott',
                        'approved_round_3' => '3. körös jóváhagyott', default => $state,
                    });
                } else {
                    $column->numeric()->alignEnd()->formatStateUsing(fn ($state) => $this->formatPricingDecimal($state, $name, 2));
                }
                $columns[] = $column;
            }
        }
        $table = AdminResourceTable::configure($table->query($query)->columns($columns)->recordActions([])->recordAction(null)->recordUrl(null)
            ->recordClasses(fn (PricingCalculationRow $record) => ['pricing-needs-attention' => $this->overviewNeedsAttention($record)])
            ->reorderableColumns()->columnManagerColumns(3)->defaultSort('product_id')->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all']), PricingCalculationRow::class, withViewAction: false);
        foreach ($expressions as $name => $definition) {
            $expression = $definition['query'];
            $table->getColumn($name)->sortable(query: fn ($query, string $direction) => $query->orderBy(clone $expression, $direction))
                ->searchable(query: fn ($query, string $search) => $query->whereRaw('CAST(('.$expression->toSql().') AS CHAR) LIKE ?',
                    [...$expression->getBindings(), '%'.str_replace(',', '.', $search).'%']), isIndividual: true);
        }

        // The overview's base records are costs, but status/review filters concern
        // all the prices displayed across that product/color row.
        $priceState = fn () => DB::table('pricing_calculation_rows as overview_state')
            ->whereColumn('overview_state.pricing_project_id', 'pricing_calculation_rows.pricing_project_id')
            ->whereColumn('overview_state.product_id', 'pricing_calculation_rows.product_id')
            ->whereColumn('overview_state.color_key', 'pricing_calculation_rows.color_key')
            ->whereIn('overview_state.price_list_id', $this->overviewLists->pluck('id'))->selectRaw('1');
        $table->pushFilters([
            SelectFilter::make('common_status')->label('Árak státusza')->multiple()->options([
                'calculated' => 'Kalkulált', 'reviewed' => 'Átnézett', 'approved' => 'Jóváhagyott',
                'modified_approved' => 'Módosított jóváhagyott', 'approved_round_2' => '2. körös jóváhagyott', 'approved_round_3' => '3. körös jóváhagyott',
            ])->query(fn ($query, array $data) => empty($data['values']) ? $query
                : $query->whereExists($priceState()->whereIn('overview_state.status', $data['values']))),
            TernaryFilter::make('requires_review')->label('Bármely ár felülvizsgálandó')->queries(
                true: fn ($query) => $query->where(fn ($q) => $q->where('requires_review', true)->orWhereExists($priceState()->where('overview_state.requires_review', true))),
                false: fn ($query) => $query->where('requires_review', false)->whereNotExists($priceState()->where('overview_state.requires_review', true)),
                blank: fn ($query) => $query,
            ),
        ]);

        return $table;
    }

    public function overviewNeedsAttention(PricingCalculationRow $record): bool
    {
        if ($record->needsPricingAttention()) {
            return true;
        }
        foreach ($this->overviewLists as $list) {
            $prefix = 'overview_'.$list->id.'_';
            if ($record->{$prefix.'price'} !== null && ($record->{$prefix.'review'} || (float) $record->{$prefix.'price'} <= 0
                || ! in_array($record->{$prefix.'status'}, ['approved', 'modified_approved', 'approved_round_2', 'approved_round_3'], true))) {
                return true;
            }
        }

        return false;
    }
}

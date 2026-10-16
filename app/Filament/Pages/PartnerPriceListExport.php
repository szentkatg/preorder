<?php

namespace App\Filament\Pages;

use App\Exports\PartnerPriceListExport as PriceListWorkbook;
use App\Models\Brand;
use App\Models\ItemMainGroup;
use App\Models\Language;
use App\Models\PriceList;
use App\Models\Season;
use App\Services\PartnerPriceListPdf;
use App\Services\PartnerPriceListService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Maatwebsite\Excel\Facades\Excel;

class PartnerPriceListExport extends Page implements HasForms, HasTable
{
    use HasPageShield { canAccess as protected canAccessUsingPagePermission; }
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static string|\UnitEnum|null $navigationGroup = 'Árképzés';

    protected static ?string $navigationLabel = 'Partner árlista készítése';

    protected static ?string $title = 'Partner árlista készítése';

    protected static ?int $navigationSort = 48;

    protected string $view = 'filament.pages.partner-price-list-export';

    public ?array $data = [];

    #[Locked]
    public ?array $appliedFilters = null;

    public static function canAccess(): bool
    {
        return static::canAccessUsingPagePermission() && PartnerPriceListService::canRead();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && parent::shouldRegisterNavigation();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(['format' => 'xlsx', 'brand_ids' => [], 'item_main_group_ids' => [], 'catalog_groups' => [],
            'language_id' => Language::where('active', true)->whereRaw('UPPER(code) = ?', ['HU'])->value('id')]);
    }

    public function form(Schema $schema): Schema
    {
        $invalidate = fn () => $this->clearPreview();

        return $schema->components([
            Select::make('season_id')->label('Szezon')->options(fn () => Season::orderByDesc('code')->get()->mapWithKeys(fn ($row) => [$row->id => $row->code.' - '.$row->name]))
                ->required()->searchable()->live()->afterStateUpdated(fn (Get $get, Set $set) => $this->refreshCatalogSelection($get, $set)),
            Select::make('price_list_id')->label('Nettó árlista')->options(fn () => PriceList::where('active', true)->whereIn('type', ['wholesale', 'distributor'])
                ->with('currency')->orderBy('code')->get()->mapWithKeys(fn ($row) => [$row->id => $row->code.' - '.$row->name_hu.' ('.$row->currency?->code.')']))
                ->required()->searchable()->live()->afterStateUpdated(function ($state, Set $set): void {
                    $list = PriceList::find($state);
                    $retail = $list?->retailPriceList;
                    $set('retail_price_list_id', $retail?->active && $retail?->type === 'retail' && $retail?->currency_id == $list?->currency_id ? $retail->id : null);
                    $this->clearPreview();
                }),
            Select::make('retail_price_list_id')->label('Bruttó kisker árlista (opcionális)')
                ->options(fn (Get $get) => PriceList::where('active', true)->where('type', 'retail')
                    ->where('currency_id', PriceList::find($get('price_list_id'))?->currency_id ?? 0)->orderBy('code')->pluck('code', 'id'))
                ->searchable()->live()->afterStateUpdated($invalidate)
                ->helperText('Alapból a nettó árlistához kapcsolt kisker árlista. Csak azonos pénznem; árfolyam- vagy ÁFA-számítás nem történik.'),
            Select::make('language_id')->label('Fájl nyelve')->options(fn () => Language::where('active', true)
                ->whereRaw('UPPER(code) IN (?, ?)', PartnerPriceListService::LANGUAGES)->orderBy('code')->get()->mapWithKeys(fn ($row) => [$row->id => strtoupper($row->code).' - '.($row->name_hu ?: $row->name)]))
                ->required()->live()->afterStateUpdated($invalidate),
            Select::make('format')->label('Formátum')->options(['xlsx' => 'Excel (.xlsx)', 'pdf' => 'PDF (.pdf)'])->required()->live()->afterStateUpdated($invalidate),
            $this->productFilter('brand_ids', 'Márkák')->options(fn () => Brand::where('active', true)->orderBy('name')->pluck('name', 'id'))
                ->live()->afterStateUpdated(fn (Get $get, Set $set) => $this->refreshCatalogSelection($get, $set))
                ->helperText('Több márka is kipipálható. Üresen: minden márka.'),
            $this->productFilter('item_main_group_ids', 'Főcsoportok')->options(fn () => ItemMainGroup::where('active', true)->orderBy('code')->get()
                ->mapWithKeys(fn ($row) => [$row->id => $row->code.' - '.($row->name_hu ?: $row->name)]))
                ->live()->afterStateUpdated(fn (Get $get, Set $set) => $this->refreshCatalogSelection($get, $set))
                ->helperText('Több főcsoport is kipipálható. Üresen: minden főcsoport.'),
            $this->productFilter('catalog_groups', 'Katalóguscsoportok')
                ->options(fn (Get $get) => app(PartnerPriceListService::class)->catalogGroups($get('season_id') ? (int) $get('season_id') : null, $get('brand_ids') ?? [], $get('item_main_group_ids') ?? []))
                ->live()->afterStateUpdated($invalidate)->helperText('Több csoport is kipipálható. Üresen: minden katalóguscsoport. A három szűrő együtt érvényesül.'),
        ])->columns(['default' => 1, 'md' => 2, 'xl' => 3])->statePath('data');
    }

    private function productFilter(string $name, string $label): CheckboxList
    {
        return CheckboxList::make($name)->label($label)->searchable()->searchPrompt('Keresés...')->bulkToggleable()->columns(1)
            ->selectAllAction(fn (Action $action) => $action->label('Mindent kijelöl'))
            ->deselectAllAction(fn (Action $action) => $action->label('Kijelölés visszavonása'))
            ->extraAttributes(['style' => 'max-height:14rem;overflow-y:auto;']);
    }

    private function refreshCatalogSelection(Get $get, Set $set): void
    {
        $available = app(PartnerPriceListService::class)->catalogGroups($get('season_id') ? (int) $get('season_id') : null,
            $get('brand_ids') ?? [], $get('item_main_group_ids') ?? []);
        $set('catalog_groups', array_values(array_intersect($get('catalog_groups') ?? [], array_keys($available))));
        $this->clearPreview();
    }

    public function clearPreview(): void
    {
        $this->appliedFilters = null;
        $this->selectedTableRecords = $this->deselectedTableRecords = [];
        $this->isTrackingDeselectedTableRecords = false;
        $this->resetTable();
    }

    public function loadProducts(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->appliedFilters = app(PartnerPriceListService::class)->validateConfiguration($this->form->getState());
        $this->tableSearch = '';
        $this->resetTable();
        $this->selectAllProducts();
    }

    public function selectAllProducts(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->isTrackingDeselectedTableRecords = false;
        $this->deselectedTableRecords = [];
        $this->selectedTableRecords = $this->getFilteredTableQuery()->pluck('products.id')->map(fn ($id) => (string) $id)->all();
        unset($this->cachedSelectedTableRecords);
        $this->dispatch('partner-price-list-selection', componentId: $this->getId(), ids: $this->selectedTableRecords);
    }

    public function clearProductSelection(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->selectedTableRecords = $this->deselectedTableRecords = [];
        $this->isTrackingDeselectedTableRecords = false;
        $this->deselectAllTableRecords();
        unset($this->cachedSelectedTableRecords);
    }

    public function table(Table $table): Table
    {
        return $table->query(function () {
            abort_unless(static::canAccess(), 403);

            return app(PartnerPriceListService::class)->productsQuery($this->appliedFilters);
        })
            ->columns([
                TextColumn::make('model_code')->label('Cikkszám')->searchable()->sortable(),
                TextColumn::make('name_hu')->label('Megnevezés')
                    ->getStateUsing(fn ($record) => ($this->appliedFilters['locale'] ?? 'hu') === 'en' ? ($record->name_en ?: $record->name_hu) : ($record->name_hu ?: $record->name_en))
                    ->searchable(query: fn ($query, string $search) => $query->where(fn ($query) => $query->where('name_hu', 'like', '%'.$search.'%')->orWhere('name_en', 'like', '%'.$search.'%')))
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy(($this->appliedFilters['locale'] ?? 'hu') === 'en' ? 'name_en' : 'name_hu', $direction)),
                TextColumn::make('name_en')->label('Megnevezés EN')->searchable()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('brand.name')->label('Márka')->sortable(),
                TextColumn::make('itemMainGroup.name_hu')->label('Főcsoport')->sortable(),
                TextColumn::make('catalog_group_name_hu')->label('Katalóguscsoport')
                    ->getStateUsing(fn ($record) => ($this->appliedFilters['locale'] ?? 'hu') === 'en' ? ($record->catalog_group_name_en ?: $record->catalog_group_name_hu) : ($record->catalog_group_name_hu ?: $record->catalog_group_name_en))
                    ->searchable()->sortable(),
                TextColumn::make('export_net_price')->label('Nettó ár')->numeric(decimalPlaces: 2, decimalSeparator: ',', thousandsSeparator: ' ')->placeholder('Hiányzó ár')->alignEnd()->sortable(),
                TextColumn::make('export_retail_price')->label('Bruttó kisker ár')->numeric(decimalPlaces: 2, decimalSeparator: ',', thousandsSeparator: ' ')->placeholder('Hiányzó ár')->alignEnd()->sortable()
                    ->visible(fn () => (bool) ($this->appliedFilters['retail_price_list_id'] ?? null)),
                TextColumn::make('promised_delivery_date')->label('Várható beérkezés')->date('Y.m.d')->sortable(),
                TextColumn::make('catalog_page')->label('Katalógusoldal')->sortable(),
            ])->selectable()->trackDeselectedRecords(false)->currentSelectionLivewireProperty('selectedTableRecords')
            ->extraAttributes(['x-on:partner-price-list-selection.window' => 'if ($event.detail.componentId === $wire.$id) { selectedRecords = new Set($event.detail.ids.map(String)); deselectedRecords = new Set(); isTrackingDeselectedRecords = false; updatedSelectedRecords() }'])
            ->recordActions([])->recordAction(null)->recordUrl(null)
            ->defaultSort('model_code')->defaultPaginationPageOption(100)->paginationPageOptions([50, 100, 250, 500])
            ->emptyStateHeading('Nincs megjeleníthető termék')->emptyStateDescription('Állítsd be a feltételeket, és töltsd be a terméklistát.');
    }

    public function exportPriceList(): mixed
    {
        abort_unless(static::canAccess(), 403);
        if (! $this->appliedFilters) {
            throw ValidationException::withMessages(['selection' => 'Először töltsd be a terméklistát.']);
        }
        $ids = $this->getSelectedTableRecords()->modelKeys();
        $document = app(PartnerPriceListService::class)->document($this->appliedFilters, $ids);
        $filename = 'partner-arlista-'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $document['season_code']).'-'.strtoupper($document['locale']);
        if ($this->appliedFilters['format'] === 'xlsx') {
            return Excel::download(new PriceListWorkbook($document), $filename.'.xlsx');
        }

        $pdf = app(PartnerPriceListPdf::class)->render($document);

        return response()->streamDownload(static fn () => print ($pdf), $filename.'.pdf', ['Content-Type' => 'application/pdf']);
    }
}

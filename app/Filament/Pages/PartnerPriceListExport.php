<?php

namespace App\Filament\Pages;

use App\Exports\PartnerPriceListExport as PriceListWorkbook;
use App\Filament\Support\AdminResourceTable;
use App\Models\Brand;
use App\Models\ItemMainGroup;
use App\Models\Language;
use App\Models\PriceList;
use App\Models\Season;
use App\Services\PartnerPriceListPdf;
use App\Services\PartnerPriceListService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
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
use Livewire\Attributes\Computed;
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

    public bool $settingsOpen = true;

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
                ->with('currency')->orderBy('code')->get()->mapWithKeys(fn ($row) => [$row->id => $row->code.' - '.$row->translate('name').' ('.$row->currency?->code.')']))
                ->required()->searchable()->live()->afterStateUpdated(function ($state, Set $set): void {
                    $list = PriceList::find($state);
                    $retail = $list?->retailPriceList;
                    $set('retail_price_list_id', $retail?->active && in_array($retail?->type, ['retail', 'outlet'], true) ? $retail->id : null);
                    $this->clearPreview();
                }),
            Select::make('retail_price_list_id')->label('Bruttó kisker / outlet árlista (opcionális)')
                ->options(fn () => PriceList::where('active', true)->whereIn('type', ['retail', 'outlet'])->with('currency')->orderBy('code')->get()
                    ->mapWithKeys(fn ($list) => [$list->id => $list->code.' ('.$list->currency?->code.')']))
                ->searchable()->live()->afterStateUpdated($invalidate)
                ->hintIcon('heroicon-o-information-circle', tooltip: 'Alapból a kapcsolt árlista. Bármely aktív kisker vagy outlet választható, eltérő pénznemben is. Átváltás nem történik.'),
            Select::make('language_id')->label('Fájl nyelve')->options(fn () => Language::where('active', true)
                ->whereRaw('UPPER(code) IN (?, ?)', PartnerPriceListService::LANGUAGES)->orderBy('code')->get()->mapWithKeys(fn ($row) => [$row->id => strtoupper($row->code).' - '.$row->translate('name')]))
                ->required()->live()->afterStateUpdated($invalidate),
            Select::make('format')->label('Formátum')->options(['xlsx' => 'Excel (.xlsx)', 'pdf' => 'PDF (.pdf)'])->required()->live()->afterStateUpdated($invalidate),
            $this->productFilter('brand_ids', 'Márkák')->options(fn () => Brand::where('active', true)->orderBy('name')->pluck('name', 'id'))
                ->live()->afterStateUpdated(fn (Get $get, Set $set) => $this->refreshCatalogSelection($get, $set)),
            $this->productFilter('item_main_group_ids', 'Főcsoportok')->options(fn () => ItemMainGroup::where('active', true)->orderBy('code')->get()
                ->mapWithKeys(fn ($row) => [$row->id => $row->code.' - '.$row->translate('name')]))
                ->live()->afterStateUpdated(fn (Get $get, Set $set) => $this->refreshCatalogSelection($get, $set)),
            $this->productFilter('catalog_groups', 'Katalóguscsoportok')
                ->options(fn (Get $get) => app(PartnerPriceListService::class)->catalogGroups($get('season_id') ? (int) $get('season_id') : null, $get('brand_ids') ?? [], $get('item_main_group_ids') ?? []))
                ->live()->afterStateUpdated($invalidate),
        ])->columns(['default' => 1, 'md' => 2, 'xl' => 4])->statePath('data');
    }

    private function productFilter(string $name, string $label): Select
    {
        return Select::make($name)->label($label)->multiple()->searchable()->preload()
            ->placeholder('Mindegyik')->searchPrompt('Keresés...')
            ->hintIcon('heroicon-o-information-circle', tooltip: 'Több érték választható. Üresen: mindegyik. A termékszűrők együtt érvényesülnek.')
            ->extraAttributes(['class' => 'partner-price-list-product-filter']);
    }

    #[Computed]
    public function appliedSummary(): array
    {
        if (! $this->appliedFilters) {
            return [];
        }

        $filters = $this->appliedFilters;
        $lists = PriceList::with('currency')->whereIn('id', array_filter([
            $filters['price_list_id'], $filters['retail_price_list_id'],
        ]))->get()->keyBy('id');
        $listLabel = fn ($id) => ($list = $lists->get($id)) ? $list->code.' ('.$list->currency?->code.')' : 'Nincs';

        return [
            'Szezon' => Season::find($filters['season_id'])?->code,
            'Nettó' => $listLabel($filters['price_list_id']),
            'Kisker / outlet' => $listLabel($filters['retail_price_list_id']),
            'Fájl' => strtoupper($filters['locale']).' / '.strtoupper($filters['format']),
            'Márka' => count($filters['brand_ids']) ?: 'Mindegyik',
            'Főcsoport' => count($filters['item_main_group_ids']) ?: 'Mindegyik',
            'Katalóguscsoport' => count($filters['catalog_groups']) ?: 'Mindegyik',
        ];
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
        $this->settingsOpen = true;
        $this->appliedFilters = null;
        unset($this->appliedSummary);
        $this->selectedTableRecords = $this->deselectedTableRecords = [];
        $this->isTrackingDeselectedTableRecords = false;
        AdminResourceTable::refreshPreservingState($this);
    }

    public function loadProducts(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->settingsOpen = true;
        $this->appliedFilters = app(PartnerPriceListService::class)->validateConfiguration($this->form->getState());
        unset($this->appliedSummary);
        AdminResourceTable::refreshPreservingState($this);
        $this->selectAllProducts();
        $this->settingsOpen = false;
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
        return AdminResourceTable::configureColumnManager($table)->query(function () {
            abort_unless(static::canAccess(), 403);

            return app(PartnerPriceListService::class)->productsQuery($this->appliedFilters);
        })
            ->columns([
                TextColumn::make('model_code')->label('Cikkszám')->searchable()->sortable(),
                TextColumn::make('name_hu')->label('Megnevezés')
                    ->searchable()->sortable(),
                TextColumn::make('brand.name')->label('Márka')->sortable(),
                TextColumn::make('itemMainGroup.name')->label('Főcsoport')->sortable(),
                TextColumn::make('catalog_group_name_hu')->label('Katalóguscsoport')
                    ->searchable()->sortable(),
                TextColumn::make('export_net_price')->label('Nettó ár')->numeric(decimalPlaces: 2, decimalSeparator: ',', thousandsSeparator: ' ')->placeholder('Hiányzó ár')->alignEnd()->sortable(),
                TextColumn::make('export_retail_price')->label('Bruttó kisker ár')->numeric(decimalPlaces: 2, decimalSeparator: ',', thousandsSeparator: ' ')->placeholder('Hiányzó ár')->alignEnd()->sortable()
                    ->visible(fn () => (bool) ($this->appliedFilters['retail_price_list_id'] ?? null)),
                TextColumn::make('promised_delivery_date')->label('Várható beérkezés')->date('Y.m.d')->sortable(),
                TextColumn::make('catalog_page')->label('Katalógusoldal')->sortable(),
            ])->selectable()->trackDeselectedRecords(false)->currentSelectionLivewireProperty('selectedTableRecords')
            ->extraAttributes(['x-on:partner-price-list-selection.window' => 'if ($event.detail.componentId === $wire.$id) { selectedRecords = new Set($event.detail.ids.map(String)); deselectedRecords = new Set(); isTrackingDeselectedRecords = false; updatedSelectedRecords() }'], merge: true)
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
        try {
            $document = app(PartnerPriceListService::class)->document($this->appliedFilters, $ids);
        } catch (ValidationException $exception) {
            if (collect(array_keys($exception->errors()))->contains(fn ($key) => str_starts_with($key, 'data.'))) {
                $this->settingsOpen = true;
            }

            throw $exception;
        }
        $filename = 'partner-arlista-'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $document['season_code']).'-'.strtoupper($document['locale']);
        if ($this->appliedFilters['format'] === 'xlsx') {
            return Excel::download(new PriceListWorkbook($document), $filename.'.xlsx');
        }

        $pdf = app(PartnerPriceListPdf::class)->render($document);

        return response()->streamDownload(static fn () => print ($pdf), $filename.'.pdf', ['Content-Type' => 'application/pdf']);
    }
}

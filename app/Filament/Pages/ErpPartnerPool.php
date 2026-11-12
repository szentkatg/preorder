<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminResourceTable;
use App\Filament\Support\Concerns\HasPricingColumnPreferences;
use App\Models\Brand;
use App\Models\Currency;
use App\Models\Language;
use App\Models\OrderSheetType;
use App\Models\Partner;
use App\Models\PartnerAddress;
use App\Models\PartnerPool;
use App\Models\PartnerPoolSyncState;
use App\Models\PriceList;
use App\Models\Supplier;
use App\Services\Imports\PartnerPoolImporter;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

class ErpPartnerPool extends Page implements HasTable
{
    use HasPageShield;
    use HasPricingColumnPreferences, InteractsWithTable {
        HasPricingColumnPreferences::loadTableColumnsFromSession insteadof InteractsWithTable;
        HasPricingColumnPreferences::persistTableColumns insteadof InteractsWithTable;
        HasPricingColumnPreferences::persistHasReorderedTableColumns insteadof InteractsWithTable;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cloud-arrow-down';

    protected static string|\UnitEnum|null $navigationGroup = 'Törzsadatok';

    protected static ?string $title = 'ERP-partnerpool';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.erp-partner-pool';

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->pricingColumnWidths = $this->loadPricingColumnWidths();
    }

    protected function pricingPreferencePrefix(): string
    {
        return 'erp_partner_pool';
    }

    #[Computed]
    public function syncState(): ?PartnerPoolSyncState
    {
        return PartnerPoolSyncState::find(1);
    }

    public function table(Table $table): Table
    {
        $table->query(PartnerPool::currentSnapshot()->with(['customer', 'supplier']))
            ->columns([
                TextColumn::make('erp_partner_code')->label('ERP partnerkód')->searchable()->sortable(),
                TextColumn::make('partner_name')->label('Partner')->searchable()->sortable(),
                TextColumn::make('short_name')->label('Rövid név')->searchable()->sortable(),
                TextColumn::make('partner_type')->label('Típus')->badge()
                    ->formatStateUsing(fn (string $state): string => ['customer' => 'Vevő', 'supplier' => 'Beszállító', 'both' => 'Mindkettő'][$state] ?? $state),
                TextColumn::make('addrid')->label('Címkód')->searchable()->sortable(),
                TextColumn::make('address_name')->label('Cím neve')->searchable()->sortable(),
                TextColumn::make('address_type')->label('ERP címtípus')->placeholder('Nincs megadva'),
                TextColumn::make('country')->label('ERP ország')->sortable(),
                TextColumn::make('zip')->label('Irányítószám')->sortable(),
                TextColumn::make('city')->label('Város')->searchable()->sortable(),
                TextColumn::make('street')->label('Utca')->searchable()->sortable(),
                TextColumn::make('contact_name')->label('Kapcsolattartó')->searchable()->sortable(),
                TextColumn::make('address_email')->label('Cím e-mail')->searchable()->sortable(),
                TextColumn::make('address_phone')->label('Cím telefon')->searchable()->sortable(),
                TextColumn::make('partner_email')->label('Partner e-mail')->searchable()->sortable(),
                TextColumn::make('partner_phone')->label('Partner telefon')->searchable()->sortable(),
                TextColumn::make('sales_rep_erp_partner_code')->label('Képviselő ERP-kód')->searchable()->sortable(),
                TextColumn::make('price_list_code')->label('Javasolt árlista')->placeholder('Nincs megadva'),
                TextColumn::make('currency_code')->label('Javasolt pénznem')->placeholder('Nincs megadva'),
                TextColumn::make('language_code')->label('Javasolt nyelv')->placeholder('Nincs megadva'),
                TextColumn::make('brand_codes')->label('Javasolt márkák')->placeholder('Nincs megadva'),
                TextColumn::make('order_sheet_type_codes')->label('Javasolt rendelőlaptípusok')->placeholder('Nincs megadva'),
                IconColumn::make('is_default')->label('Alapcím')->boolean()->sortable(),
                IconColumn::make('partner_active')->label('Partner aktív')->boolean()->sortable(),
                IconColumn::make('address_active')->label('Cím aktív')->boolean()->sortable(),
                TextColumn::make('customer.name')->label('Vevőtörzsben')->placeholder('Még nincs'),
                TextColumn::make('supplier.name')->label('Beszállítótörzsben')->placeholder('Még nincs'),
            ])
            ->filters([
                SelectFilter::make('partner_type')->label('Partnertípus')->multiple()
                    ->options(['customer' => 'Vevő', 'supplier' => 'Beszállító', 'both' => 'Mindkettő']),
                TernaryFilter::make('is_default')->label('Alapértelmezett cím'),
                TernaryFilter::make('partner_active')->label('Partner aktív'),
                TernaryFilter::make('address_active')->label('Cím aktív'),
            ])
            ->recordActions([$this->importAction()])
            ->recordActionsPosition(RecordActionsPosition::BeforeColumns)
            ->recordActionsColumnLabel('Átvétel')
            ->reorderableColumns()
            ->headerActions([Action::make('refreshPool')->label('Lista frissítése')->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    abort_unless(static::canAccess(), 403);
                    unset($this->syncState);
                    AdminResourceTable::refreshPreservingState($this);
                })])
            ->defaultSort('erp_partner_code')->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500]);

        return AdminResourceTable::configure($table, PartnerPool::class, false);
    }

    protected function importAction(): Action
    {
        $customer = fn (Get $get): bool => in_array($get('target_type'), ['customer', 'both'], true);

        return Action::make('importPartner')->label('Átvétel')->icon('heroicon-o-arrow-down-on-square')
            ->visible(fn (PartnerPool $record): bool => Gate::allows('Import:PartnerPool') && $record->partner_active
                && ($record->addrid === null || $record->address_active) && $this->allowedTargets($record) !== [])
            ->modalHeading('ERP-partner átvétele')->modalWidth(Width::FiveExtraLarge)
            ->modalDescription('A kijelölt cím és a partner vehető át. Meglévő törzsadatot és jogosultságot nem írunk felül; partnerfelhasználót nem hozunk létre. Beszállítónál ennek a sornak a címkódját és országát használjuk.')
            ->modalSubmitActionLabel('Átvétel jóváhagyása')->modalCancelActionLabel('Mégse')
            ->fillForm(function (PartnerPool $record): array {
                $targets = $this->allowedTargets($record);
                abort_if($targets === [], 403);

                return ['source_batch_id' => $record->batch_id,
                    'erp_code_preview' => $record->erp_partner_code, 'name_preview' => $record->partner_name,
                    'address_preview' => trim(($record->addrid ?? '').' — '.($record->address_name ?? '').' — '.($record->city ?? '').' '.($record->street ?? '')),
                    'address_type_preview' => $record->address_type]
                    + app(PartnerPoolImporter::class)->defaults($record, array_key_exists($record->partner_type, $targets)
                        ? $record->partner_type : array_key_first($targets));
            })
            ->schema([
                Hidden::make('source_batch_id')->required(),
                TextInput::make('erp_code_preview')->label('ERP partnerkód')->disabled()->dehydrated(false),
                TextInput::make('name_preview')->label('Partner neve')->disabled()->dehydrated(false),
                TextInput::make('address_preview')->label('Kiválasztott ERP-cím')->disabled()->dehydrated(false),
                TextInput::make('address_type_preview')->label('ERP címtípus (tájékoztató)')->disabled()->dehydrated(false)
                    ->visible(fn (Get $get): bool => filled($get('address_type_preview'))),
                Select::make('target_type')->label('Átvétel célja')->required()->live()
                    ->options(fn (PartnerPool $record): array => $this->allowedTargets($record)),
                TextInput::make('country')->label('Kétbetűs országkód')->required()->regex('/^[A-Za-z]{2}$/D')
                    ->helperText('Például HU vagy CN. Belső ERP-országazonosító helyett a kétbetűs kódot add meg.'),
                Section::make('Új vevőcím beállításai')->visible($customer)->columns(3)->schema([
                    Textarea::make('suggestion_notice')->label('ERP-javaslatok ellenőrzése')->rows(2)->columnSpanFull()
                        ->disabled()->dehydrated(false)->visible(fn (Get $get): bool => filled($get('suggestion_notice'))),
                    Select::make('price_list_id')->label('Árlista')->options(fn () => PriceList::where('active', true)->orderBy('code')->pluck('code', 'id'))
                        ->searchable()->required($customer),
                    Select::make('currency_id')->label('Pénznem')->options(fn () => Currency::where('active', true)->orderBy('code')->pluck('code', 'id'))
                        ->searchable()->required($customer),
                    Select::make('language_id')->label('Nyelv')->options(fn () => Language::where('active', true)->orderBy('code')->pluck('name', 'id'))
                        ->searchable()->required($customer),
                    Select::make('brand_ids')->label('Engedélyezett márkák')->multiple()->options(fn () => Brand::where('active', true)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()->required($customer),
                    Select::make('order_sheet_type_ids')->label('Engedélyezett rendelőlaptípusok')->multiple()
                        ->options(fn () => OrderSheetType::where('active', true)->orderBy('name')->pluck('name', 'id'))->searchable()->required($customer),
                    Toggle::make('allow_assortment_ordering')->label('Gyűjtős rendelés engedélyezett')->default(false),
                ]),
            ])
            ->action(function (PartnerPool $record, array $data): void {
                abort_unless(static::canAccess(), 403);
                $result = app(PartnerPoolImporter::class)->import($record->id, $data['source_batch_id'], $data);
                Notification::make()->success()->title($result['created'] ? 'Partner átvétele kész' : 'A kiválasztott törzsadatok már léteznek')
                    ->body('Létrehozott rekordok: '.count($result['created']).'. A meglévő adatok változatlanok maradtak.')->send();
                AdminResourceTable::refreshPreservingState($this);
            });
    }

    protected function allowedTargets(PartnerPool $row): array
    {
        $options = [];
        if ($row->permits('customer') && Gate::allows('create', Partner::class) && Gate::allows('create', PartnerAddress::class)) {
            $options['customer'] = 'Vevő';
        }
        if ($row->permits('supplier') && Gate::allows('create', Supplier::class)) {
            $options['supplier'] = 'Beszállító';
        }
        if (count($options) === 2) {
            $options['both'] = 'Vevő és beszállító';
        }

        return $options;
    }
}

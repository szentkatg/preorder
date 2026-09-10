<?php

namespace App\Filament\Pages;

use App\Exports\StockOrderProportioningPreviewExport;
use App\Models\Brand;
use App\Models\Order;
use App\Models\OrderSheetType;
use App\Models\Season;
use App\Services\Orders\StockOrderProportioningService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class StockOrderProportioning extends Page implements
    Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;
    use HasPageShield;

    protected static string|\BackedEnum|null $navigationIcon =
        'heroicon-o-calculator';

    protected static string|\UnitEnum|null $navigationGroup =
        'Rendelések';

    protected static ?string $navigationLabel =
        'Készletrendelés arányosítása';

    protected static ?string $title =
        'Készletrendelés arányosítása';

    protected static ?int $navigationSort = 20;

    protected string $view =
        'filament.pages.stock-order-proportioning';

    public ?array $data = [];

    /**
     * Csak a rövid összesítés kerül a Livewire állapotába.
     */
    public array $preview = [];

    /**
     * Az előnézeti és mentési cache-adatok közös azonosítója.
     */
    public ?string $previewToken = null;

    public int $previewPage = 1;

    public ?string $errorMessage = null;

    protected int $previewPageSize = 20;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('season_id')
                    ->label('Szezon')
                    ->options(
                        Season::query()
                            ->orderByDesc('id')
                            ->get()
                            ->mapWithKeys(
                                fn (Season $season): array => [
                                    $season->id => trim(
                                        collect([
                                            $season->code,
                                            $season->name,
                                        ])
                                            ->filter()
                                            ->implode(' | ')
                                    ),
                                ]
                            )
                            ->all()
                    )
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(
                        function (Set $set): void {
                            $set('ratio_order_id', null);
                            $set('stock_order_id', null);

                            $this->resetPreview();
                        }
                    )
                    ->required(),

                Forms\Components\Select::make('brand_id')
                    ->label('Márka')
                    ->options(
                        Brand::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()
                    )
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(
                        function (Set $set): void {
                            $set('ratio_order_id', null);
                            $set('stock_order_id', null);

                            $this->resetPreview();
                        }
                    )
                    ->required(),

                Forms\Components\Select::make(
                    'order_sheet_type_id'
                )
                    ->label('Rendelőlap típusa')
                    ->options(
                        OrderSheetType::query()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(
                                fn (
                                    OrderSheetType $type
                                ): array => [
                                    $type->id => trim(
                                        collect([
                                            $type->code,
                                            $type->translate('name'),
                                        ])
                                            ->filter()
                                            ->implode(' | ')
                                    ),
                                ]
                            )
                            ->all()
                    )
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(
                        function (Set $set): void {
                            $set('ratio_order_id', null);
                            $set('stock_order_id', null);

                            $this->resetPreview();
                        }
                    )
                    ->required(),

                Forms\Components\Select::make('ratio_order_id')
                    ->label('Arányrendelés')
                    ->helperText(
                        'A méretenként megadott mennyiségek '
                        . 'elosztási arányként lesznek használva.'
                    )
                    ->searchable()
                    ->live()
                    ->getSearchResultsUsing(
                        fn (
                            string $search,
                            Get $get
                        ): array => $this->searchOrders(
                            $search,
                            $get('season_id'),
                            $get('brand_id'),
                            $get('order_sheet_type_id')
                        )
                    )
                    ->getOptionLabelUsing(
                        fn ($value): ?string =>
                            $this->getOrderLabel(
                                (int) $value
                            )
                    )
                    ->disabled(
                        fn (Get $get): bool =>
                            ! $this->scopeIsSelected($get)
                    )
                    ->afterStateUpdated(
                        function (): void {
                            $this->resetPreview();
                        }
                    )
                    ->required(),

                Forms\Components\Select::make('stock_order_id')
                    ->label('Készletrendelés')
                    ->helperText(
                        'A rendelés jelenlegi összmennyisége lesz '
                        . 'a tervezett készlet. Felülíráskor a '
                        . 'számított korrekciós mennyiségek kerülnek '
                        . 'a rendelésbe.'
                    )
                    ->searchable()
                    ->live()
                    ->getSearchResultsUsing(
                        fn (
                            string $search,
                            Get $get
                        ): array => $this->searchOrders(
                            $search,
                            $get('season_id'),
                            $get('brand_id'),
                            $get('order_sheet_type_id')
                        )
                    )
                    ->getOptionLabelUsing(
                        fn ($value): ?string =>
                            $this->getOrderLabel(
                                (int) $value
                            )
                    )
                    ->disabled(
                        fn (Get $get): bool =>
                            ! $this->scopeIsSelected($get)
                    )
                    ->afterStateUpdated(
                        function (): void {
                            $this->resetPreview();
                        }
                    )
                    ->required(),
            ])
            ->columns(3)
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calculate')
                ->label('Számítás és előnézet')
                ->icon('heroicon-o-calculator')
                ->action('calculatePreview'),

            Action::make('exportPreview')
                ->label('Előnézet exportálása Excelbe')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(
                    fn (): bool =>
                        $this->previewToken !== null
                        && ! ($this->preview['applied'] ?? false)
                )
                ->action('exportPreview'),

            Action::make('apply')
                ->label('Készletrendelés felülírása')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->visible(
                    fn (): bool =>
                        ! empty($this->preview)
                        && ! (
                            $this->preview['applied']
                            ?? false
                        )
                        && filled($this->previewToken)
                )
                ->requiresConfirmation()
                ->modalHeading(
                    'Készletrendelés felülírása'
                )
                ->modalDescription(
                    'A kiválasztott készletrendelés jelenlegi '
                    . 'tételei törlődnek, és pontosan az előnézet '
                    . 'alapján elkészített korrekciós mennyiségek '
                    . 'kerülnek a helyükre. A negatív mennyiségek '
                    . 'is mentésre kerülnek.'
                )
                ->modalSubmitActionLabel('Felülírás')
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:target' => 'applyCalculation',
                ])
                ->action('applyCalculation'),
        ];
    }

    public function calculatePreview(
        StockOrderProportioningService $service
    ): void {
        $this->resetPreview();

        try {
            $this->form->validate();

            $ratioOrderId = (int) (
                $this->data['ratio_order_id'] ?? 0
            );

            $stockOrderId = (int) (
                $this->data['stock_order_id'] ?? 0
            );

            $result = $service->preview(
                $ratioOrderId,
                $stockOrderId
            );

            $groups = $result['groups'] ?? [];
            $writePayload =
                $result['write_payload'] ?? null;

            if (! is_array($writePayload)) {
                throw new \RuntimeException(
                    'A számítás nem készített mentési csomagot.'
                );
            }

            $this->previewToken =
                (string) Str::uuid();

            $this->previewPage = 1;

            /*
             * A részletes, megjelenítésre szolgáló előnézet külön
             * cache-be kerül. Ebből egyszerre csak egy lapot olvasunk.
             */
            Cache::put(
                $this->previewGroupsCacheKey(
                    $this->previewToken
                ),
                $groups,
                now()->addMinutes(30)
            );

            /*
             * A mentéshez szükséges tömör csomagot külön tároljuk.
             * Ebben csak scope, summary, sku_id és quantity van.
             */
            Cache::put(
                $this->previewWriteCacheKey(
                    $this->previewToken
                ),
                $writePayload,
                now()->addMinutes(30)
            );

            /*
             * A publikus Livewire állapotban kizárólag a rövid
             * összesítés és a lapozási információ marad.
             */
            $this->preview = [
                'scope' =>
                    $result['scope'] ?? [],
                'summary' =>
                    $result['summary'] ?? [],
                'total_group_count' =>
                    count($groups),
                'applied' => false,
            ];

            Notification::make()
                ->title('A számítás elkészült')
                ->body(
                    (
                        $this->preview[
                            'total_group_count'
                        ] ?? 0
                    )
                    . ' termék–szín kombináció került '
                    . 'feldolgozásra.'
                )
                ->success()
                ->send();
        } catch (Throwable $e) {
            $this->resetPreview();

            $this->errorMessage =
                $e->getMessage();

            Notification::make()
                ->title('A számítás sikertelen')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function exportPreview(
        StockOrderProportioningPreviewExport $export
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        if (! $this->previewToken) {
            throw new \RuntimeException(
                'Nincs exportálható előnézet. '
                . 'Először futtasd le a számítást.'
            );
        }

        $groups = Cache::get(
            $this->previewGroupsCacheKey(
                $this->previewToken
            )
        );

        if (! is_array($groups)) {
            throw new \RuntimeException(
                'Az előnézet lejárt vagy már nem található. '
                . 'Futtasd le újra a számítást.'
            );
        }

        return $export->download([
            'scope' => $this->preview['scope'] ?? [],
            'summary' => $this->preview['summary'] ?? [],
            'groups' => $groups,
        ]);
    }
    
    public function applyCalculation(
        StockOrderProportioningService $service
    ): void {
        $this->errorMessage = null;

        try {
            $this->form->validate();

            $ratioOrderId = (int) (
                $this->data['ratio_order_id'] ?? 0
            );

            $stockOrderId = (int) (
                $this->data['stock_order_id'] ?? 0
            );

            if (! $this->previewToken) {
                throw new \RuntimeException(
                    'Nincs érvényes előnézet. '
                    . 'Először futtasd le a számítást.'
                );
            }

            $token = $this->previewToken;

            $writeCacheKey =
                $this->previewWriteCacheKey($token);

            $groupsCacheKey =
                $this->previewGroupsCacheKey($token);

            /*
             * Az aktuális Livewire példányban azonnal érvénytelenítjük
             * a tokent, hogy a gomb ne maradjon használható.
             */
            $this->previewToken = null;

            /*
             * A mentési csomagot egyszer használjuk fel.
             * A következő kérés már nem találja meg ugyanazt a tokent.
             */
            $writePayload =
                Cache::pull($writeCacheKey);

            if (! is_array($writePayload)) {
                throw new \RuntimeException(
                    'Az előnézet már felhasználásra került, '
                    . 'lejárt vagy nem található. '
                    . 'Futtasd le újra a számítást.'
                );
            }

            /*
             * A megjelenítési cache-re mentés után már nincs szükség.
             */
            Cache::forget($groupsCacheKey);

            $this->preview = [];
            $this->previewPage = 1;

            /*
             * A service nem számol újra.
             * Csak a tömör, korábban elkészített mentési csomagot
             * írja vissza a készletrendelésbe.
             */
            $result = $service->apply(
                $writePayload,
                $ratioOrderId,
                $stockOrderId
            );

            $this->preview = [
                'scope' =>
                    $result['scope'] ?? [],
                'summary' =>
                    $result['summary'] ?? [],
                'total_group_count' => 0,
                'applied' => true,
            ];

            Notification::make()
                ->title(
                    'A készletrendelés felülírása megtörtént'
                )
                ->body(
                    'Új készletkorrekció összesen: '
                    . (
                        $this->preview['summary']
                            ['new_stock_quantity']
                        ?? 0
                    )
                    . ' db.'
                )
                ->success()
                ->send();
        } catch (Throwable $e) {
            /*
             * A korábban felhasznált tokent hiba esetén sem
             * állítjuk vissza. Új előnézetet kell készíteni.
             */
            $this->previewToken = null;
            $this->preview = [];
            $this->previewPage = 1;

            $this->errorMessage =
                $e->getMessage();

            Notification::make()
                ->title('A felülírás sikertelen')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function searchOrders(
        string $search,
        mixed $seasonId,
        mixed $brandId,
        mixed $orderSheetTypeId
    ): array {
        if (
            ! $seasonId
            || ! $brandId
            || ! $orderSheetTypeId
        ) {
            return [];
        }

        return Order::query()
            ->where('season_id', $seasonId)
            ->where('brand_id', $brandId)
            ->where(
                'order_sheet_type_id',
                $orderSheetTypeId
            )
            ->where(
                function (
                    Builder $query
                ) use ($search): void {
                    $query
                        ->where(
                            'id',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhereHas(
                            'partner',
                            fn (
                                Builder $partnerQuery
                            ): Builder =>
                                $partnerQuery
                                    ->where(
                                        'erp_partner_code',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'name',
                                        'like',
                                        "%{$search}%"
                                    )
                        )
                        ->orWhereHas(
                            'partnerAddress',
                            fn (
                                Builder $addressQuery
                            ): Builder =>
                                $addressQuery
                                    ->where(
                                        'addrid',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'name',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'city',
                                        'like',
                                        "%{$search}%"
                                    )
                        );
                }
            )
            ->with([
                'partner:id,erp_partner_code,name',
                'partnerAddress:id,addrid,name,city',
            ])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(
                fn (Order $order): array => [
                    $order->id =>
                        $this->formatOrderLabel($order),
                ]
            )
            ->all();
    }

    protected function getOrderLabel(
        int $orderId
    ): ?string {
        if ($orderId <= 0) {
            return null;
        }

        $order = Order::query()
            ->with([
                'partner:id,erp_partner_code,name',
                'partnerAddress:id,addrid,name,city',
            ])
            ->find($orderId);

        return $order
            ? $this->formatOrderLabel($order)
            : null;
    }

    protected function formatOrderLabel(
        Order $order
    ): string {
        return collect([
            "#{$order->id}",
            $order->partner?->erp_partner_code,
            $order->partner?->name,
            $order->partnerAddress?->addrid,
            $order->partnerAddress?->name,
            $order->partnerAddress?->city,
            $order->status,
        ])
            ->filter(
                fn (mixed $value): bool =>
                    $value !== null
                    && $value !== ''
            )
            ->implode(' | ');
    }

    protected function scopeIsSelected(
        Get $get
    ): bool {
        return filled($get('season_id'))
            && filled($get('brand_id'))
            && filled(
                $get('order_sheet_type_id')
            );
    }

    public function getDisplayedPreviewGroups(): array
    {
        if (! $this->previewToken) {
            return [];
        }

        $groups = Cache::get(
            $this->previewGroupsCacheKey(
                $this->previewToken
            )
        );

        if (! is_array($groups)) {
            return [];
        }

        $offset =
            ($this->previewPage - 1)
            * $this->previewPageSize;

        return array_slice(
            $groups,
            $offset,
            $this->previewPageSize
        );
    }

    public function getPreviewTotalPages(): int
    {
        $groupCount = (int) (
            $this->preview[
                'total_group_count'
            ] ?? 0
        );

        if ($groupCount === 0) {
            return 1;
        }

        return (int) ceil(
            $groupCount
            / $this->previewPageSize
        );
    }

    public function getPreviewFirstDisplayedNumber(): int
    {
        $groupCount = (int) (
            $this->preview[
                'total_group_count'
            ] ?? 0
        );

        if ($groupCount === 0) {
            return 0;
        }

        return (
            ($this->previewPage - 1)
            * $this->previewPageSize
        ) + 1;
    }

    public function getPreviewLastDisplayedNumber(): int
    {
        $groupCount = (int) (
            $this->preview[
                'total_group_count'
            ] ?? 0
        );

        return min(
            $this->previewPage
                * $this->previewPageSize,
            $groupCount
        );
    }

    public function previousPreviewPage(): void
    {
        if ($this->previewPage > 1) {
            $this->previewPage--;
        }
    }

    public function nextPreviewPage(): void
    {
        if (
            $this->previewPage
            < $this->getPreviewTotalPages()
        ) {
            $this->previewPage++;
        }
    }

    protected function previewCachePrefix(
        string $token
    ): string {
        return 'stock-order-proportioning:'
            . $token;
    }

    protected function previewGroupsCacheKey(
        string $token
    ): string {
        return $this->previewCachePrefix($token)
            . ':groups';
    }

    protected function previewWriteCacheKey(
        string $token
    ): string {
        return $this->previewCachePrefix($token)
            . ':write';
    }

    protected function forgetPreviewCache(): void
    {
        if (! $this->previewToken) {
            return;
        }

        Cache::forget(
            $this->previewGroupsCacheKey(
                $this->previewToken
            )
        );

        Cache::forget(
            $this->previewWriteCacheKey(
                $this->previewToken
            )
        );

        $this->previewToken = null;
    }

    protected function resetPreview(): void
    {
        $this->forgetPreviewCache();

        $this->preview = [];
        $this->previewPage = 1;
        $this->errorMessage = null;
    }
}

<?php

namespace App\Filament\Pages;

use App\Models\Brand;
use App\Models\Order;
use App\Models\OrderSheetType;
use App\Models\Season;
use App\Services\Orders\StockOrderProportioningService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Throwable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class StockOrderProportioning extends Page implements
    Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

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

    public array $preview = [];

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
                            ->mapWithKeys(fn (Season $season): array => [
                                $season->id => trim(
                                    collect([
                                        $season->code,
                                        $season->name,
                                    ])
                                        ->filter()
                                        ->implode(' | ')
                                ),
                            ])
                            ->all()
                    )
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('ratio_order_id', null);
                        $set('stock_order_id', null);
                        $this->resetPreview();
                    })
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
                    ->afterStateUpdated(function (Set $set): void {
                        $set('ratio_order_id', null);
                        $set('stock_order_id', null);
                        $this->resetPreview();
                    })
                    ->required(),

                Forms\Components\Select::make('order_sheet_type_id')
                    ->label('Rendelőlap típusa')
                    ->options(
                        OrderSheetType::query()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(
                                fn (OrderSheetType $type): array => [
                                    $type->id => trim(
                                        collect([
                                            $type->code,
                                            $type->name_hu,
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
                    ->afterStateUpdated(function (Set $set): void {
                        $set('ratio_order_id', null);
                        $set('stock_order_id', null);
                        $this->resetPreview();
                    })
                    ->required(),

                Forms\Components\Select::make('ratio_order_id')
                    ->label('Arányrendelés')
                    ->helperText(
                        'A méretenként megadott mennyiségek elosztási '
                        . 'arányként lesznek használva.'
                    )
                    ->searchable()
                    ->live()
                    ->getSearchResultsUsing(
                        fn (string $search, Get $get): array =>
                            $this->searchOrders(
                                $search,
                                $get('season_id'),
                                $get('brand_id'),
                                $get('order_sheet_type_id')
                            )
                    )
                    ->getOptionLabelUsing(
                        fn ($value): ?string =>
                            $this->getOrderLabel((int) $value)
                    )
                    ->disabled(
                        fn (Get $get): bool =>
                            ! $this->scopeIsSelected($get)
                    )
                    ->afterStateUpdated(function (): void {
                        $this->resetPreview();
                    })
                    ->required(),

                Forms\Components\Select::make('stock_order_id')
                    ->label('Készletrendelés')
                    ->helperText(
                        'A rendelés jelenlegi összmennyisége lesz a '
                        . 'tervezett készlet, majd a tételei felülíródnak.'
                    )
                    ->searchable()
                    ->live()
                    ->getSearchResultsUsing(
                        fn (string $search, Get $get): array =>
                            $this->searchOrders(
                                $search,
                                $get('season_id'),
                                $get('brand_id'),
                                $get('order_sheet_type_id')
                            )
                    )
                    ->getOptionLabelUsing(
                        fn ($value): ?string =>
                            $this->getOrderLabel((int) $value)
                    )
                    ->disabled(
                        fn (Get $get): bool =>
                            ! $this->scopeIsSelected($get)
                    )
                    ->afterStateUpdated(function (): void {
                        $this->resetPreview();
                    })
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

            Action::make('apply')
                ->label('Készletrendelés felülírása')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->visible(fn (): bool => ! empty($this->preview))
                ->requiresConfirmation()
                ->modalHeading('Készletrendelés felülírása')
                ->modalDescription(
                    'A kiválasztott készletrendelés jelenlegi tételei '
                    . 'törlődnek, és a frissen számított korrekciós '
                    . 'mennyiségek kerülnek a helyükre. A negatív '
                    . 'mennyiségek is mentésre kerülnek.'
                )
                ->modalSubmitActionLabel('Felülírás')
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

            $this->previewToken = (string) Str::uuid();
            $this->previewPage = 1;

            Cache::put(
                $this->previewCacheKey($this->previewToken),
                $result,
                now()->addMinutes(30)
            );

            /*
            * A Livewire publikus állapotában csak a rövid összesítés marad.
            * A teljes groups tömb a szerveroldali cache-be kerül.
            */
            $this->preview = [
                'scope' => $result['scope'] ?? [],
                'summary' => $result['summary'] ?? [],
                'total_group_count' => count(
                    $result['groups'] ?? []
                ),
                'applied' => false,
            ];

            Notification::make()
                ->title('A számítás elkészült')
                ->body(
                    ($this->preview['total_group_count'] ?? 0)
                    . ' termék–szín kombináció került feldolgozásra.'
                )
                ->success()
                ->send();
        } catch (Throwable $e) {
            $this->resetPreview();
            $this->errorMessage = $e->getMessage();

            Notification::make()
                ->title('A számítás sikertelen')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
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

            /*
            * A korábbi előnézetet eltávolítjuk a cache-ből.
            * A service a mentés előtt friss adatokból újraszámol mindent.
            */
            $this->forgetPreviewCache();

            $this->preview = [];
            $this->previewPage = 1;

            $result = $service->apply(
                $ratioOrderId,
                $stockOrderId
            );

            /*
            * A service visszaadhatja a teljes számítást is, de abból
            * kizárólag a rövid összesítést tesszük Livewire állapotba.
            */
            $this->preview = [
                'scope' => $result['scope'] ?? [],
                'summary' => $result['summary'] ?? [],
                'total_group_count' => 0,
                'applied' => true,
            ];

            Notification::make()
                ->title('A készletrendelés felülírása megtörtént')
                ->body(
                    'Új készletkorrekció összesen: '
                    . (
                        $this->preview['summary']
                            ['new_stock_quantity'] ?? 0
                    )
                    . ' db.'
                )
                ->success()
                ->send();
        } catch (Throwable $e) {
            $this->preview = [];
            $this->errorMessage = $e->getMessage();

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
            ->where(function (Builder $query) use ($search): void {
                $query
                    ->where('id', 'like', "%{$search}%")
                    ->orWhereHas(
                        'partner',
                        fn (Builder $partnerQuery): Builder =>
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
                        fn (Builder $addressQuery): Builder =>
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
            })
            ->with([
                'partner:id,erp_partner_code,name',
                'partnerAddress:id,addrid,name,city',
            ])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Order $order): array => [
                $order->id => $this->formatOrderLabel($order),
            ])
            ->all();
    }

    protected function getOrderLabel(int $orderId): ?string
    {
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

    protected function formatOrderLabel(Order $order): string
    {
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
                    $value !== null && $value !== ''
            )
            ->implode(' | ');
    }

    protected function scopeIsSelected(Get $get): bool
    {
        return filled($get('season_id'))
            && filled($get('brand_id'))
            && filled($get('order_sheet_type_id'));
    }

    protected function resetPreview(): void
    {
        $this->preview = [];
        $this->errorMessage = null;
    }
    public function getDisplayedPreviewGroups(): array
    {
        if (! $this->previewToken) {
            return [];
        }

        $result = Cache::get(
            $this->previewCacheKey($this->previewToken)
        );

        if (! is_array($result)) {
            return [];
        }

        $groups = $result['groups'] ?? [];

        $offset = ($this->previewPage - 1)
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
            $this->preview['total_group_count'] ?? 0
        );

        if ($groupCount === 0) {
            return 1;
        }

        return (int) ceil(
            $groupCount / $this->previewPageSize
        );
    }

    public function getPreviewFirstDisplayedNumber(): int
    {
        $groupCount = (int) (
            $this->preview['total_group_count'] ?? 0
        );

        if ($groupCount === 0) {
            return 0;
        }

        return (($this->previewPage - 1)
            * $this->previewPageSize) + 1;
    }

    public function getPreviewLastDisplayedNumber(): int
    {
        $groupCount = (int) (
            $this->preview['total_group_count'] ?? 0
        );

        return min(
            $this->previewPage * $this->previewPageSize,
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

    protected function previewCacheKey(string $token): string
    {
        return 'stock-order-proportioning:' . $token;
    }

    protected function forgetPreviewCache(): void
    {
        if (! $this->previewToken) {
            return;
        }

        Cache::forget(
            $this->previewCacheKey($this->previewToken)
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
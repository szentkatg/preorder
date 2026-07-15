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

    public ?string $errorMessage = null;

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

            $this->preview = $service->preview(
                $ratioOrderId,
                $stockOrderId
            );

            Notification::make()
                ->title('A számítás elkészült')
                ->body(
                    ($this->preview['summary']['group_count'] ?? 0)
                    . ' termék-szín kombináció került feldolgozásra.'
                )
                ->success()
                ->send();
        } catch (Throwable $e) {
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

            $this->preview = $service->apply(
                $ratioOrderId,
                $stockOrderId
            );

            /*
             * A visszaadott előnézet még a mentés alapjául szolgáló
             * számítást mutatja. Megjelöljük, hogy a mentés megtörtént.
             */
            $this->preview['applied'] = true;

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
}
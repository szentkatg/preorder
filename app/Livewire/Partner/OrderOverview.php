<?php

namespace App\Livewire\Partner;

use App\Models\Order;
use App\Services\Partner\PartnerSeasonContext;
use Livewire\Attributes\Locked;

class OrderOverview extends OrderSelector
{
    #[Locked]
    public ?int $selectedOrderId = null;

    public function hydrate(): void
    {
        abort_unless(auth('partner')->user()?->active && $this->selectedOrder !== null, 403);
    }

    public function mount(mixed $order = null): void
    {
        $this->seasonId = PartnerSeasonContext::id();
        $user = auth('partner')->user();
        abort_unless($user?->active, 403);
        $order = $user->accessibleOrdersQuery()->where('season_id', $this->seasonId)
            ->whereKey($order instanceof Order ? $order->id : $order)->first();
        abort_unless($order instanceof Order, 404);
        $this->selectedOrderId = (int) $order->id;
        $this->partnerAddressId = (int) $order->partner_address_id;
        $this->brandId = (int) $order->brand_id;
        $this->orderSheetTypeId = (int) $order->order_sheet_type_id;
        $this->orderTypeId = $order->order_type_id;
        $this->storeSelection();
        $this->setPartnerLocale();
    }

    public function render()
    {
        return view('livewire.partner.order-overview')->layout('components.layouts.app');
    }
}

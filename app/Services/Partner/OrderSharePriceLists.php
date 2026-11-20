<?php

namespace App\Services\Partner;

use App\Models\Order;
use App\Models\PartnerAddress;
use App\Models\PartnerUser;
use App\Models\PriceList;
use Illuminate\Database\Eloquent\Builder;

class OrderSharePriceLists
{
    public function forOrder(Order $order, ?PartnerUser $user = null): Builder
    {
        // Resolve the owner from the database, never from editable browser state.
        $partner = Order::query()->whereKey($order->getKey())->select('partner_id');
        $addresses = PartnerAddress::query()->where('active', true)
            ->whereIn('partner_id', $partner)->whereNotNull('price_list_id');

        if ($user?->role === PartnerUser::ROLE_ADDRESS_USER) {
            $addresses->whereIn('id', $user->accessibleAddressesQuery()->select('partner_addresses.id'));

            if (! $user->active || ! $user->canAccessOrder($order)) {
                $addresses->whereRaw('1 = 0');
            }
        }

        return PriceList::query()->where('active', true)
            ->whereIn('type', ['wholesale', 'distributor'])
            ->whereIn('id', $addresses->select('price_list_id'));
    }

    public function allows(Order $order, ?int $priceListId, ?PartnerUser $user = null): bool
    {
        return $priceListId !== null && $this->forOrder($order, $user)->whereKey($priceListId)->exists();
    }
}

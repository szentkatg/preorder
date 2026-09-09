<?php

namespace App\Services\Partner;

use App\Models\Order;
use App\Models\Partner;
use App\Models\PartnerAddress;
use App\Models\PartnerUser;
use Illuminate\Database\Eloquent\Builder;

class PartnerAccessService
{
    public function accessiblePartnersQuery(PartnerUser $user): Builder
    {
        $query = Partner::query();

        if ($user->role === PartnerUser::ROLE_PARTNER_ADMIN) {
            return $query->whereKey($user->partner_id);
        }

        if ($user->role === PartnerUser::ROLE_ADDRESS_USER) {
            return $query->whereIn(
                'partners.id',
                $user->addresses()
                    ->select('partner_addresses.partner_id')
                    ->distinct()
            );
        }

        if ($user->role === PartnerUser::ROLE_SALES_REP) {
            $salesRepErpPartnerCode = $user->partner?->erp_partner_code;

            return $query->where(function (Builder $query) use ($user, $salesRepErpPartnerCode): void {
                $query
                    ->whereIn(
                        'partners.id',
                        $user->partners()->select('partners.id')
                    )
                    ->orWhereIn(
                        'partners.id',
                        $user->addresses()
                            ->select('partner_addresses.partner_id')
                            ->distinct()
                    );

                if (filled($salesRepErpPartnerCode)) {
                    $query->orWhere(
                        'partners.sales_rep_erp_partner_code',
                        $salesRepErpPartnerCode
                    );
                }
            });
        }

        return $query->whereRaw('1 = 0');
    }

    public function accessibleAddressesQuery(PartnerUser $user): Builder
    {
        $query = PartnerAddress::query();

        if ($user->role === PartnerUser::ROLE_PARTNER_ADMIN) {
            return $query->where('partner_addresses.partner_id', $user->partner_id);
        }

        if ($user->role === PartnerUser::ROLE_ADDRESS_USER) {
            return $query->whereIn(
                'partner_addresses.id',
                $user->addresses()->select('partner_addresses.id')
            );
        }

        if ($user->role === PartnerUser::ROLE_SALES_REP) {
            $salesRepErpPartnerCode = $user->partner?->erp_partner_code;

            return $query->where(function (Builder $query) use ($user, $salesRepErpPartnerCode): void {
                $query
                    ->whereIn(
                        'partner_addresses.partner_id',
                        $user->partners()->select('partners.id')
                    )
                    ->orWhereIn(
                        'partner_addresses.id',
                        $user->addresses()->select('partner_addresses.id')
                    );

                if (filled($salesRepErpPartnerCode)) {
                    $query->orWhereIn(
                        'partner_addresses.partner_id',
                        Partner::query()
                            ->select('partners.id')
                            ->where(
                                'partners.sales_rep_erp_partner_code',
                                $salesRepErpPartnerCode
                            )
                    );
                }
            });
        }

        return $query->whereRaw('1 = 0');
    }

    public function accessibleOrdersQuery(PartnerUser $user): Builder
    {
        return Order::query()->whereIn(
            'orders.partner_address_id',
            $this->accessibleAddressesQuery($user)
                ->select('partner_addresses.id')
        );
    }

    public function canAccessPartner(PartnerUser $user, Partner $partner): bool
    {
        return $this->accessiblePartnersQuery($user)
            ->whereKey($partner->getKey())
            ->exists();
    }

    public function canAccessAddress(
        PartnerUser $user,
        PartnerAddress $address
    ): bool {
        return $this->accessibleAddressesQuery($user)
            ->whereKey($address->getKey())
            ->exists();
    }

    public function canAccessOrder(PartnerUser $user, Order $order): bool
    {
        return $this->accessibleOrdersQuery($user)
            ->whereKey($order->getKey())
            ->exists();
    }
}

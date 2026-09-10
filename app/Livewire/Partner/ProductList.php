<?php

namespace App\Livewire\Partner;

use App\Models\Brand;
use App\Models\OrderSheetType;
use App\Models\PartnerAddress;
use App\Models\Product;
use App\Models\Season;
use Livewire\Component;

class ProductList extends Component
{
    public Season $season;

    public PartnerAddress $address;

    public Brand $brand;

    public OrderSheetType $type;

    public function mount(
        Season $season,
        PartnerAddress $address,
        Brand $brand,
        OrderSheetType $type
    ): void {
        $partnerUser = auth('partner')->user();

        abort_unless(
            $partnerUser
                && $address->active
                && $address->partner?->active
                && $partnerUser->canAccessAddress($address)
                && $address->brands()->whereKey($brand->getKey())->exists()
                && $address->orderSheetTypes()->whereKey($type->getKey())->exists(),
            403
        );

        $this->season = $season;
        $this->address = $address;
        $this->brand = $brand;
        $this->type = $type;

        session([
            'partner_order_selector.season_id' => (int) $this->season->id,
            'partner_order_selector.partner_address_id' => (int) $this->address->id,
            'partner_order_selector.brand_id' => (int) $this->brand->id,
            'partner_order_selector.order_sheet_type_id' => (int) $this->type->id,
            'partner_order_selector.order_id' => null,
        ]);

        $this->redirectRoute('partner.orders.select', navigate: true);
    }

    public function render()
    {
        $catalogGroups = Product::query()
            ->where('season_id', $this->season->id)
            ->where('brand_id', $this->brand->id)
            ->where('order_sheet_type_id', $this->type->id)
            ->where('active', true)
            ->selectRaw('catalog_group_name_hu, MIN(catalog_group_sort) as catalog_group_sort')
            ->groupBy('catalog_group_name_hu')
            ->orderBy('catalog_group_sort')
            ->orderBy('catalog_group_name_hu')
            ->get();

        return view('livewire.partner.product-list', [
            'catalogGroups' => $catalogGroups,
        ])->layout('components.layouts.app');
    }
}

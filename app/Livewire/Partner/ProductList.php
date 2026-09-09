<?php

namespace App\Livewire\Partner;

use App\Models\Brand;
use App\Models\Order;
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

    public ?Order $order = null;

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

        $this->order = Order::firstOrCreate(
            [
                'season_id' => $this->season->id,
                'partner_id' => $this->address->partner_id,
                'partner_address_id' => $this->address->id,
                'brand_id' => $this->brand->id,
                'order_sheet_type_id' => $this->type->id,
                'status' => 'editing',
            ],
            [
                'price_list_id' => $this->address->price_list_id,
            ]
        );
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

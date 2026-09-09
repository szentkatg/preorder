<?php

namespace App\Livewire\Partner;

use App\Models\Brand;
use App\Models\PartnerAddress;
use App\Models\Season;
use Livewire\Component;

class BrandShow extends Component
{
    public Season $season;

    public PartnerAddress $address;

    public Brand $brand;

    public function mount(
        Season $season,
        PartnerAddress $address,
        Brand $brand
    ): void {
        $partnerUser = auth('partner')->user();

        abort_unless(
            $partnerUser
                && $address->active
                && $address->partner?->active
                && $partnerUser->canAccessAddress($address)
                && $address->brands()->whereKey($brand->getKey())->exists(),
            403
        );

        $this->season = $season;
        $this->address = $address;
        $this->brand = $brand;
    }

    public function render()
    {
        return view('livewire.partner.brand-show', [
            'orderSheetTypes' => $this->address
                ->orderSheetTypes()
                ->orderBy('name')
                ->get(),
        ]);
    }
}

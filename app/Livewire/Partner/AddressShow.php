<?php

namespace App\Livewire\Partner;

use App\Models\PartnerAddress;
use App\Models\Season;
use Livewire\Component;

class AddressShow extends Component
{
    public Season $season;

    public PartnerAddress $address;

    public function mount(
        Season $season,
        PartnerAddress $address
    ): void {
        $partnerUser = auth('partner')->user();

        abort_unless(
            $partnerUser
                && $address->active
                && $address->partner?->active
                && $partnerUser->canAccessAddress($address),
            403
        );

        $this->season = $season;
        $this->address = $address;
    }

    public function render()
    {
        return view('livewire.partner.address-show', [
            'brands' => $this->address
                ->brands()
                ->orderBy('name')
                ->get(),
        ]);
    }
}

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

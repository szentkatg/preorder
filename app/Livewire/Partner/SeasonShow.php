<?php

namespace App\Livewire\Partner;

use App\Models\Season;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SeasonShow extends Component
{
    public Season $season;

    public function mount(Season $season): void
    {
        $this->season = $season;
    }

    public function render()
    {
        $partnerUser = Auth::guard('partner')->user();

        return view('livewire.partner.season-show', [
            'partner' => $partnerUser->partner,
            'addresses' => $partnerUser->accessibleAddressesQuery()
                ->where('active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }
}

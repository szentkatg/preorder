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
        $partner = Auth::guard('partner')
            ->user()
            ->partner;

        return view('livewire.partner.season-show', [
            'partner' => $partner,
            'addresses' => $partner->addresses()
                ->where('active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
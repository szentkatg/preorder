<?php

namespace App\Livewire\Partner;

use App\Livewire\Partner\Concerns\RequiresSelectedPartnerSeason;
use App\Models\Season;
use App\Services\Partner\PartnerSeasonContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Dashboard extends Component
{
    use RequiresSelectedPartnerSeason;

    public function render()
    {
        return view('livewire.partner.dashboard', [
            'user' => Auth::guard('partner')->user(),
            'seasons' => Season::query()
                ->visibleToPartners()
                ->whereKey(PartnerSeasonContext::id())
                ->orderBy('deadline')
                ->get(),
        ]);
    }
}

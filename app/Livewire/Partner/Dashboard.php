<?php

namespace App\Livewire\Partner;

use App\Models\Season;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        return view('livewire.partner.dashboard', [
            'user' => Auth::guard('partner')->user(),
            'seasons' => Season::query()
                ->where('active', true)
                ->orderBy('deadline')
                ->get(),
        ]);
    }
}
<?php

namespace App\Livewire\Partner;

use App\Models\PartnerUser;
use App\Models\Season;
use App\Services\Partner\PartnerSeasonContext;
use Illuminate\Validation\Rule;
use Livewire\Component;

class SeasonSelector extends Component
{
    public ?int $seasonId = null;

    public function boot(): void
    {
        // Re-check access on every Livewire request, not only the initial page.
        abort_unless(PartnerUser::find(auth('partner')->id())?->active, 403);
        app()->setLocale((string) session('partner.locale', 'hu'));
    }

    public function mount(): void
    {
        $this->seasonId = PartnerSeasonContext::current()?->id;
        if ($this->seasonId === null) {
            $seasons = Season::query()->visibleToPartners()->get();
            $this->seasonId = $seasons->count() === 1 ? (int) $seasons->first()->id : null;
        }
    }

    public function selectSeason(): void
    {
        $this->validate([
            'seasonId' => ['required', 'integer', Rule::exists('seasons', 'id')->where('visible_to_partners', true)],
        ], ['seasonId.required' => __('partner.select_season_to_continue'), 'seasonId.exists' => __('partner.select_season_to_continue')]);

        PartnerSeasonContext::select($this->seasonId);
        $this->redirectRoute('partner.orders.select', navigate: true);
    }

    public function render()
    {
        return view('livewire.partner.season-selector', [
            'seasons' => Season::query()->visibleToPartners()->orderByDesc('id')->get(),
            'hasCurrentSeason' => PartnerSeasonContext::current() !== null,
        ]);
    }
}

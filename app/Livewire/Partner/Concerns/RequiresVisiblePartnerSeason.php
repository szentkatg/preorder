<?php

namespace App\Livewire\Partner\Concerns;

use App\Services\Partner\PartnerSeasonVisibility;

trait RequiresVisiblePartnerSeason
{
    public function hydrateRequiresVisiblePartnerSeason(): void
    {
        $this->assertPartnerSeasonVisible();
    }

    protected function assertPartnerSeasonVisible(?int $seasonId = null): void
    {
        PartnerSeasonVisibility::assertVisible($seasonId ?? (isset($this->order) ? $this->order->season_id : $this->season->id));
    }
}

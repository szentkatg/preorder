<?php

namespace Tests\Feature;

use App\Livewire\Partner\SalesRepOrderSummary;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalesRepOrderSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_is_isolated_and_lazy_loaded(): void
    {
        $season = Season::create(['code' => 'W27', 'name' => 'Winter', 'visible_to_partners' => true]);
        session(['partner.season_id' => $season->id]);
        Livewire::test(SalesRepOrderSummary::class)
            ->assertSet('summaryLoaded', false)
            ->assertSee(__('partner.loading'));
    }
}

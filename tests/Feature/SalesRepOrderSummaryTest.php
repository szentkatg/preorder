<?php

namespace Tests\Feature;

use App\Livewire\Partner\SalesRepOrderSummary;
use Livewire\Livewire;
use Tests\TestCase;

class SalesRepOrderSummaryTest extends TestCase
{
    public function test_summary_is_isolated_and_lazy_loaded(): void
    {
        Livewire::test(SalesRepOrderSummary::class)
            ->assertSet('summaryLoaded', false)
            ->assertSee(__('partner.loading'));
    }
}

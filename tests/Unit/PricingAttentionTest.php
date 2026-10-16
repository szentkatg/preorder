<?php

namespace Tests\Unit;

use App\Filament\Support\PricingGrid;
use App\Models\PricingCalculationRow;
use Tests\TestCase;

class PricingAttentionTest extends TestCase
{
    public function test_all_price_types_flag_pending_approval_and_review(): void
    {
        foreach (['retail', 'wholesale', 'distributor', 'outlet'] as $type) {
            foreach (['calculated', 'reviewed', 'approved', 'modified_approved', 'approved_round_2', 'approved_round_3'] as $status) {
                $row = new PricingCalculationRow(['price_type' => $type, 'status' => $status,
                    'final_price' => 70, 'base_price' => 100, 'outlet_discount_percent' => 20, 'requires_review' => false]);
                $this->assertSame(in_array($status, ['calculated', 'reviewed'], true), $row->needsPricingAttention(), "$type / $status");
                $row->requires_review = true;
                $this->assertTrue($row->needsPricingAttention());
            }
        }
    }

    public function test_cost_needs_no_approval_but_invalid_values_and_outlet_discount_need_attention(): void
    {
        $cost = new PricingCalculationRow(['price_type' => 'cost', 'status' => 'calculated', 'final_price' => 100]);
        $this->assertFalse($cost->needsPricingAttention());
        $cost->requires_review = true;
        $this->assertTrue($cost->needsPricingAttention());
        $cost->requires_review = false;
        foreach ([null, 0, -1] as $price) {
            $cost->final_price = $price;
            $this->assertTrue($cost->needsPricingAttention());
        }
        $outlet = new PricingCalculationRow(['price_type' => 'outlet', 'status' => 'approved',
            'final_price' => 90, 'base_price' => 100, 'outlet_discount_percent' => 20]);
        $this->assertTrue($outlet->needsPricingAttention());
    }

    public function test_distributor_warning_uses_selected_price_not_the_wholesale_source(): void
    {
        $source = new PricingCalculationRow(['price_type' => 'wholesale', 'status' => 'calculated', 'final_price' => 100]);
        $target = new PricingCalculationRow(['price_type' => 'distributor', 'status' => 'approved', 'final_price' => 80]);
        $page = new class($target)
        {
            public function __construct(public PricingCalculationRow $target) {}

            public function pricingEditableRow($record): PricingCalculationRow
            {
                return $this->target;
            }

            public function supportsOutletPricing(): bool
            {
                return false;
            }
        };
        $this->assertFalse(PricingGrid::needsAttention($source, $page));
        $target->requires_review = true;
        $this->assertTrue(PricingGrid::needsAttention($source, $page));
    }
}

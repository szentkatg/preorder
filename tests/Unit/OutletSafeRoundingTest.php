<?php

namespace Tests\Unit;

use App\Models\PricingRoundingRule;
use App\Services\Pricing\PricingRoundingService;
use Tests\TestCase;

class OutletSafeRoundingTest extends TestCase
{
    public function test_rounds_up_only_when_the_adjusted_ending_meets_the_discount(): void
    {
        $service = new PricingRoundingService;
        $rule = new PricingRoundingRule(['round_to' => 100, 'adjustment' => -10, 'mode' => 'nearest']);
        $this->assertSame(1590.0, $service->applyOutlet(1592, $rule));
        $this->assertSame(1490.0, $service->applyOutlet(1589.99, $rule));
        $this->assertSame(1590.0, $service->applyOutlet(1590, $rule));
        $rule->adjustment = 20;
        $this->assertSame(1520.0, $service->applyOutlet(1592, $rule));
        $rule->adjustment = 320;
        $this->assertSame(1520.0, $service->applyOutlet(1592, $rule));
    }

    public function test_decimal_steps_large_values_and_tiny_boundary_values_never_exceed_ceiling(): void
    {
        $service = new PricingRoundingService;
        foreach ([1, 10, 100, 1000, null] as $step) {
            foreach ([0, -.05, -10, 15] as $adjustment) {
                $rule = new PricingRoundingRule(['round_to' => $step, 'decimal_places' => 2, 'adjustment' => $adjustment]);
                foreach ([.0001, 1.25, 1592, 11193, 999999999.9999] as $ceiling) {
                    $this->assertLessThanOrEqual($ceiling, $service->applyOutlet($ceiling, $rule));
                }
            }
        }
        $rule = new PricingRoundingRule(['decimal_places' => 0, 'adjustment' => -.05]);
        $this->assertSame(14.95, $service->applyOutlet(14.96, $rule));
        $this->assertSame(13.95, $service->applyOutlet(14.94, $rule));
    }
}

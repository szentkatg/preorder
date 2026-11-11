<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\PartnerPriceListExport;
use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Pages\PricingDistributorCalculations;
use App\Filament\Pages\PricingForeignRetailCalculations;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Filament\Pages\PricingHufRetailPriceDistribution;
use App\Filament\Pages\PricingProductOverview;
use App\Filament\Pages\PricingProductWorkspace;
use App\Filament\Pages\PricingWholesaleCalculations;
use App\Filament\Pages\PricingWorkflow;
use App\Filament\Resources\PricingCalculationErrors\Pages\ListPricingCalculationErrors;
use App\Filament\Resources\PricingCalculationRows\Pages\ListPricingCalculationRows;
use App\Filament\Resources\PricingParameters\Pages\ListPricingParameters;
use App\Filament\Resources\PricingProjects\Pages\ListPricingProjects;
use App\Filament\Resources\PricingRoundingRules\Pages\ListPricingRoundingRules;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Support\PricingPageLayout;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingPageScrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_pricing_pages_and_resources_opt_into_the_shared_scroll_layout(): void
    {
        foreach ([PricingCostCalculations::class, PricingHufRetailCalculations::class,
            PricingForeignRetailCalculations::class, PricingWholesaleCalculations::class,
            PricingDistributorCalculations::class, PricingProductOverview::class,
            PricingProductWorkspace::class, PricingWorkflow::class, PricingHufRetailPriceDistribution::class,
            PartnerPriceListExport::class, ListPricingProjects::class, ListPricingParameters::class,
            ListPricingRoundingRules::class, ListPricingCalculationRows::class,
            ListPricingCalculationErrors::class] as $page) {
            $this->assertTrue(PricingPageLayout::scrollsIntro([$page]), $page);
        }
        $this->assertTrue(PricingPageLayout::scrollsIntro([
            'App\\Filament\\Resources\\PricingProjects\\Pages\\EditPricingProject',
        ]));
        $this->assertFalse(PricingPageLayout::scrollsIntro([ListProducts::class]));
        $this->assertFalse(PricingPageLayout::scrollsIntro([]));
    }

    public function test_page_hook_marks_real_pricing_views_without_changing_other_admin_pages(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        Filament::bootCurrentPanel();

        foreach ([PricingCostCalculations::class, PricingHufRetailCalculations::class,
            PricingForeignRetailCalculations::class, PricingWholesaleCalculations::class,
            PricingDistributorCalculations::class, PricingProductOverview::class,
            PricingProductWorkspace::class, PricingWorkflow::class, PricingHufRetailPriceDistribution::class,
            PartnerPriceListExport::class, ListPricingProjects::class, ListPricingParameters::class,
            ListPricingRoundingRules::class, ListPricingCalculationRows::class,
            ListPricingCalculationErrors::class] as $page) {
            $component = Livewire::test($page)->assertSuccessful();
            $this->assertStringContainsString('data-pricing-page-scroll', $component->html(), $page);
        }
        Livewire::test(ListProducts::class)->assertSuccessful()->assertDontSee('data-pricing-page-scroll', false);
    }
}

<?php

namespace Tests\Feature;

use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Resources\PricingCalculationRows\Pages\ListPricingCalculationRows;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\ProductPurchasePrice;
use App\Models\Season;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Pricing\CostCalculationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IncrementalCostCalculationTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private array $products = [];

    private array $suppliers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $season = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true]);
        $currency = Currency::create(['code' => 'HUF', 'name_hu' => 'Forint', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Teszt', 'status' => 'active', 'active' => true]);
        $groups = [];
        foreach (['A', 'B'] as $code) {
            $groups[] = ItemMainGroup::create(['code' => $code, 'name_hu' => $code, 'active' => true]);
        }
        foreach (['CN', 'PL'] as $country) {
            $this->suppliers[$country] = Supplier::create(['erp_partner_code' => $country, 'name' => $country,
                'country_code' => $country, 'active' => true]);
        }
        foreach (['PU', 'NYLON', 'PU', 'NYLON'] as $index => $material) {
            $product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $groups[(int) ($index / 2)]->id,
                'model_code' => 'MODEL'.$index, 'name_hu' => 'Modell '.$index, 'material_composition' => $material, 'active' => true]);
            $this->products[] = $product;
            $this->project->products()->attach($product);
            foreach (($index === 0 ? ['CN' => 100, 'PL' => 90] : [($index === 1 ? 'CN' : 'PL') => 100]) as $country => $price) {
                ProductPurchasePrice::create(['product_id' => $product->id, 'supplier_id' => $this->suppliers[$country]->getKey(),
                    'currency_id' => $currency->id, 'purchase_price' => $price, 'active' => true]);
            }
        }
        app(CostCalculationService::class)->recalculate($this->project);
    }

    private function parameter(string $scope, float $value = 20): PricingParameter
    {
        return PricingParameter::create(['pricing_project_id' => $this->project->id,
            'parameter_type' => 'customs_percent', 'scope_type' => $scope, 'value' => $value, 'active' => true,
            'product_id' => $scope === 'product' ? $this->products[0]->id : null,
            'item_main_group_id' => $scope === 'item_main_group' ? $this->products[0]->item_main_group_id : null,
            'material_composition' => $scope === 'material_composition' ? 'PU' : null,
            'supplier_country_code' => $scope === 'supplier_country' ? 'PL' : null]);
    }

    public static function scopes(): array
    {
        return ['model' => ['product', [0]], 'group' => ['item_main_group', [0, 1]],
            'material' => ['material_composition', [0, 2]], 'country' => ['supplier_country', [0, 2, 3]],
            'global' => ['global', [0, 1, 2, 3]]];
    }

    #[DataProvider('scopes')]
    public function test_parameter_recalculation_does_not_touch_unrelated_products(string $scope, array $expected): void
    {
        $before = $this->project->calculationRows()->get()->keyBy('product_id')->map->getAttributes();
        $this->travel(1)->minutes();
        $result = app(CostCalculationService::class)->recalculateForParameter($this->parameter($scope));
        $ids = array_map(fn ($index) => $this->products[$index]->id, $expected);
        $this->assertEqualsCanonicalizing($ids, $result['product_ids']);
        $this->assertSame(count($expected), $result['candidate_groups']);
        foreach ($this->products as $index => $product) {
            if (! in_array($index, $expected)) {
                $this->assertSame($before[$product->id], $this->project->calculationRows()->where('product_id', $product->id)->sole()->getAttributes());
            }
        }
        if ($scope === 'supplier_country') {
            $row = $this->project->calculationRows()->where('product_id', $this->products[0]->id)->sole();
            $this->assertEquals($this->suppliers['CN']->getKey(), $row->supplier_id);
            $this->assertEquals(100, $row->final_price);
        }
    }

    public function test_global_change_leaves_unchanged_model_override_and_its_snapshot_untouched(): void
    {
        $this->parameter('product', 7);
        app(CostCalculationService::class)->recalculate($this->project);
        $row = $this->project->calculationRows()->where('product_id', $this->products[0]->id)->sole();
        $before = $row->getAttributes();
        $this->travel(1)->minutes();
        $result = app(CostCalculationService::class)->recalculateForParameter($this->parameter('global'));
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertNotContains($row->product_id, $result['product_ids']);
        $this->assertSame(3, $result['updated']);
    }

    public function test_country_scope_counts_and_recalculates_a_currently_non_selected_supplier_offer(): void
    {
        $parameter = $this->parameter('supplier_country', 20);
        $parameter->supplier_country_code = 'CN';
        $parameter->save();
        ProductPurchasePrice::where('product_id', $this->products[0]->id)
            ->where('supplier_id', $this->suppliers['CN']->getKey())->update(['purchase_price' => 80]);
        app(CostCalculationService::class)->recalculate($this->project);
        $first = $this->project->calculationRows()->where('product_id', $this->products[0]->id)->sole();
        $this->assertEquals($this->suppliers['PL']->getKey(), $first->supplier_id);
        $edited = $this->project->calculationRows()->where('product_id', $this->products[1]->id)->sole();
        $page = Livewire::test(PricingCostCalculations::class)
            ->call('startInlineCostPercentParameterAdjustment', $edited->id, 'customs_percent', 0)
            ->assertSet('pendingParameterAdjustment.scope_counts.supplier_country', 2)
            ->set('pendingParameterScope', 'supplier_country')->call('confirmPendingParameterAdjustment')->assertHasNoErrors();
        $this->assertEquals($this->suppliers['CN']->getKey(), $first->fresh()->supplier_id);
        $this->assertEquals(80, $first->fresh()->final_price);
        $this->assertArrayNotHasKey('html', $page->effects);
    }

    public function test_inline_preparation_confirmation_and_cancel_return_only_the_panel_and_changed_cells(): void
    {
        $row = $this->project->calculationRows()->where('product_id', $this->products[0]->id)->sole();
        $page = Livewire::test(PricingCostCalculations::class)->sortTable('customs_percent', 'asc');
        $positions = $page->instance()->pricingVisibleRowIds;
        $this->assertCount(4, $positions);
        $page->call('startInlineCostPercentParameterAdjustment', $row->id, 'customs_percent', 20);
        $this->assertArrayNotHasKey('html', $page->effects);
        $this->assertSame(['pricing-cost-adjustment'], array_keys($page->effects['partials']));
        $page->set('pendingParameterScope', 'global');
        $this->assertArrayNotHasKey('html', $page->effects);
        $page->set('pendingParameterScope', 'product');
        $page->call('confirmPendingParameterAdjustment')->assertHasNoErrors();
        $this->assertArrayNotHasKey('html', $page->effects);
        $this->assertSame($positions, $page->instance()->pricingVisibleRowIds);
        foreach (array_keys($page->effects['partials']) as $partial) {
            $this->assertTrue($partial === 'pricing-cost-adjustment' || str_starts_with($partial, 'pricing-cell-'.$row->id.'-'), $partial);
        }
        $this->assertEquals(108, $row->fresh()->final_price);
        $page->call('cancelPendingParameterAdjustment');
        $this->assertArrayNotHasKey('html', $page->effects);
    }

    public function test_general_list_parameter_modal_also_only_refreshes_the_changed_product(): void
    {
        $row = $this->project->calculationRows()->where('product_id', $this->products[0]->id)->sole();
        $page = Livewire::test(ListPricingCalculationRows::class)
            ->call('startInlineCostPercentParameterAdjustment', $row->id, 'customs_percent', 20);
        $page->callMountedAction()->assertHasNoErrors();
        $this->assertArrayNotHasKey('html', $page->effects);
        $cellKeys = array_filter(array_keys($page->effects['partials']), fn ($key) => str_starts_with($key, 'pricing-cell-'));
        $this->assertNotEmpty($cellKeys);
        foreach ($cellKeys as $key) {
            $this->assertStringStartsWith('pricing-cell-'.$row->id.'-', $key);
        }
    }
}

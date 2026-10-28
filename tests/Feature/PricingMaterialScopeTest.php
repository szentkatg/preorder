<?php

namespace Tests\Feature;

use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Resources\PricingCalculationRows\Tables\PricingCalculationRowsTable;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\ProductPurchasePrice;
use App\Models\Season;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Pricing\CostCalculationService;
use App\Services\Pricing\PricingConfigurationCopyService;
use App\Services\Pricing\PricingParameterAdjustmentService;
use App\Services\Pricing\PricingParameterResolver;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingMaterialScopeTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8))]);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $season = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'BAG', 'name' => 'Táska', 'active' => true]);
        $this->product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
            'model_code' => 'TEST', 'name_hu' => 'Teszt', 'material_composition' => '100% POLYESTER', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Teszt', 'status' => 'active', 'active' => true]);
        $this->project->products()->attach($this->product);
    }

    private function parameter(string $scope, int $value, array $attributes = []): PricingParameter
    {
        return PricingParameter::create($attributes + ['pricing_project_id' => $this->project->id,
            'parameter_type' => 'customs_percent', 'scope_type' => $scope, 'value' => $value, 'active' => true]);
    }

    public function test_exact_material_overrides_group_but_model_remains_the_most_specific(): void
    {
        $global = $this->parameter('global', 1);
        $group = $this->parameter('item_main_group', 2, ['item_main_group_id' => $this->product->item_main_group_id]);
        $material = $this->parameter('material_composition', 12, ['material_composition' => '100% POLYESTER']);
        $this->parameter('material_composition', 99, ['material_composition' => 'COTTON']);
        $resolve = fn () => app(PricingParameterResolver::class)->resolve($this->project->id, 'customs_percent', $this->product);
        $this->assertTrue($resolve()->is($material));
        $model = $this->parameter('product', 8, ['product_id' => $this->product->id]);
        $this->assertTrue($resolve()->is($model));
        $model->delete();
        $material->update(['active' => false]);
        $this->assertTrue($resolve()->is($group));
        $group->delete();
        $this->assertTrue($resolve()->is($global));
    }

    public function test_inline_cost_adjustment_writes_material_scope_and_counts_only_matching_project_rows(): void
    {
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'active' => true]);
        $cost = PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $this->product->id,
            'currency_id' => $currency->id, 'price_type' => 'cost', 'calculated_price' => 100]);
        $parameter = app(PricingParameterAdjustmentService::class)->updateCostPercentParameter($cost, 'customs_percent', 'material_composition', 12);
        $this->assertSame('100% POLYESTER', $parameter->material_composition);
        $this->assertNull($parameter->product_id);
        $this->assertNull($parameter->item_main_group_id);
        $options = (new \ReflectionMethod(PricingCalculationRowsTable::class, 'parameterScopeOptions'))->invoke(null, $cost);
        $this->assertStringContainsString('(1 sor)', $options['material_composition']);
        $this->product->update(['material_composition' => null]);
        $this->assertNull(app(PricingParameterResolver::class)->resolve($this->project->id, 'customs_percent', $this->product));
    }

    public function test_copy_identifies_collisions_by_material_and_preserves_distinct_material_parameters(): void
    {
        $first = $this->parameter('material_composition', 12, ['material_composition' => '100% POLYESTER']);
        $second = $this->parameter('material_composition', 8, ['material_composition' => 'COTTON']);
        $target = $this->project->replicate();
        $target->save();
        $service = app(PricingConfigurationCopyService::class);
        $ids = [$first->id, $second->id];
        $preview = $service->preview(PricingParameter::class, $ids, $target->id);
        $service->copy(PricingParameter::class, $ids, $target->id, $preview['token']);
        $this->assertSame(2, $target->parameters()->count());
        $preview = $service->preview(PricingParameter::class, $ids, $target->id);
        $first->update(['value' => 15]);
        $preview = $service->preview(PricingParameter::class, $ids, $target->id);
        $service->copy(PricingParameter::class, $ids, $target->id, $preview['token'], 'overwrite');
        $this->assertEquals(15, $target->parameters()->where('material_composition', '100% POLYESTER')->sole()->value);
        $this->assertEquals(8, $target->parameters()->where('material_composition', 'COTTON')->sole()->value);
    }

    public function test_material_cost_parameter_is_available_in_the_inline_workspace_and_used_in_real_calculation(): void
    {
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'active' => true]);
        $supplier = Supplier::create(['erp_partner_code' => 'SUP', 'name' => 'Szállító', 'active' => true]);
        ProductPurchasePrice::create(['product_id' => $this->product->id, 'supplier_id' => $supplier->getKey(),
            'currency_id' => $currency->id, 'purchase_price' => 100, 'active' => true]);
        $this->parameter('material_composition', 12, ['material_composition' => '100% POLYESTER']);
        app(CostCalculationService::class)->recalculate($this->project);
        $cost = $this->project->calculationRows()->sole();
        $this->assertEquals(112, $cost->final_price);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(PricingCostCalculations::class)
            ->call('startInlineCostPercentParameterAdjustment', $cost->id, 'customs_percent', 15)
            ->set('pendingParameterScope', 'material_composition');
        $page->call('confirmPendingParameterAdjustment')->assertHasNoErrors();
        $this->assertEquals(15, $this->project->parameters()->where('scope_type', 'material_composition')->sole()->value);
        $this->assertEquals(115, $cost->fresh()->final_price);
    }
}

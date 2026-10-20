<?php

namespace Tests\Feature;

use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Filament\Resources\PricingProjects\Pages\EditPricingProject;
use App\Filament\Resources\PricingProjects\Pages\ViewPricingProject;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PricingCalculationError;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\ProductPurchasePrice;
use App\Models\Season;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Pricing\CostCalculationAuthorization;
use App\Services\Pricing\CostCalculationService;
use App\Services\Pricing\PricingParameterAdjustmentService;
use BezhanSalleh\FilamentShield\Facades\FilamentShield as Shield;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const READ_PERMISSIONS = [
        'View:PricingCostCalculations', 'View:PricingHufRetailCalculations',
        'ViewAny:PricingCalculationRow', 'View:PricingCalculationRow', 'ViewAny:PricingProject', 'View:PricingProject',
    ];

    private const COST_WRITE_PERMISSIONS = [
        'Create:PricingCalculationRow', 'Update:PricingCalculationRow', 'Update:PricingProject',
        'Create:PricingParameter', 'Update:PricingParameter',
    ];

    private PricingProject $project;

    private PricingCalculationRow $cost;

    private PricingCalculationRow $retail;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8))]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $season = Season::create(['code' => 'AUTH', 'name' => 'Jogosultság teszt', 'active' => true]);
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'name_hu' => 'Forint', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'AUTH', 'name_hu' => 'Teszt', 'active' => true]);
        $product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
            'model_code' => 'AUTH-MODEL', 'name_hu' => 'Teszt termék', 'active' => true]);
        $supplier = Supplier::create(['erp_partner_code' => 'AUTH-SUPPLIER', 'name' => 'Teszt', 'active' => true]);
        ProductPurchasePrice::create(['product_id' => $product->id, 'supplier_id' => $supplier->supplier_id,
            'currency_id' => $currency->id, 'purchase_price' => 100, 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Jogosultság teszt',
            'status' => PricingProject::STATUS_ACTIVE, 'active' => true]);
        $this->project->products()->attach($product);
        $this->cost = PricingCalculationRow::create(['pricing_project_id' => $this->project->id,
            'product_id' => $product->id, 'currency_id' => $currency->id, 'price_type' => 'cost', 'calculated_price' => 100]);
        $this->retail = PricingCalculationRow::create(['pricing_project_id' => $this->project->id,
            'product_id' => $product->id, 'currency_id' => $currency->id, 'price_type' => 'retail',
            'base_price' => 100, 'multiplier' => 4.7, 'calculated_price' => 490]);
    }

    public function test_workspace_page_permissions_are_independent_and_also_require_list_access(): void
    {
        $this->signInWith(['ViewAny:PricingCalculationRow']);
        $this->assertFalse(PricingCostCalculations::canAccess());
        $this->assertFalse(PricingHufRetailCalculations::canAccess());
        $this->get('/admin/pricing-cost-calculations')->assertForbidden();
        $this->get('/admin/pricing-huf-retail-calculations')->assertForbidden();

        $this->signInWith(['ViewAny:PricingCalculationRow', 'View:PricingCostCalculations']);
        $this->assertTrue(PricingCostCalculations::shouldRegisterNavigation());
        $this->assertFalse(PricingHufRetailCalculations::shouldRegisterNavigation());
        $this->get('/admin/pricing-cost-calculations')->assertOk();
        $this->get('/admin/pricing-huf-retail-calculations')->assertForbidden();

        $this->signInWith(['ViewAny:PricingCalculationRow', 'View:PricingHufRetailCalculations']);
        $this->assertFalse(PricingCostCalculations::shouldRegisterNavigation());
        $this->assertTrue(PricingHufRetailCalculations::shouldRegisterNavigation());
        $this->get('/admin/pricing-cost-calculations')->assertForbidden();
        $this->get('/admin/pricing-huf-retail-calculations')->assertOk();

        $this->signInWith(['View:PricingCostCalculations', 'View:PricingHufRetailCalculations']);
        $this->get('/admin/pricing-cost-calculations')->assertForbidden();
        $this->get('/admin/pricing-huf-retail-calculations')->assertForbidden();
    }

    public function test_both_page_permissions_are_listed_by_shield(): void
    {
        $pages = Shield::getPages();
        $this->assertArrayHasKey('View:PricingCostCalculations', $pages[PricingCostCalculations::class]['permissions']);
        $this->assertArrayHasKey('View:PricingHufRetailCalculations', $pages[PricingHufRetailCalculations::class]['permissions']);
    }

    public function test_read_only_role_sees_tables_details_and_export_but_not_write_controls(): void
    {
        $this->signInWith(self::READ_PERMISSIONS);
        $page = Livewire::test(PricingCostCalculations::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$this->cost])->assertTableActionDoesNotExist('calculationDetails')
            ->assertTableActionDoesNotExist('updateShippingCostPercent')
            ->assertTableActionDoesNotExist('updateCustomsPercent')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableBulkActionHidden('delete')
            ->assertTableActionVisible('exportExcel');
        foreach (['shipping_cost_percent', 'customs_percent'] as $field) {
            $this->assertSame('false', $page->instance()->getTable()->getColumn($field)->record($this->cost)->getExtraCellAttributes()['data-cost-editable']);
        }
        $page->call('openCostDetails', $this->cost->id)->assertActionMounted('costDetails');
        $retail = Livewire::test(PricingHufRetailCalculations::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$this->retail])->assertTableActionDoesNotExist('retailDetails')
            ->assertTableActionDoesNotExist('approveRetail')
            ->assertTableActionDoesNotExist('acceptCalculatedRetail')
            ->assertActionHidden('calculateHufRetail');
        $this->assertSame('false', $retail->instance()->getTable()->getColumn('manual_price')->record($this->retail)->getExtraCellAttributes()['data-pricing-editable']);
        $retail->call('openPricingDetails', $this->retail->id)->assertActionMounted('pricingDetails');
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])->assertSuccessful()->assertActionHidden('recalculateCost');
    }

    public function test_details_also_require_record_view_permission(): void
    {
        $this->signInWith(['View:PricingCostCalculations', 'View:PricingHufRetailCalculations', 'ViewAny:PricingCalculationRow']);
        Livewire::test(PricingCostCalculations::class)->assertSuccessful()->call('openCostDetails', $this->cost->id)->assertForbidden();
        Livewire::test(PricingCostCalculations::class)->call('mountAction', 'costDetails', ['record_id' => $this->cost->id])->assertForbidden();
        Livewire::test(PricingHufRetailCalculations::class)->assertSuccessful()->call('openPricingDetails', $this->retail->id)->assertForbidden();
    }

    public function test_forged_inline_start_is_rejected_without_writing_data(): void
    {
        $this->signInWith(self::READ_PERMISSIONS);
        Livewire::test(PricingCostCalculations::class)
            ->call('startInlineCostPercentParameterAdjustment', $this->cost->id, PricingParameter::TYPE_CUSTOMS_PERCENT, 12)
            ->assertForbidden();
        $this->assertUnchanged();
    }

    public function test_forged_edit_and_bulk_delete_actions_cannot_be_mounted_by_read_only_role(): void
    {
        $this->signInWith(self::READ_PERMISSIONS);
        Livewire::test(PricingCostCalculations::class)
            ->mountTableAction('edit', $this->cost)->assertTableActionNotMounted('edit')
            ->mountTableBulkAction('delete', [$this->cost])->assertTableBulkActionNotMounted('delete');
        $this->assertSame(2, PricingCalculationRow::count());
        $this->assertUnchanged();
    }

    public function test_forged_pending_confirmation_is_rejected_without_writing_data(): void
    {
        $this->signInWith(self::READ_PERMISSIONS);
        Livewire::test(PricingCostCalculations::class)->set('pendingParameterAdjustment', [
            'record_id' => $this->cost->id, 'parameter_type' => PricingParameter::TYPE_CUSTOMS_PERCENT,
            'value' => 12, 'scope_options' => [PricingParameter::SCOPE_GLOBAL => 'Globális'],
        ])->set('pendingParameterScope', PricingParameter::SCOPE_GLOBAL)
            ->call('confirmPendingParameterAdjustment')->assertForbidden();
        $this->assertUnchanged();
    }

    public function test_read_only_role_cannot_bypass_the_ui_using_cost_services(): void
    {
        $this->signInWith(self::READ_PERMISSIONS);
        foreach ([
            fn () => app(CostCalculationService::class)->recalculate($this->project),
            fn () => app(PricingParameterAdjustmentService::class)->updateCostPercentParameter(
                $this->cost, PricingParameter::TYPE_CUSTOMS_PERCENT, PricingParameter::SCOPE_GLOBAL, 12,
            ),
        ] as $write) {
            try {
                $write();
                $this->fail('Unauthorized write was allowed.');
            } catch (AuthorizationException) {
                $this->assertUnchanged();
            }
        }
    }

    public function test_every_cost_parameter_write_permission_is_required(): void
    {
        foreach (self::COST_WRITE_PERMISSIONS as $missing) {
            $this->signInWith(array_diff(array_merge(self::READ_PERMISSIONS, self::COST_WRITE_PERMISSIONS), [$missing]));
            $this->assertFalse(CostCalculationAuthorization::canAdjustParameter($this->cost), $missing);
            try {
                app(PricingParameterAdjustmentService::class)->updateCostPercentParameter(
                    $this->cost, PricingParameter::TYPE_CUSTOMS_PERCENT, PricingParameter::SCOPE_GLOBAL, 12,
                );
                $this->fail('Missing permission was ignored: '.$missing);
            } catch (AuthorizationException) {
                $this->assertUnchanged();
            }
        }
    }

    public function test_editor_role_can_change_parameter_and_recalculate_from_on_demand_cell_editor(): void
    {
        $this->signInWith(array_merge(self::READ_PERMISSIONS, self::COST_WRITE_PERMISSIONS));
        $page = Livewire::test(PricingCostCalculations::class)
            ->assertTableActionDoesNotExist('updateCustomsPercent');
        $this->assertSame('true', $page->instance()->getTable()->getColumn('customs_percent')->record($this->cost)->getExtraCellAttributes()['data-cost-editable']);
        $page->call('startInlineCostPercentParameterAdjustment', $this->cost->id, PricingParameter::TYPE_CUSTOMS_PERCENT, 12)
            ->set('pendingParameterScope', PricingParameter::SCOPE_GLOBAL)
            ->call('confirmPendingParameterAdjustment')->assertHasNoErrors();
        $this->assertSame('12.0000', $this->cost->fresh()->customs_percent);
        $this->assertSame('112.0000', $this->cost->fresh()->final_price);
        $this->assertSame('12.000000', PricingParameter::sole()->value);
        $page->call('startInlineCostPercentParameterAdjustment', $this->cost->id, PricingParameter::TYPE_CUSTOMS_PERCENT, '10,0')
            ->set('pendingParameterScope', PricingParameter::SCOPE_GLOBAL)->call('confirmPendingParameterAdjustment')
            ->assertHasNoErrors();
        $this->assertSame('10.000000', PricingParameter::sole()->value);
        $this->assertSame('110.0000', $this->cost->fresh()->final_price);
    }

    public function test_cost_recalculation_service_checks_each_of_its_write_permissions(): void
    {
        $permissions = ['Update:PricingProject', 'Create:PricingCalculationRow', 'Update:PricingCalculationRow'];
        foreach ($permissions as $missing) {
            $this->signInWith(array_merge(self::READ_PERMISSIONS, array_diff($permissions, [$missing])));
            try {
                app(CostCalculationService::class)->recalculate($this->project);
                $this->fail('Missing recalculation permission was ignored: '.$missing);
            } catch (AuthorizationException) {
                $this->assertUnchanged();
            }
        }
    }

    public function test_write_access_is_rechecked_when_confirming_a_pending_edit(): void
    {
        $user = $this->signInWith(array_merge(self::READ_PERMISSIONS, self::COST_WRITE_PERMISSIONS));
        $page = Livewire::test(PricingCostCalculations::class)
            ->call('startInlineCostPercentParameterAdjustment', $this->cost->id, PricingParameter::TYPE_CUSTOMS_PERCENT, 12)
            ->set('pendingParameterScope', PricingParameter::SCOPE_GLOBAL);
        $user->roles()->firstOrFail()->revokePermissionTo('Update:PricingParameter');
        $this->actingAs($user->fresh());
        $page->call('confirmPendingParameterAdjustment')->assertForbidden();
        $this->assertUnchanged();
    }

    public function test_project_recalculation_requires_row_create_and_update_as_well_as_project_update(): void
    {
        $this->signInWith(array_merge(self::READ_PERMISSIONS, ['Update:PricingProject']));
        $this->assertFalse(CostCalculationAuthorization::canRecalculate($this->project));
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])->assertActionHidden('recalculateCost');
        Livewire::test(EditPricingProject::class, ['record' => $this->project->id])->assertActionHidden('recalculateCost');
        $this->signInWith(array_merge(self::READ_PERMISSIONS, self::COST_WRITE_PERMISSIONS));
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])
            ->assertActionVisible('recalculateCost')->callAction('recalculateCost')->assertHasNoErrors();
        Livewire::test(EditPricingProject::class, ['record' => $this->project->id])->assertActionVisible('recalculateCost');
    }

    public function test_page_access_is_rechecked_when_confirming_a_pending_edit(): void
    {
        $user = $this->signInWith(array_merge(self::READ_PERMISSIONS, self::COST_WRITE_PERMISSIONS));
        $page = Livewire::test(PricingCostCalculations::class)
            ->call('startInlineCostPercentParameterAdjustment', $this->cost->id, PricingParameter::TYPE_CUSTOMS_PERCENT, 12)
            ->set('pendingParameterScope', PricingParameter::SCOPE_GLOBAL);
        $user->roles()->firstOrFail()->revokePermissionTo('View:PricingCostCalculations');
        $this->actingAs($user->fresh());
        $page->call('confirmPendingParameterAdjustment')->assertForbidden();
        $this->assertUnchanged();
    }

    private function signInWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::findOrCreate('pricing-test-'.$user->id, 'web');
        $role->givePermissionTo(array_map(fn ($name) => Permission::findOrCreate($name, 'web'), $permissions));
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function assertUnchanged(): void
    {
        $this->assertSame(0, PricingParameter::count());
        $this->assertSame('100.0000', $this->cost->fresh()->final_price);
        $this->assertSame(0, PricingCalculationError::count());
    }
}

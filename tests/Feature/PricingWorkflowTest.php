<?php

namespace Tests\Feature;

use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Pages\PricingWorkflow;
use App\Models\AdminUiPreference;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\PricingWorkflowSummary;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private PricingProject $other;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $season = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'WF', 'name_hu' => 'Teszt', 'active' => true]);
        $this->product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
            'model_code' => 'WF-1', 'name_hu' => 'Teszt', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Első projekt', 'status' => 'active', 'active' => true]);
        $this->other = PricingProject::create(['season_id' => $season->id, 'name' => 'Másik projekt', 'status' => 'draft', 'active' => true]);
        $this->row('cost', 'HUF', 'calculated', 500);
        $this->row('retail', 'HUF', 'approved', 2000);
        $this->row('outlet', 'HUF', 'calculated', 1800, ['base_price' => 2000, 'outlet_discount_percent' => 20]);
        $this->row('retail', 'EUR', 'modified_approved', 10);
        $this->row('outlet', 'EUR', 'approved_round_3', 8, ['base_price' => 10, 'outlet_discount_percent' => 20]);
        $this->row('wholesale', 'EUR', 'approved_round_2', 5, ['requires_review' => true]);
        $this->row('distributor', 'EUR', 'reviewed', 4);
        $second = $this->product->replicate();
        $second->model_code = 'WF-2';
        $second->save();
        $this->row('cost', 'HUF', 'calculated', 600, ['product_id' => $second->id]);
        $this->row('retail', 'HUF', 'approved', 0, ['product_id' => $second->id]);
        $this->row('retail', 'HUF', 'approved', 3000, ['pricing_project_id' => $this->other->id]);
    }

    private function row(string $type, string $code, string $status, float $price, array $extra = []): PricingCalculationRow
    {
        $currency = Currency::firstOrCreate(['code' => $code], ['name_hu' => $code, 'active' => true]);
        $list = $type === 'cost' ? null : PriceList::firstOrCreate(['code' => $type.'-'.$code],
            ['name_hu' => $type, 'type' => $type, 'currency_id' => $currency->id, 'active' => true]);

        return PricingCalculationRow::create(array_replace(['pricing_project_id' => $this->project->id,
            'product_id' => $this->product->id, 'price_type' => $type, 'currency_id' => $currency->id,
            'price_list_id' => $list?->id, 'status' => $status, 'calculated_price' => $price], $extra));
    }

    public function test_counts_are_project_scoped_and_outlet_is_included_as_a_subset(): void
    {
        $counts = app(PricingWorkflowSummary::class)->forProject($this->project);
        $this->assertSame(['total' => 3, 'approved' => 1, 'pending' => 2,
            'outlet_total' => 1, 'outlet_approved' => 0, 'outlet_pending' => 1], $counts['huf']);
        $this->assertSame(2, $counts['cost']['approved']);
        $this->assertSame(2, $counts['foreign']['approved']);
        $this->assertSame(1, $counts['foreign']['outlet_approved']);
        $this->assertSame(1, $counts['wholesale']['pending']);
        $this->assertSame(1, $counts['distributor']['pending']);
        $this->assertSame(2, $counts['overview']['total']);
        $this->assertSame(2, $counts['overview']['pending']);
        $other = app(PricingWorkflowSummary::class)->forProject($this->other);
        $this->assertSame(1, $other['huf']['approved']);
        $this->assertSame(0, $other['foreign']['total']);
    }

    public function test_missing_selection_never_sums_all_projects_and_switching_replaces_counts(): void
    {
        $page = Livewire::test(PricingWorkflow::class)->assertSuccessful()->assertSet('pricingProjectId', null)
            ->assertSee('Válassz árprojektet.');
        $this->assertNull($page->instance()->workflowSteps['huf']['counts']);
        $page->call('selectPricingProject', $this->project->id)->assertSee('(1 outlet)');
        $this->assertSame(3, $page->instance()->workflowSteps['huf']['counts']['total']);
        $page->call('selectPricingProject', $this->other->id);
        $this->assertSame(1, $page->instance()->workflowSteps['huf']['counts']['total']);
        $this->assertSame(0, $page->instance()->workflowSteps['huf']['counts']['pending']);
        $page->call('selectPricingProject', '');
        $this->assertNull($page->instance()->workflowSteps['huf']['counts']);
    }

    public function test_opening_a_step_reapplies_selected_project_even_after_another_tab_changes_it(): void
    {
        $page = Livewire::test(PricingWorkflow::class)->call('selectPricingProject', $this->project->id);
        Livewire::test(PricingCostCalculations::class)->call('selectPricingProject', $this->other->id);
        $page->call('openPricingStep', 'cost')->assertRedirect(PricingCostCalculations::getUrl());
        $this->assertSame($this->project->id, AdminUiPreference::where('preference_key', 'pricing_workspace_project_context')->firstOrFail()->value['project_id']);
        Livewire::test(PricingCostCalculations::class)->assertSet('pricingProjectId', $this->project->id);
    }

    public function test_archive_is_read_only_and_live_refresh_has_a_small_payload_without_snapshots(): void
    {
        $this->project->update(['status' => 'closed']);
        $row = $this->project->calculationRows()->first();
        $row->update(['calculation_snapshot' => ['large_source' => str_repeat('sensitive-source', 100000)]]);
        $before = $this->project->calculationRows()->get()->map->getAttributes()->all();
        $page = Livewire::test(PricingWorkflow::class)->call('setPricingArchive', true)
            ->assertSet('pricingProjectId', $this->project->id)->assertSee('Lezárt árprojekt: csak megtekintés.');
        $this->assertSame(3, $page->instance()->workflowSteps['huf']['counts']['total']);
        $page->call('$refresh')->assertDontSee('sensitive-source');
        $this->assertLessThan(100000, strlen(json_encode(['snapshot' => $page->snapshot, 'effects' => $page->effects])));
        $this->assertSame($before, $this->project->calculationRows()->get()->map->getAttributes()->all());
        $page->call('selectPricingProject', $this->other->id)->assertStatus(422);
    }

    public function test_outlet_approval_with_insufficient_discount_is_pending_even_without_review_flag(): void
    {
        $this->project->calculationRows()->where('price_type', 'outlet')->whereHas('currency', fn ($query) => $query->where('code', 'HUF'))
            ->update(['status' => 'approved_round_2', 'requires_review' => false]);
        $this->assertSame(1, app(PricingWorkflowSummary::class)->forProject($this->project)['huf']['outlet_pending']);
    }

    public function test_dashboard_and_destination_permissions_are_enforced_for_direct_requests(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test(PricingWorkflow::class)->assertForbidden();
        $user->givePermissionTo(['View:PricingWorkflow', Permission::findOrCreate('ViewAny:PricingCalculationRow', 'web'),
            Permission::findOrCreate('ViewAny:PricingProject', 'web'), Permission::findOrCreate('View:PricingProject', 'web')]);
        $page = Livewire::test(PricingWorkflow::class)->call('selectPricingProject', $this->project->id)->assertSuccessful();
        $this->assertNull($page->instance()->workflowSteps['cost']['url']);
        $page->call('openPricingStep', 'cost')->assertForbidden();
    }

    public function test_context_cannot_be_overwritten_through_a_livewire_property_update(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(PricingWorkflow::class)->set('pricingProjectId', $this->other->id);
    }
}

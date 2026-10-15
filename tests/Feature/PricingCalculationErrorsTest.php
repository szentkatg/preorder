<?php

namespace Tests\Feature;

use App\Filament\Resources\PricingCalculationErrors\Pages\ListPricingCalculationErrors;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PricingCalculationError;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PricingCalculationErrorsTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $season = Season::create(['code' => 'W27', 'name' => 'Teszt szezon', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Teszt projekt', 'status' => 'active', 'active' => true]);
    }

    public function test_all_price_types_are_visible_without_an_automatic_filter_and_can_be_filtered(): void
    {
        $this->signInWith([]);
        $errors = collect(['cost', 'retail', 'wholesale', 'distributor'])->map(fn ($type) => $this->error($type));
        $page = Livewire::test(ListPricingCalculationErrors::class)->assertSuccessful()
            ->assertCanSeeTableRecords($errors)->assertCountTableRecords(4);
        $this->assertNull($page->instance()->getTable()->getFilter('price_type')->getDefaultState());
        foreach ($errors as $error) {
            $page->filterTable('price_type', $error->price_type)->assertCanSeeTableRecords([$error])
                ->assertCountTableRecords(1);
        }
    }

    public function test_single_delete_requires_confirmation_and_only_deletes_the_selected_log(): void
    {
        $this->signInWith(['Delete:PricingCalculationError']);
        $selected = $this->error('distributor');
        $other = $this->error('wholesale');
        $page = Livewire::test(ListPricingCalculationErrors::class)->assertTableActionVisible('delete', $selected)
            ->mountTableAction('delete', $selected);
        $this->assertModelExists($selected);
        $page->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertModelMissing($selected);
        $this->assertModelExists($other);
    }

    public function test_bulk_delete_removes_only_selected_logs_and_preserves_calculation_rows(): void
    {
        $this->signInWith(['DeleteAny:PricingCalculationError']);
        $selected = collect([$this->error('cost'), $this->error('distributor')]);
        $other = $this->error('retail');
        $currency = Currency::create(['code' => 'HUF', 'name_hu' => 'Forint', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'BAG', 'name_hu' => 'Táska', 'active' => true]);
        $product = Product::create(['season_id' => $this->project->season_id, 'item_main_group_id' => $group->id,
            'model_code' => 'BAG27', 'name_hu' => 'Táska', 'active' => true]);
        PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $product->id,
            'currency_id' => $currency->id, 'price_type' => 'cost', 'calculated_price' => 1234, 'manual_price' => 1200]);
        $before = DB::table('pricing_calculation_rows')->get()->toArray();
        $page = Livewire::test(ListPricingCalculationErrors::class)->assertTableBulkActionVisible('delete')
            ->mountTableBulkAction('delete', $selected);
        $this->assertDatabaseCount('pricing_calculation_errors', 3);
        $page->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();
        foreach ($selected as $error) {
            $this->assertModelMissing($error);
        }
        $this->assertModelExists($other);
        $this->assertModelExists($this->project);
        $this->assertEquals($before, DB::table('pricing_calculation_rows')->get()->toArray());
    }

    public function test_read_only_user_cannot_delete_even_by_direct_action_calls(): void
    {
        $this->signInWith([]);
        $error = $this->error('distributor');
        Livewire::test(ListPricingCalculationErrors::class)
            ->assertTableActionHidden('delete', $error)->assertTableBulkActionHidden('delete')
            ->mountTableAction('delete', $error)->assertTableActionNotMounted('delete')
            ->mountTableBulkAction('delete', [$error])->assertTableBulkActionNotMounted('delete');
        $this->assertModelExists($error);
    }

    public function test_individual_and_bulk_delete_permissions_are_independent(): void
    {
        $error = $this->error('distributor');
        $this->signInWith(['Delete:PricingCalculationError']);
        Livewire::test(ListPricingCalculationErrors::class)->assertTableActionVisible('delete', $error)
            ->assertTableBulkActionHidden('delete');
        $this->signInWith(['DeleteAny:PricingCalculationError']);
        Livewire::test(ListPricingCalculationErrors::class)->assertTableActionHidden('delete', $error)
            ->assertTableBulkActionVisible('delete');
    }

    private function error(string $type): PricingCalculationError
    {
        return PricingCalculationError::create(['run_id' => (string) Str::uuid(), 'pricing_project_id' => $this->project->id,
            'price_type' => $type, 'severity' => 'error', 'message' => $type.' teszthiba']);
    }

    private function signInWith(array $permissions): void
    {
        $user = User::factory()->create();
        foreach (['ViewAny:PricingCalculationError', 'View:PricingCalculationError', ...$permissions] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);
    }
}

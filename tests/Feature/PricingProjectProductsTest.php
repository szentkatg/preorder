<?php

namespace Tests\Feature;

use App\Filament\Resources\PricingCalculationRows\Pages\CreatePricingCalculationRow;
use App\Filament\Resources\PricingCalculationRows\Pages\EditPricingCalculationRow;
use App\Filament\Resources\PricingProjects\Pages\CreatePricingProject;
use App\Filament\Resources\PricingProjects\Pages\SelectPricingProjectProducts;
use App\Filament\Resources\PricingProjects\PricingProjectResource;
use App\Models\Brand;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\OrderSheetType;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\ProductPurchasePrice;
use App\Models\Season;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Pricing\CostCalculationService;
use App\Services\Pricing\PricingProjectCalculationService;
use App\Services\Pricing\PricingProjectProductService;
use App\Services\Pricing\ProductPricingService;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingProjectProductsTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private Product $product;

    private Product $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $season = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'BAG', 'name' => 'Táska', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Első ütem', 'status' => 'draft', 'active' => true]);
        foreach (['product', 'other'] as $key) {
            $brand = Brand::create(['code' => $key, 'name' => $key, 'active' => true]);
            $sheet = OrderSheetType::create(['code' => $key, 'name' => $key, 'active' => true]);
            $this->{$key} = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
                'model_code' => $key, 'name_hu' => $key, 'brand_id' => $brand->id, 'order_sheet_type_id' => $sheet->id, 'active' => true]);
        }
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'active' => true]);
        $supplier = Supplier::create(['erp_partner_code' => 'SUP', 'name' => 'Szállító', 'short_name' => 'Szállító', 'active' => true]);
        foreach ([$this->product, $this->other] as $product) {
            ProductPurchasePrice::create(['product_id' => $product->id, 'supplier_id' => $supplier->getKey(),
                'currency_id' => $currency->id, 'purchase_price' => 100, 'active' => true]);
        }
    }

    private function add(array $ids): int
    {
        return app(PricingProjectProductService::class)->add($this->project, $ids);
    }

    public function test_creation_redirects_to_explicit_product_selection_without_auto_enrolling_the_season(): void
    {
        Livewire::test(CreatePricingProject::class)->fillForm(['name' => 'Második ütem', 'season_id' => $this->project->season_id,
            'status' => 'draft', 'active' => true])->call('create')->assertHasNoFormErrors()
            ->assertRedirect(PricingProjectResource::getUrl('products', ['record' => PricingProject::where('name', 'Második ütem')->sole()]));
        $this->assertDatabaseCount('pricing_project_products', 0);
    }

    public function test_selection_table_filters_brand_and_sheet_and_adds_only_checked_products(): void
    {
        $page = Livewire::test(SelectPricingProjectProducts::class, ['record' => $this->project->id])
            ->assertCanSeeTableRecords([$this->product, $this->other])
            ->filterTable('brand_id', [$this->product->brand_id])->assertCanNotSeeTableRecords([$this->other])
            ->resetTableFilters()->filterTable('order_sheet_type_id', [$this->other->order_sheet_type_id])
            ->assertCanSeeTableRecords([$this->other])->assertCanNotSeeTableRecords([$this->product])
            ->resetTableFilters()->callTableBulkAction('addSelectedProducts', [$this->product])->assertHasNoTableBulkActionErrors();
        $this->assertSame([$this->product->id], $this->project->products()->pluck('products.id')->all());
        $page->assertCanNotSeeTableRecords([$this->product])->assertCanSeeTableRecords([$this->other]);
        $this->assertDatabaseCount('pricing_calculation_rows', 0);
    }

    public function test_product_picker_keeps_filters_after_addition_and_when_reopened(): void
    {
        $page = Livewire::test(SelectPricingProjectProducts::class, ['record' => $this->project->id])
            ->filterTable('brand_id', [(string) $this->product->brand_id])->searchTable('product')->sortTable('model_code', 'desc');
        $before = $page->instance()->tableFilters;
        $this->assertTrue($page->instance()->getTable()->persistsFiltersInSession());
        $this->assertSame('await $wire.applyTableFilters(); close()', $page->instance()->getTable()->getFiltersApplyAction()->getAlpineClickHandler());
        $page->callTableBulkAction('addSelectedProducts', [$this->product])->assertHasNoTableBulkActionErrors()->assertCountTableRecords(0);
        $this->assertSame($before, $page->instance()->tableFilters);
        $restored = Livewire::test(SelectPricingProjectProducts::class, ['record' => $this->project->id])->assertCountTableRecords(0);
        $this->assertSame($before, $restored->instance()->tableFilters);
        $this->assertSame('product', $restored->instance()->tableSearch);
        $restored->resetTableFilters()->searchTable('')->assertCanSeeTableRecords([$this->other]);
    }

    public function test_candidates_exclude_other_seasons_and_all_assigned_products_including_closed_projects(): void
    {
        $this->add([$this->product->id]);
        $this->project->update(['status' => 'closed']);
        $second = $this->project->replicate();
        $second->status = 'draft';
        $second->save();
        $different = $this->other->replicate();
        $different->season_id = Season::create(['code' => 'W28', 'name' => 'Tél 28', 'active' => true])->id;
        $different->model_code = 'NEXT';
        $different->save();
        $this->assertSame([$this->other->id], app(PricingProjectProductService::class)->candidates($second)->pluck('id')->all());
    }

    public function test_stale_mixed_selection_is_atomic_and_duplicate_assignment_is_rejected(): void
    {
        $second = $this->project->replicate();
        $second->save();
        app(PricingProjectProductService::class)->add($second, [$this->product->id]);
        try {
            $this->add([$this->other->id, $this->product->id]);
            $this->fail('Expected duplicate rejection');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Egyetlen terméket sem', $exception->getMessage());
        }
        $this->assertSame(0, $this->project->products()->count());
        $this->assertSame(1, $second->products()->count());
    }

    public function test_wrong_season_and_missing_ids_are_not_accepted(): void
    {
        $this->expectException(DomainException::class);
        $this->add([$this->other->id, 99999]);
    }

    public function test_empty_and_unselected_projects_do_not_calculate_the_whole_season(): void
    {
        $this->assertSame(0, app(CostCalculationService::class)->recalculate($this->project)['created']);
        $this->assertSame(0, app(PricingProjectCalculationService::class)->calculate($this->project)['products']);
        $this->add([$this->product->id]);
        $this->assertSame(1, app(CostCalculationService::class)->recalculate($this->project)['created']);
        $this->assertDatabaseMissing('pricing_calculation_rows', ['product_id' => $this->other->id]);
        $this->assertSame(1, app(PricingProjectCalculationService::class)->calculate($this->project)['products']);
        $this->assertDatabaseMissing('pricing_calculation_rows', ['product_id' => $this->other->id]);
        $this->expectException(DomainException::class);
        app(ProductPricingService::class)->recalculate($this->project, $this->other);
    }

    public function test_removal_deletes_all_draft_calculations_but_preserves_published_prices_and_does_not_recreate(): void
    {
        $this->add([$this->product->id, $this->other->id]);
        app(CostCalculationService::class)->recalculate($this->project);
        $this->project->publications()->create(['published_by' => auth()->id(), 'published_at' => now(), 'snapshot' => ['products' => []]]);
        $before = $this->project->publications()->sole()->getAttributes();
        $this->assertSame(1, app(PricingProjectProductService::class)->remove($this->project, [$this->product->id]));
        $this->assertDatabaseMissing('pricing_calculation_rows', ['product_id' => $this->product->id]);
        app(CostCalculationService::class)->recalculate($this->project);
        $this->assertDatabaseMissing('pricing_calculation_rows', ['product_id' => $this->product->id]);
        $this->assertSame($before, $this->project->publications()->sole()->getAttributes());
        $this->assertModelExists($this->product);
    }

    public function test_permissions_and_current_project_status_are_checked_again_on_save(): void
    {
        $project = $this->project->fresh();
        $this->project->update(['status' => 'closed']);
        $this->expectException(DomainException::class);
        app(PricingProjectProductService::class)->add($project, [$this->product->id]);
    }

    public function test_wrong_season_is_rejected_even_for_a_known_unassigned_product(): void
    {
        $this->product->update(['season_id' => Season::create(['code' => 'S28', 'name' => 'Nyár', 'active' => true])->id]);
        $this->expectException(DomainException::class);
        $this->add([$this->product->id]);
    }

    public function test_remove_checks_delete_permissions_before_removing_membership_or_prices(): void
    {
        $this->add([$this->product->id]);
        app(CostCalculationService::class)->recalculate($this->project);
        $editor = User::factory()->create();
        $editor->givePermissionTo(array_map(fn ($name) => Permission::findOrCreate($name, 'web'),
            ['ViewAny:PricingProject', 'View:PricingProject', 'Update:PricingProject', 'ViewAny:Product', 'View:Product']));
        $this->actingAs($editor);
        try {
            app(PricingProjectProductService::class)->remove($this->project, [$this->product->id]);
            $this->fail('Expected delete authorization');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('pricing_project_products', ['product_id' => $this->product->id]);
            $this->assertDatabaseHas('pricing_calculation_rows', ['product_id' => $this->product->id]);
        }
    }

    public function test_read_only_user_cannot_open_the_selector_or_add_via_direct_call(): void
    {
        $reader = User::factory()->create();
        $reader->givePermissionTo(array_map(fn ($name) => Permission::findOrCreate($name, 'web'),
            ['ViewAny:PricingProject', 'View:PricingProject', 'ViewAny:Product', 'View:Product']));
        $this->actingAs($reader);
        $this->get(PricingProjectResource::getUrl('products', ['record' => $this->project]))->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $this->add([$this->product->id]);
    }

    public function test_manual_calculation_forms_cannot_bypass_project_product_selection(): void
    {
        $data = ['pricing_project_id' => $this->project->id, 'product_id' => $this->product->id,
            'price_type' => 'cost', 'currency_id' => Currency::where('code', 'HUF')->value('id'),
            'manual_price' => 100, 'status' => 'calculated'];
        Livewire::test(CreatePricingCalculationRow::class)
            ->fillForm($data)->call('create')->assertHasFormErrors(['product_id']);
        $this->assertDatabaseCount('pricing_calculation_rows', 0);
        $this->add([$this->product->id]);
        Livewire::test(CreatePricingCalculationRow::class)
            ->fillForm($data)->call('create')->assertHasNoFormErrors();
        $row = $this->project->calculationRows()->sole();
        Livewire::test(EditPricingCalculationRow::class, ['record' => $row->id])
            ->fillForm(['product_id' => $this->other->id])->call('save')->assertHasFormErrors(['product_id']);
        $this->assertSame($this->product->id, $row->fresh()->product_id);
    }

    public function test_removal_failure_rolls_back_membership_and_every_deleted_calculation(): void
    {
        $this->add([$this->product->id, $this->other->id]);
        app(CostCalculationService::class)->recalculate($this->project);
        $before = $this->project->calculationRows()->orderBy('id')->get()->toArray();
        $failedId = $this->project->calculationRows()->where('product_id', $this->other->id)->value('id');
        Event::listen('eloquent.deleting: '.PricingCalculationRow::class, function ($row) use ($failedId): void {
            if ($row->id === $failedId) {
                throw new \RuntimeException('Simulated failure');
            }
        });
        try {
            app(PricingProjectProductService::class)->remove($this->project, [$this->product->id, $this->other->id]);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated failure', $exception->getMessage());
        }
        $this->assertSame(2, $this->project->products()->count());
        $this->assertSame($before, $this->project->calculationRows()->orderBy('id')->get()->toArray());
    }

    public function test_migration_backfills_current_calculations_without_turning_historical_snapshots_into_membership(): void
    {
        $this->add([$this->product->id]);
        app(CostCalculationService::class)->recalculate($this->project);
        $second = $this->project->replicate();
        $second->save();
        $second->publications()->create(['published_by' => auth()->id(), 'published_at' => now(),
            'snapshot' => ['products' => [$this->product->id => $this->product->only(['id', 'model_code'])]]]);
        $before = PricingCalculationRow::get()->toArray();
        $migration = require database_path('migrations/2026_10_19_140000_add_material_scope_and_pricing_project_products.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, PricingCalculationRow::get()->toArray());
        $this->assertTrue($this->project->products()->whereKey($this->product->id)->exists());
        $this->assertFalse($second->products()->whereKey($this->product->id)->exists());
        $this->assertDatabaseCount('pricing_project_products', 1);
        $this->assertDatabaseCount('pricing_project_publications', 1);
    }

    public function test_conflicts_are_reported_and_migration_stops_before_any_schema_or_data_change(): void
    {
        $this->add([$this->product->id]);
        app(CostCalculationService::class)->recalculate($this->project);
        $second = $this->project->replicate();
        $second->name = 'Ütköző projekt';
        $second->save();
        $row = $this->project->calculationRows()->sole()->replicate();
        $row->pricing_project_id = $second->id;
        $row->save();
        $before = PricingCalculationRow::get()->toArray();
        $this->artisan('pricing:check-product-assignments')->expectsOutputToContain('1 termék több árprojektben szerepel')->assertExitCode(1);
        $migration = require database_path('migrations/2026_10_19_140000_add_material_scope_and_pricing_project_products.php');
        $migration->down();
        try {
            $migration->up();
            $this->fail('Expected migration conflict');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('pricing:check-product-assignments', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasTable('pricing_project_products'));
        $this->assertFalse(Schema::hasColumn('pricing_parameters', 'material_composition'));
        $this->assertSame($before, PricingCalculationRow::get()->toArray());
        $row->delete();
        $migration->up();
        $this->artisan('pricing:check-product-assignments')->expectsOutputToContain('Nincs ütköző')->assertExitCode(0);
    }
}

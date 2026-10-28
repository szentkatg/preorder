<?php

namespace Tests\Feature;

use App\Exceptions\PricingParameterConflictException;
use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Resources\PricingParameters\Pages\CreatePricingParameter;
use App\Filament\Resources\PricingParameters\Pages\EditPricingParameter;
use App\Filament\Resources\PricingParameters\Pages\ListPricingParameters;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\PricingConfigurationCopyService;
use App\Services\Pricing\PricingParameterResolver;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingParameterValidationTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

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
        $group = ItemMainGroup::create(['code' => 'BAG', 'name' => 'Táska', 'active' => true]);
        $this->product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
            'model_code' => 'TEST', 'name_hu' => 'Teszt', 'material_composition' => 'PU', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Teszt', 'status' => 'active', 'active' => true]);
    }

    private function attributes(array $overrides = []): array
    {
        return array_replace(['pricing_project_id' => $this->project->id,
            'parameter_type' => 'customs_percent', 'scope_type' => 'global', 'value' => 3.9, 'active' => true], $overrides);
    }

    private function parameter(array $overrides = []): PricingParameter
    {
        return PricingParameter::create($this->attributes($overrides));
    }

    private function assertConflict(callable $operation, PricingParameter $existing): void
    {
        try {
            $operation();
            $this->fail('An active parameter with the same effective identity must be rejected.');
        } catch (PricingParameterConflictException $exception) {
            $this->assertStringContainsString('#'.$existing->id, $exception->getMessage());
        }
    }

    public function test_global_duplicate_is_rejected_even_with_stale_dimensions_and_equal_value(): void
    {
        $existing = $this->parameter();
        $this->assertConflict(fn () => $this->parameter(['supplier_country_code' => 'CN',
            'item_main_group_id' => $this->product->item_main_group_id, 'material_composition' => 'PU',
            'product_id' => $this->product->id]), $existing);
        $this->assertDatabaseCount('pricing_parameters', 1);
        // Omitting the DB-default active/scope fields must not bypass validation.
        $this->assertConflict(fn () => PricingParameter::create(['pricing_project_id' => $this->project->id,
            'parameter_type' => 'customs_percent', 'value' => 8]), $existing);
    }

    public function test_inactive_versions_are_allowed_but_reactivation_and_identity_changes_are_checked(): void
    {
        $existing = $this->parameter();
        $existing->update(['value' => 4]); // Does not conflict with itself.
        $inactive = $this->parameter(['active' => false, 'value' => 9]);
        $this->assertConflict(fn () => $inactive->update(['active' => true]), $existing);
        $this->assertFalse($inactive->fresh()->active);
        $country = $this->parameter(['scope_type' => 'supplier_country', 'supplier_country_code' => 'CN']);
        $this->assertConflict(fn () => $country->update(['scope_type' => 'global']), $existing);
        $this->assertSame('supplier_country', $country->fresh()->scope_type);
        $existing->update(['active' => false]);
        $inactive->refresh()->update(['active' => true]);
        $this->assertTrue($inactive->fresh()->active);
    }

    public function test_scope_material_price_list_currency_and_project_are_independent_dimensions(): void
    {
        $this->parameter();
        $this->parameter(['scope_type' => 'supplier_country', 'supplier_country_code' => 'CN']);
        $this->parameter(['scope_type' => 'item_main_group', 'item_main_group_id' => $this->product->item_main_group_id]);
        $material = $this->parameter(['scope_type' => 'material_composition', 'material_composition' => 'PU', 'value' => 9.7]);
        $this->parameter(['scope_type' => 'material_composition', 'material_composition' => 'Nylon']);
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollár', 'active' => true]);
        $list = PriceList::create(['code' => 'TEST', 'name' => 'Teszt', 'currency_id' => $currency->id, 'type' => 'retail', 'active' => true]);
        $this->parameter(['currency_id' => $currency->id]);
        $this->parameter(['price_list_id' => $list->id]);
        $this->parameter(['price_list_id' => $list->id, 'currency_id' => $currency->id]);
        $this->parameter(['parameter_type' => 'shipping_cost_percent']);
        $other = $this->project->replicate();
        $other->save();
        $this->parameter(['pricing_project_id' => $other->id]);
        $resolver = app(PricingParameterResolver::class);
        $this->assertTrue($resolver->resolve($this->project->id, 'customs_percent', $this->product, 'CN', requireUnique: true)->is($material));
        $model = $this->parameter(['scope_type' => 'product', 'product_id' => $this->product->id]);
        $this->assertTrue($resolver->resolve($this->project->id, 'customs_percent', $this->product, 'CN', requireUnique: true)->is($model));
        $this->assertConflict(fn () => $this->parameter(['scope_type' => 'material_composition', 'material_composition' => 'PU']), $material);
    }

    public function test_save_clears_irrelevant_fields_and_normalizes_country_but_detects_legacy_duplicates(): void
    {
        $country = $this->parameter(['scope_type' => 'supplier_country', 'supplier_country_code' => 'cn',
            'product_id' => $this->product->id, 'item_main_group_id' => $this->product->item_main_group_id, 'material_composition' => 'PU']);
        $this->assertSame(['supplier_country_code' => 'CN', 'item_main_group_id' => null, 'material_composition' => null, 'product_id' => null], $country->fresh()->scopeAttributes());
        $this->assertNull($country->fresh()->product_id);
        $this->assertNull($country->fresh()->item_main_group_id);
        $this->assertNull($country->fresh()->material_composition);
        $country->newQuery()->whereKey($country->id)->update(['supplier_country_code' => 'cn']);
        $this->assertConflict(fn () => $this->parameter(['scope_type' => 'supplier_country', 'supplier_country_code' => 'CN']), $country);
        $global = $this->parameter();
        $global->newQuery()->whereKey($global->id)->update(['supplier_country_code' => 'CN']);
        $this->assertConflict(fn () => $this->parameter(), $global);
        $country->refresh()->update(['active' => false, 'scope_type' => 'global']);
        $this->assertNull($country->fresh()->supplier_country_code);
    }

    public function test_moving_to_another_project_cannot_introduce_a_duplicate(): void
    {
        $existing = $this->parameter();
        $other = $this->project->replicate();
        $other->save();
        $moving = $this->parameter(['pricing_project_id' => $other->id]);
        $this->assertConflict(fn () => $moving->update(['pricing_project_id' => $this->project->id]), $existing);
        $this->assertSame($other->id, $moving->fresh()->pricing_project_id);
    }

    public function test_legacy_duplicates_can_be_deactivated_but_not_saved_as_active(): void
    {
        $existing = $this->parameter();
        $legacy = $this->parameter(['active' => false]);
        $legacy->newQuery()->whereKey($legacy->id)->update(['active' => true, 'supplier_country_code' => 'CN']);
        $this->assertConflict(fn () => $legacy->refresh()->update(['value' => 12]), $existing);
        $this->assertEquals(3.9, $legacy->fresh()->value);
        Livewire::test(EditPricingParameter::class, ['record' => $legacy->id])
            ->fillForm(['active' => false])->call('save')->assertHasNoFormErrors();
        $this->assertFalse($legacy->fresh()->active);
        $this->assertNull($legacy->fresh()->supplier_country_code);
    }

    public function test_inline_cost_adjustment_reports_legacy_conflict_without_recalculating(): void
    {
        $existing = $this->parameter();
        $legacy = $this->parameter(['active' => false]);
        $legacy->newQuery()->whereKey($legacy->id)->update(['active' => true]);
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'active' => true]);
        $this->project->products()->attach($this->product);
        $row = PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $this->product->id,
            'price_type' => 'cost', 'currency_id' => $currency->id, 'calculated_price' => 100]);
        Livewire::test(PricingCostCalculations::class)
            ->call('startInlineCostPercentParameterAdjustment', $row->id, 'customs_percent', 12)
            ->set('pendingParameterScope', 'global')->call('confirmPendingParameterAdjustment')
            ->assertNotified('A paraméter nem menthető');
        $this->assertEquals(3.9, $existing->fresh()->value);
        $this->assertEquals(100, $row->fresh()->final_price);
    }

    public function test_create_and_edit_pages_show_validation_error_without_writing(): void
    {
        $existing = $this->parameter();
        Livewire::test(CreatePricingParameter::class)->fillForm($this->attributes())->call('create')
            ->assertHasFormErrors(['value'])->assertSee('#'.$existing->id);
        $country = $this->parameter(['scope_type' => 'supplier_country', 'supplier_country_code' => 'CN']);
        Livewire::test(EditPricingParameter::class, ['record' => $country->id])
            ->fillForm(['scope_type' => 'global', 'value' => 12])->call('save')
            ->assertHasFormErrors(['value'])->assertSee('#'.$existing->id);
        $this->assertSame('supplier_country', $country->fresh()->scope_type);
        $this->assertDatabaseCount('pricing_parameters', 2);
    }

    public function test_list_edit_modal_keeps_open_and_explains_conflict(): void
    {
        $existing = $this->parameter();
        $country = $this->parameter(['scope_type' => 'supplier_country', 'supplier_country_code' => 'CN']);
        $page = Livewire::test(ListPricingParameters::class)->mountTableAction('edit', $country)
            ->setTableActionData(['scope_type' => 'global', 'value' => 12])->callMountedTableAction()
            ->assertNotified('A paraméter nem menthető');
        $this->assertNotNull($page->instance()->getMountedAction());
        $this->assertSame('supplier_country', $country->fresh()->scope_type);
        $this->assertEquals(3.9, $existing->fresh()->value);
    }

    public function test_copy_detects_legacy_hidden_dimensions_and_requires_explicit_choice(): void
    {
        $source = $this->parameter();
        $target = $this->project->replicate();
        $target->save();
        $existing = $this->parameter(['pricing_project_id' => $target->id, 'value' => 12]);
        $existing->newQuery()->whereKey($existing->id)->update(['supplier_country_code' => 'CN', 'material_composition' => 'PU']);
        $service = app(PricingConfigurationCopyService::class);
        $preview = $service->preview(PricingParameter::class, [$source->id], $target->id);
        $this->assertSame(1, $preview['conflicts']);
        $this->assertSame(0, $preview['new']);
        $skipped = $service->copy(PricingParameter::class, [$source->id], $target->id, $preview['token'], 'skip');
        $this->assertSame(1, $skipped['skipped']);
        $this->assertEquals(12, $existing->fresh()->value);
        $overwritten = $service->copy(PricingParameter::class, [$source->id], $target->id, $preview['token'], 'overwrite');
        $this->assertSame(1, $overwritten['overwritten']);
        $this->assertEquals(3.9, $existing->fresh()->value);
        $this->assertNull($existing->fresh()->supplier_country_code);
        $this->assertNull($existing->fresh()->material_composition);
        $this->assertSame(1, $target->parameters()->count());
    }

    public function test_copy_rejects_selected_legacy_source_duplicates_instead_of_creating_a_new_conflict(): void
    {
        $first = $this->parameter();
        $second = $this->parameter(['active' => false]);
        $second->newQuery()->whereKey($second->id)->update(['active' => true, 'supplier_country_code' => 'CN']);
        $target = $this->project->replicate();
        $target->save();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('forrássor');
        try {
            app(PricingConfigurationCopyService::class)->preview(PricingParameter::class, [$first->id, $second->id], $target->id);
        } finally {
            $this->assertSame(0, $target->parameters()->count());
        }
    }

    public function test_each_scope_rejects_its_own_duplicate_without_blocking_other_scope_values(): void
    {
        foreach ([['scope_type' => 'supplier_country', 'supplier_country_code' => 'CN'],
            ['scope_type' => 'item_main_group', 'item_main_group_id' => $this->product->item_main_group_id],
            ['scope_type' => 'material_composition', 'material_composition' => 'PU'],
            ['scope_type' => 'product', 'product_id' => $this->product->id]] as $attributes) {
            $existing = $this->parameter($attributes);
            $this->assertConflict(fn () => $this->parameter($attributes + ['value' => 12]), $existing);
        }
        $this->assertDatabaseCount('pricing_parameters', 4);
    }
}

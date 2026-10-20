<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\Brand;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\OrderSheetType;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\RoundingRule;
use App\Models\Season;
use App\Models\SizeRange;
use App\Models\User;
use App\Services\ProductBulkUpdateService;
use DomainException;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Livewire\Mechanisms\ExtendBlade\ExtendBlade;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductBulkUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Product $first;

    private Product $second;

    private Product $untouched;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $season = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'BAG', 'name' => 'Táskák', 'name_hu' => 'Táskák', 'active' => true]);
        $size = SizeRange::create(['code' => 'ONE', 'name_hu' => 'Egyméretes', 'active' => true]);
        $brand = Brand::create(['code' => 'HT', 'name' => 'Heavy Tools', 'active' => true]);
        $sheet = OrderSheetType::create(['code' => 'NORMAL', 'name' => 'Normál', 'name_hu' => 'Normál', 'active' => true]);
        foreach (['first', 'second', 'untouched'] as $name) {
            $this->{$name} = Product::create(['model_code' => 'MODEL-'.$name, 'name_hu' => 'Termék '.$name, 'name_en' => 'Original '.$name,
                'season_id' => $season->id, 'item_main_group_id' => $group->id, 'size_range_id' => $size->id,
                'brand_id' => $brand->id, 'order_sheet_type_id' => $sheet->id, 'active' => true, 'catalog_sort' => 10]);
        }
    }

    private function changes(): array
    {
        return [['field' => 'promised_delivery_date', 'value' => '2027-09-30'],
            ['field' => 'catalog_page', 'value' => 12], ['field' => 'active', 'value' => '0']];
    }

    private function update(array $changes, ?array $ids = null): array
    {
        return app(ProductBulkUpdateService::class)->update($ids ?? [$this->first->id, $this->second->id], $changes);
    }

    public function test_bulk_wizard_only_saves_after_confirmation_and_only_selected_fields_and_products(): void
    {
        $before = $this->untouched->fresh()->getAttributes();
        $page = Livewire::test(ListProducts::class)->mountTableBulkAction('bulkUpdateProducts', [$this->first, $this->second]);
        $action = $page->instance()->getMountedAction();
        $this->assertTrue($action->isWizard());
        $this->assertFalse($action->isWizardSkippable());
        $this->assertSame('Kijelölt termékek tömeges módosítása', $action->getModalHeading());
        $blade = app(ExtendBlade::class);
        $originalErrors = view()->shared('errors');
        view()->share('errors', (new ViewErrorBag)->put('default', $page->instance()->getErrorBag()));
        $blade->startLivewireRendering($page->instance());
        try {
            $modal = $action->toModalHtmlable()->toHtml();
        } finally {
            $blade->endLivewireRendering();
            view()->share('errors', $originalErrors);
        }
        $this->assertStringContainsString('Biztos módosítani kívánsz 2 db terméket', $modal);
        $page->set('mountedActions.0.data.changes', $this->changes())->set('mountedActions.0.data.confirmed', false);
        $this->assertNull($this->first->fresh()->promised_delivery_date);
        $page->callMountedTableBulkAction()->assertHasTableBulkActionErrors(['confirmed']);
        $this->assertTrue((bool) $this->first->fresh()->active);
        $page->set('mountedActions.0.data.confirmed', true);
        $dataBeforeSave = $page->instance()->getSchema('mountedActionSchema0')->getState();
        $this->assertEquals($this->changes(), array_values($dataBeforeSave['changes']));
        $page->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();
        $page->assertSet('mountedActions', []);
        foreach ([$this->first, $this->second] as $product) {
            $this->assertSame('2027-09-30', $product->fresh()->promised_delivery_date);
            $this->assertSame(12, $product->fresh()->catalog_page);
            $this->assertFalse((bool) $product->fresh()->active);
            $this->assertSame('Original '.($product->id === $this->first->id ? 'first' : 'second'), $product->fresh()->name_en);
        }
        $this->assertSame($before, $this->untouched->fresh()->getAttributes());
        $page->assertSet('mountedActions', [])->assertDispatched('deselectAllTableRecords');
    }

    public function test_cancel_keeps_every_product_unchanged(): void
    {
        $before = Product::orderBy('id')->get()->map->getAttributes()->all();
        Livewire::test(ListProducts::class)->mountTableBulkAction('bulkUpdateProducts', [$this->first, $this->second])
            ->setTableBulkActionData(['changes' => $this->changes()])->call('unmountAction');
        $this->assertSame($before, Product::orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_live_field_selection_initializes_the_date_input_and_saves_its_value(): void
    {
        $page = Livewire::test(ListProducts::class)->mountTableBulkAction('bulkUpdateProducts', [$this->first]);
        $key = array_key_first($page->instance()->mountedActions[0]['data']['changes']);
        $path = 'mountedActions.0.data.changes.'.$key;
        $page->set($path.'.field', 'promised_delivery_date')->set($path.'.value', '2027-10-01')
            ->set('mountedActions.0.data.confirmed', true)->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();
        $page->assertSet('mountedActions', []);
        $this->assertSame('2027-10-01', $this->first->fresh()->promised_delivery_date);
    }

    public function test_changing_field_clears_previous_value_and_relation_choices_are_validated(): void
    {
        $brand = Brand::create(['code' => 'NEW', 'name' => 'Másik márka', 'active' => true]);
        $page = Livewire::test(ListProducts::class)->mountTableBulkAction('bulkUpdateProducts', [$this->first, $this->second]);
        $key = array_key_first($page->instance()->mountedActions[0]['data']['changes']);
        $path = 'mountedActions.0.data.changes.'.$key;
        $page->set($path.'.field', 'name_hu')->set($path.'.value', 'Ezt el kell felejteni')
            ->set($path.'.field', 'brand_id')->assertSet($path.'.value', null)
            ->set($path.'.value', (string) $brand->id)->set('mountedActions.0.data.confirmed', true)
            ->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors()->assertSet('mountedActions', []);
        $this->assertSame($brand->id, $this->first->fresh()->brand_id);
        $this->assertSame('Termék first', $this->first->fresh()->name_hu);
    }

    public function test_select_all_respects_filter_and_deselected_records(): void
    {
        $this->untouched->update(['active' => false]);
        $page = Livewire::test(ListProducts::class)->filterTable('active', true)
            ->set('isTrackingDeselectedTableRecords', true)->set('deselectedTableRecords', [(string) $this->second->id])
            ->mountTableBulkAction('bulkUpdateProducts', []);
        $key = array_key_first($page->instance()->mountedActions[0]['data']['changes']);
        $path = 'mountedActions.0.data.changes.'.$key;
        $page->set($path.'.field', 'catalog_page')->set($path.'.value', 99)->set('mountedActions.0.data.confirmed', true)
            ->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors()->assertSet('mountedActions', []);
        $this->assertSame(99, $this->first->fresh()->catalog_page);
        $this->assertNull($this->second->fresh()->catalog_page);
        $this->assertNull($this->untouched->fresh()->catalog_page);
    }

    public function test_changed_selection_after_modal_open_is_not_silently_applied(): void
    {
        $page = Livewire::test(ListProducts::class)->mountTableBulkAction('bulkUpdateProducts', [$this->first]);
        $key = array_key_first($page->instance()->mountedActions[0]['data']['changes']);
        $path = 'mountedActions.0.data.changes.'.$key;
        $page->set($path.'.field', 'catalog_page')->set($path.'.value', 99)
            ->set('selectedTableRecords', [(string) $this->first->id, (string) $this->second->id])
            ->set('mountedActions.0.data.confirmed', true)->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();
        $this->assertNull($this->first->fresh()->catalog_page);
        $this->assertNull($this->second->fresh()->catalog_page);
        $this->assertNotEmpty($page->instance()->mountedActions);
    }

    public function test_rounding_rule_uses_its_custom_primary_key_and_can_be_cleared(): void
    {
        $rule = RoundingRule::create(['code' => 'BULK', 'name' => 'Tömeges szabály', 'rounding_multiple' => 10, 'rounding_mode' => 'ceil', 'active' => true]);
        $this->assertArrayHasKey($rule->getKey(), ProductBulkUpdateService::options('rounding_rule_id'));
        $this->update([['field' => 'rounding_rule_id', 'value' => $rule->getKey()]]);
        $this->assertSame($rule->getKey(), $this->first->fresh()->rounding_rule_id);
        $this->update([['field' => 'rounding_rule_id', 'value' => null]]);
        $this->assertNull($this->first->fresh()->rounding_rule_id);
    }

    public function test_optional_values_can_be_cleared_and_false_and_zero_are_saved(): void
    {
        $this->update([['field' => 'name_en', 'value' => ''], ['field' => 'catalog_sort', 'value' => 0], ['field' => 'active', 'value' => '0']]);
        $this->assertNull($this->first->fresh()->name_en);
        $this->assertSame(0, $this->first->fresh()->catalog_sort);
        $this->assertFalse((bool) $this->first->fresh()->active);
    }

    public function test_service_rejects_unknown_fields_without_writing_anything(): void
    {
        try {
            $this->update([['field' => 'name_hu', 'value' => 'Should not persist'], ['field' => 'id', 'value' => 999]]);
            $this->fail('Expected validation error');
        } catch (ValidationException) {
            $this->assertSame('Termék first', $this->first->fresh()->name_hu);
        }
    }

    public function test_duplicate_fields_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->update([['field' => 'name_hu', 'value' => 'A'], ['field' => 'name_hu', 'value' => 'B']]);
    }

    public function test_empty_change_list_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->update([]);
    }

    public function test_empty_selection_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->update($this->changes(), []);
    }

    public function test_invalid_date_and_missing_related_record_are_rejected(): void
    {
        foreach ([['field' => 'promised_delivery_date', 'value' => '2027-02-30'],
            ['field' => 'brand_id', 'value' => 98765], ['field' => 'name_hu', 'value' => ''],
            ['field' => 'active', 'value' => 'not-a-bool'], ['field' => 'catalog_sort', 'value' => -1]] as $change) {
            try {
                $this->update([$change]);
                $this->fail('Expected validation error for '.$change['field']);
            } catch (ValidationException) {
                $this->assertSame('Termék first', $this->first->fresh()->name_hu);
            }
        }
    }

    public function test_missing_selected_product_aborts_the_entire_batch(): void
    {
        $this->second->delete();
        try {
            $this->update($this->changes());
            $this->fail('Expected missing product error');
        } catch (DomainException) {
            $this->assertNull($this->first->fresh()->promised_delivery_date);
        }
    }

    public function test_read_only_user_has_no_bulk_update_action_and_service_refuses_writes(): void
    {
        $reader = User::factory()->create();
        $reader->givePermissionTo([Permission::findOrCreate('ViewAny:Product', 'web'), Permission::findOrCreate('View:Product', 'web')]);
        $this->actingAs($reader);
        Livewire::test(ListProducts::class)->assertTableBulkActionHidden('bulkUpdateProducts');
        $this->expectException(AuthorizationException::class);
        $this->update($this->changes());
    }

    public function test_one_denied_product_aborts_before_any_product_is_written(): void
    {
        $editor = User::factory()->create();
        $editor->givePermissionTo([Permission::findOrCreate('ViewAny:Product', 'web'), Permission::findOrCreate('Update:Product', 'web')]);
        $this->actingAs($editor);
        Gate::before(fn ($user, $ability, $arguments) => $ability === 'update' && ($arguments[0] ?? null) instanceof Product
            && $arguments[0]->id === $this->second->id ? false : null);
        try {
            $this->update($this->changes());
            $this->fail('Expected authorization error');
        } catch (AuthorizationException) {
            $this->assertNull($this->first->fresh()->promised_delivery_date);
        }
    }

    public function test_model_save_failure_rolls_back_previous_products(): void
    {
        Product::saving(fn (Product $product) => $product->id !== $this->second->id);
        try {
            $this->update($this->changes());
            $this->fail('Expected save rejection');
        } catch (DomainException) {
            $this->assertNull($this->first->fresh()->promised_delivery_date);
        }
    }

    public function test_model_code_collision_is_rejected_without_partial_updates(): void
    {
        try {
            $this->update([['field' => 'model_code', 'value' => 'SAME']]);
            $this->fail('Expected identity collision');
        } catch (DomainException) {
            $this->assertSame('MODEL-first', $this->first->fresh()->model_code);
        }
        $this->expectException(DomainException::class);
        $this->update([['field' => 'model_code', 'value' => $this->untouched->model_code]], [$this->first->id]);
    }

    public function test_unchanged_fields_are_not_saved_again(): void
    {
        $result = $this->update([['field' => 'catalog_sort', 'value' => 10]]);
        $this->assertSame(['selected' => 2, 'updated' => 0, 'unchanged' => 2, 'marked_for_review' => 0], $result);
    }

    public function test_form_fields_are_available_but_internal_fields_are_not(): void
    {
        $form = ProductForm::configure(Schema::make());
        $keys = array_map(fn ($component) => $component->getName(), $form->getComponents());
        $this->assertEqualsCanonicalizing($keys, array_keys(ProductBulkUpdateService::fields()));
        $this->assertArrayNotHasKey('previous_season_product_id', ProductBulkUpdateService::fields());
    }

    public function test_linked_project_prevents_inconsistent_season_change(): void
    {
        $project = PricingProject::create(['season_id' => $this->first->season_id, 'name' => 'Existing', 'status' => 'active', 'active' => true]);
        $project->products()->attach($this->first);
        $new = Season::create(['code' => 'W28', 'name' => 'Új tél', 'active' => true]);
        $this->expectException(DomainException::class);
        $this->update([['field' => 'season_id', 'value' => $new->id]]);
    }

    public function test_parameter_source_changes_mark_only_open_calculations_for_review(): void
    {
        $currency = Currency::create(['code' => 'HUF', 'name_hu' => 'Forint', 'active' => true]);
        $open = PricingProject::create(['season_id' => $this->first->season_id, 'name' => 'Open', 'status' => 'active', 'active' => true]);
        $closed = PricingProject::create(['season_id' => $this->first->season_id, 'name' => 'Closed', 'status' => 'closed', 'active' => true]);
        $rows = [];
        foreach ([$open, $closed] as $project) {
            $rows[] = PricingCalculationRow::create(['pricing_project_id' => $project->id, 'product_id' => $this->first->id,
                'currency_id' => $currency->id, 'price_type' => 'cost', 'calculated_price' => 100, 'requires_review' => false]);
        }
        $result = $this->update([['field' => 'material_composition', 'value' => '100% polyester']]);
        $this->assertSame(1, $result['marked_for_review']);
        $this->assertTrue($rows[0]->fresh()->requires_review);
        $this->assertFalse($rows[1]->fresh()->requires_review);
        $this->assertEquals(100, $rows[0]->fresh()->final_price);
    }
}

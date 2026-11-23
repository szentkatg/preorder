<?php

namespace Tests\Feature\Admin;

use App\Exports\AdminTableExport;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Brand;
use App\Models\ItemMainGroup;
use App\Models\OrderSheetType;
use App\Models\Product;
use App\Models\Season;
use App\Models\SizeRange;
use App\Models\User;
use App\Services\ProductBulkUpdateService;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductExclusivityTest extends TestCase
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
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $season = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'B03', 'name' => 'Táska']);
        $range = SizeRange::create(['code' => 'ONE', 'name_hu' => 'ONE']);
        $brand = Brand::create(['code' => 'HT', 'name' => 'Heavy Tools']);
        $type = OrderSheetType::create(['code' => 'B', 'name' => 'Táska']);
        foreach (['first', 'second', 'untouched'] as $key) {
            $this->{$key} = Product::create(['model_code' => 'MODEL-'.$key, 'name_hu' => 'Modell '.$key,
                'season_id' => $season->id, 'item_main_group_id' => $group->id, 'size_range_id' => $range->id,
                'brand_id' => $brand->id, 'order_sheet_type_id' => $type->id, 'active' => true, 'visible_to_partners' => true]);
        }
    }

    public function test_migration_keeps_existing_model_data_and_starts_with_null_exclusivity(): void
    {
        $before = $this->first->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_11_23_150000_add_exclusivity_to_products_table.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, $this->first->fresh()->getAttributes());
        $this->assertNull($this->first->fresh()->exclusivity);
        $this->first->update(['exclusivity' => 'Kizárólagos partner']);
        $this->assertSame('Kizárólagos partner', $this->first->fresh()->exclusivity);
        $this->assertNull($this->second->fresh()->exclusivity);
    }

    public function test_product_form_saves_one_hundred_unicode_characters_rejects_one_hundred_and_one_and_can_clear(): void
    {
        $value = str_repeat('Ő', 100);
        $page = Livewire::test(EditProduct::class, ['record' => $this->first->id])
            ->fillForm(['exclusivity' => $value])->call('save')->assertHasNoFormErrors();
        $this->assertSame($value, $this->first->fresh()->exclusivity);
        $page->fillForm(['exclusivity' => $value.'Á'])->call('save')->assertHasFormErrors(['exclusivity' => 'max']);
        $this->assertSame($value, $this->first->fresh()->exclusivity);
        $page->fillForm(['exclusivity' => ''])->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->first->fresh()->exclusivity);
        $this->assertTrue($this->first->fresh()->visible_to_partners);
    }

    public function test_product_list_can_search_sort_and_export_the_complete_exclusivity_text(): void
    {
        $this->first->update(['exclusivity' => 'B kizárólagos partner']);
        $this->second->update(['exclusivity' => 'A kizárólagos partner']);
        $page = Livewire::test(ListProducts::class)->assertTableColumnExists('exclusivity')
            ->searchTable('kizárólagos')->sortTable('exclusivity', 'asc')
            ->assertCountTableRecords(2)->assertCanSeeTableRecords([$this->second, $this->first], inOrder: true);
        $column = $page->instance()->getTable()->getColumn('exclusivity');
        $this->first->update(['exclusivity' => str_repeat('Á', 100)]);
        $this->assertSame([str_repeat('Á', 100)], (new AdminTableExport(Product::query(), [$column]))->map($this->first->fresh()));
    }

    public function test_bulk_editor_uses_the_hundred_character_limit_and_changes_only_selected_models(): void
    {
        $page = Livewire::test(ListProducts::class)->mountTableBulkAction('bulkUpdateProducts', [$this->first, $this->second]);
        $key = array_key_first($page->instance()->mountedActions[0]['data']['changes']);
        $path = 'mountedActions.0.data.changes.'.$key;
        $page->set($path.'.field', 'exclusivity');
        $input = collect($page->instance()->getSchema('mountedActionSchema0')->getFlatFields())
            ->first(fn ($field) => $field instanceof TextInput && $field->getName() === 'value');
        $this->assertSame(100, $input->getMaxLength());
        $value = str_repeat('É', 100);
        $page->set($path.'.value', $value)->set('mountedActions.0.data.confirmed', true)
            ->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();
        $this->assertSame($value, $this->first->fresh()->exclusivity);
        $this->assertSame($value, $this->second->fresh()->exclusivity);
        $this->assertNull($this->untouched->fresh()->exclusivity);
        $result = app(ProductBulkUpdateService::class)->update([$this->first->id], [['field' => 'exclusivity', 'value' => '']]);
        $this->assertSame(0, $result['marked_for_review']);
        $this->assertNull($this->first->fresh()->exclusivity);
        $this->assertSame($value, $this->second->fresh()->exclusivity);
    }

    public function test_bulk_service_rejects_overlength_values_without_truncating_or_writing(): void
    {
        try {
            app(ProductBulkUpdateService::class)->update([$this->first->id, $this->second->id], [['field' => 'exclusivity', 'value' => str_repeat('Á', 101)]]);
            $this->fail('Overlength exclusivity must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('changes.0.value', $exception->errors());
            $this->assertNull($this->first->fresh()->exclusivity);
            $this->assertNull($this->second->fresh()->exclusivity);
        }
    }

    public function test_bulk_exclusivity_requires_existing_product_update_permissions(): void
    {
        $this->actingAs(User::factory()->create());
        $this->expectException(AuthorizationException::class);
        app(ProductBulkUpdateService::class)->update([$this->first->id], [['field' => 'exclusivity', 'value' => 'Not allowed']]);
    }
}

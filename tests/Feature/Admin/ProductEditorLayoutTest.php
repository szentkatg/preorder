<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\RelationManagers\ColorsRelationManager;
use App\Models\Brand;
use App\Models\Color;
use App\Models\ItemMainGroup;
use App\Models\OrderSheetType;
use App\Models\Product;
use App\Models\Season;
use App\Models\SizeRange;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductEditorLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $this->product = Product::create([
            'model_code' => 'B01W27', 'name_hu' => 'EMIRO', 'active' => true,
            'season_id' => Season::create(['code' => 'W27', 'name' => '2027 Tél'])->id,
            'item_main_group_id' => ItemMainGroup::create(['code' => 'B01', 'name' => 'Táska'])->id,
            'size_range_id' => SizeRange::create(['code' => 'ONE', 'name_hu' => 'Egyméretes'])->id,
            'brand_id' => Brand::create(['code' => 'HT', 'name' => 'Heavy Tools'])->id,
            'order_sheet_type_id' => OrderSheetType::create(['code' => 'B', 'name' => 'Táska'])->id,
            'material_composition' => '100% poliészter', 'catalog_group_name_hu' => 'SIDE BAGS',
        ]);
    }

    public function test_product_forms_are_compact_and_responsive_without_hiding_fields(): void
    {
        foreach ([CreateProduct::class, EditProduct::class, ViewProduct::class] as $class) {
            $page = Livewire::test($class, $class === CreateProduct::class ? [] : ['record' => $this->product->getRouteKey()])
                ->assertSuccessful();
            $form = $page->instance()->getSchema('form');
            $this->assertSame(1, $form->getColumns('default'));
            $this->assertSame(2, $form->getColumns('md'));
            $this->assertSame(4, $form->getColumns('xl'));
            $this->assertTrue($form->isDense());
            foreach (['season_id', 'item_main_group_id', 'size_range_id', 'model_code', 'name_hu', 'material_composition',
                'brand_id', 'order_sheet_type_id', 'rounding_rule_id', 'promised_delivery_date',
                'catalog_group_name_hu', 'catalog_group_sort', 'catalog_sort', 'catalog_page', 'active'] as $field) {
                $page->assertFormFieldExists($field, form: 'form');
            }
            $page->assertFormFieldExists('material_composition', 'form', fn ($field) => $field instanceof Textarea
                && $field->getRows() === 1 && $field->getColumnSpan('default') === 1);
        }
    }

    public function test_compact_editor_saves_full_multiline_material_and_delivery_date(): void
    {
        $material = str_repeat("100% poliészter, bélés: pamut\n", 10);
        Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->fillForm(['name_hu' => 'Új modellnév', 'material_composition' => $material,
                'promised_delivery_date' => '2027-03-01', 'catalog_page' => 9])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Új modellnév', $this->product->fresh()->name_hu);
        $this->assertSame($material, $this->product->fresh()->material_composition);
        $this->assertSame('2027-03-01', $this->product->fresh()->promised_delivery_date);
        $this->assertSame(9, $this->product->fresh()->catalog_page);
    }

    public function test_colors_use_page_scrolling_and_keep_editing_and_excel_export(): void
    {
        $first = Color::create(['product_id' => $this->product->id, 'code' => '01', 'name_hu' => 'BLACK']);
        $second = Color::create(['product_id' => $this->product->id, 'code' => '02', 'name_hu' => 'BRICK']);
        $page = Livewire::test(ColorsRelationManager::class, ['ownerRecord' => $this->product, 'pageClass' => EditProduct::class])
            ->assertSuccessful()->assertCanSeeTableRecords([$first, $second])
            ->assertSee('data-product-colors-natural-scroll="true"', false)->assertSee('Új szín');
        $this->assertSame('true', $page->instance()->getTable()->getExtraAttributes()['data-product-colors-natural-scroll']);
        $this->assertSame('true', $page->instance()->getTable()->getExtraAttributes()['data-compact-column-manager']);
        $page->callTableAction('edit', $second, data: ['code' => '02', 'name_hu' => 'Új színnév', 'sort_order' => 3, 'active' => true])
            ->assertHasNoTableActionErrors();
        $this->assertSame('Új színnév', $second->fresh()->name_hu);
        $page->callTableAction('exportExcel')->assertFileDownloaded();
    }

    public function test_colors_remain_paginated_while_the_current_page_has_natural_height(): void
    {
        for ($index = 1; $index <= 13; $index++) {
            Color::create(['product_id' => $this->product->id, 'code' => sprintf('%02d', $index), 'name_hu' => 'Szín '.$index, 'sort_order' => $index]);
        }
        $page = Livewire::test(ColorsRelationManager::class, ['ownerRecord' => $this->product, 'pageClass' => EditProduct::class]);
        $this->assertCount(10, $page->instance()->getTableRecords());
        $page->call('gotoPage', 2, $page->instance()->getTablePaginationPageName());
        $this->assertCount(3, $page->instance()->getTableRecords());
    }

    public function test_scroll_override_is_scoped_to_product_colors_and_keeps_global_lists_constrained(): void
    {
        $css = file_get_contents(public_path('css/admin-table-scroll.css'));
        $this->assertMatchesRegularExpression('/\[data-product-colors-natural-scroll\][^{]+\{\s*max-height:\s*none;\s*overflow-y:\s*hidden;/s', $css);
        $this->assertStringContainsString('max-height: var(--admin-table-content-max-height', $css);
        $this->assertStringContainsString('.admin-table-scrollbar.is-visible', $css);
    }
}

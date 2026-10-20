<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\ExchangeRates\Pages\ListExchangeRates;
use App\Filament\Resources\PricingParameters\Pages\CreatePricingParameter;
use App\Filament\Resources\PricingParameters\Pages\EditPricingParameter;
use App\Filament\Resources\PricingParameters\Pages\ListPricingParameters;
use App\Filament\Resources\PricingParameters\PricingParameterResource;
use App\Filament\Resources\PricingParameters\Schemas\PricingParameterForm;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\ColorsRelationManager;
use App\Filament\Support\AdminResourceTable;
use App\Models\Color;
use App\Models\ItemMainGroup;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles;
use Filament\Facades\Filament;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminListStateTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $first;

    private PricingProject $second;

    private PricingParameter $material;

    private PricingParameter $global;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $season = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'BAG', 'name_hu' => 'Táska', 'active' => true]);
        Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
            'model_code' => 'TEST', 'name_hu' => 'Teszt', 'material_composition' => 'PU', 'active' => true]);
        $this->first = PricingProject::create(['season_id' => $season->id, 'name' => 'Első', 'status' => 'active', 'active' => true]);
        $this->second = PricingProject::create(['season_id' => $season->id, 'name' => 'Második', 'status' => 'active', 'active' => true]);
        $this->material = $this->parameter(['scope_type' => 'material_composition', 'material_composition' => 'PU', 'value' => 9.7]);
        $this->global = $this->parameter();
        $this->parameter(['parameter_type' => 'shipping_cost_percent']);
        $this->parameter(['scope_type' => 'supplier_country', 'supplier_country_code' => 'CN']);
        $this->parameter(['pricing_project_id' => $this->second->id]);
    }

    private function parameter(array $attributes = []): PricingParameter
    {
        return PricingParameter::create($attributes + ['pricing_project_id' => $this->first->id,
            'parameter_type' => 'customs_percent', 'scope_type' => 'global', 'value' => 3.9, 'active' => true]);
    }

    private function filteredList()
    {
        return Livewire::test(ListPricingParameters::class)
            ->filterTable('pricing_project_id', [(string) $this->first->id])
            ->filterTable('parameter_type', ['customs_percent'])
            ->filterTable('scope_type', ['material_composition'])
            ->searchTable('PU')->searchTableColumns(['parameter_type' => 'customs'])
            ->sortTable('value', 'desc');
    }

    private function state($page): array
    {
        return ['filters' => $page->instance()->tableFilters, 'search' => $page->instance()->tableSearch,
            'columns' => $page->instance()->tableColumnSearches, 'sort' => $page->instance()->tableSort];
    }

    public function test_parameter_filters_are_multiple_and_combine_with_and_using_complete_hungarian_options(): void
    {
        $page = Livewire::test(ListPricingParameters::class)->assertCountTableRecords(5);
        foreach (['pricing_project_id', 'parameter_type', 'scope_type'] as $name) {
            $this->assertTrue($page->instance()->getTable()->getFilter($name)->isMultiple());
        }
        $this->assertSame(PricingParameterForm::parameterTypeOptions(), $page->instance()->getTable()->getFilter('parameter_type')->getOptions());
        $this->assertSame(PricingParameterForm::scopeTypeOptions(), $page->instance()->getTable()->getFilter('scope_type')->getOptions());
        $page->filterTable('pricing_project_id', [$this->first->id])->assertCountTableRecords(4)
            ->filterTable('parameter_type', ['customs_percent'])->assertCountTableRecords(3)
            ->filterTable('scope_type', ['global', 'material_composition'])->assertCountTableRecords(2)
            ->assertCanSeeTableRecords([$this->global, $this->material]);
        $page->filterTable('pricing_project_id', [$this->first->id, $this->second->id])->assertCountTableRecords(3);
        $page->filterTable('scope_type', ['material_composition'])->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([$this->material]);
    }

    public function test_save_and_cancel_in_edit_modal_preserve_filters_search_and_sort(): void
    {
        $page = $this->filteredList()->assertCanSeeTableRecords([$this->material])->assertCountTableRecords(1);
        $before = $this->state($page);
        $page->mountTableAction('edit', $this->material)->setTableActionData(['value' => 11])->callMountedTableAction()
            ->assertHasNoTableActionErrors()->assertTableActionNotMounted('edit');
        $this->assertSame($before, $this->state($page));
        $this->assertEquals(11, $this->material->fresh()->value);
        $page->mountTableAction('edit', $this->material)->setTableActionData(['value' => 18])->call('unmountAction');
        $this->assertSame($before, $this->state($page));
        $this->assertEquals(11, $this->material->fresh()->value);
        $restored = Livewire::test(ListPricingParameters::class)->assertCountTableRecords(1)->assertCanSeeTableRecords([$this->material]);
        $this->assertSame($before, $this->state($restored));
    }

    public function test_full_edit_save_returns_to_filtered_list_and_create_keeps_existing_list_state(): void
    {
        $before = $this->state($this->filteredList());
        Livewire::test(EditPricingParameter::class, ['record' => $this->material->id])
            ->fillForm(['value' => 12])->call('save')->assertHasNoFormErrors()
            ->assertRedirect(PricingParameterResource::getUrl('index'));
        $this->assertSame($before, $this->state(Livewire::test(ListPricingParameters::class)));
        Livewire::test(CreatePricingParameter::class)->fillForm(['pricing_project_id' => $this->first->id,
            'parameter_type' => 'customs_percent', 'scope_type' => 'supplier_country', 'supplier_country_code' => 'RO',
            'value' => 5, 'active' => true])->call('create')->assertHasNoFormErrors()
            ->assertRedirect(PricingParameterResource::getUrl('index'));
        $this->assertSame($before, $this->state(Livewire::test(ListPricingParameters::class)->assertCountTableRecords(1)));
    }

    public function test_leaving_full_page_without_saving_keeps_list_state_and_does_not_change_record(): void
    {
        $before = $this->state($this->filteredList());
        $edit = Livewire::test(EditPricingParameter::class, ['record' => $this->material->id])->fillForm(['value' => 25]);
        $cancel = (new \ReflectionMethod($edit->instance(), 'getCancelFormAction'))->invoke($edit->instance());
        $this->assertStringContainsString('window.history.back()', $cancel->getAlpineClickHandler());
        $this->assertSame($before, $this->state(Livewire::test(ListPricingParameters::class)));
        $this->assertEquals(9.7, $this->material->fresh()->value);
    }

    public function test_reset_is_remembered_and_different_lists_do_not_share_filter_state(): void
    {
        $page = $this->filteredList();
        $other = Livewire::test(ListExchangeRates::class);
        $this->assertNotSame($page->instance()->getTableFiltersSessionKey(), $other->instance()->getTableFiltersSessionKey());
        $this->assertArrayNotHasKey('parameter_type', $other->instance()->tableFilters);
        $page->call('removeTableFilters')->assertCountTableRecords(5);
        $restored = Livewire::test(ListPricingParameters::class)->assertCountTableRecords(5);
        $this->assertEmpty($restored->instance()->tableFilters['scope_type']['values']);
        $this->assertSame('', $restored->instance()->tableSearch);
        $this->assertEmpty(array_filter($restored->instance()->tableColumnSearches));
    }

    public function test_apply_is_deferred_and_button_closes_dropdown_only_after_server_application(): void
    {
        $page = Livewire::test(ListPricingParameters::class)->assertCountTableRecords(5);
        $table = $page->instance()->getTable();
        $handler = $table->getFiltersApplyAction()->getAlpineClickHandler();
        $this->assertSame('await $wire.applyTableFilters(); close()', $handler);
        $this->assertStringContainsString(htmlspecialchars($handler, ENT_QUOTES), $page->html());
        $page->set('tableDeferredFilters.scope_type.values', ['material_composition'])->assertCountTableRecords(5);
        Livewire::test(ListPricingParameters::class)->assertCountTableRecords(5);
        $page->call('applyTableFilters')->assertCountTableRecords(1)->assertCanSeeTableRecords([$this->material]);
        Livewire::test(ListPricingParameters::class)->assertCountTableRecords(1);
        // Inline filter layouts must not invoke the browser's global window.close().
        $table->filtersLayout(FiltersLayout::AboveContent);
        $this->assertNull($table->getFiltersApplyAction()->getAlpineClickHandler());
    }

    public function test_refresh_preserves_state_but_deliberate_reset_still_clears_filters(): void
    {
        $page = $this->filteredList();
        $before = $this->state($page);
        AdminResourceTable::refreshPreservingState($page->instance());
        $this->assertSame($before, $this->state($page));
        $page->call('resetTable')->assertCountTableRecords(1); // Global and column search remain, filters are reset.
        $this->assertEmpty($page->instance()->tableFilters['scope_type']['values']);
    }

    public function test_every_registered_admin_resource_including_plugin_lists_gets_the_shared_defaults(): void
    {
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if (! $resource::hasPage('index')) {
                continue;
            }
            $page = Livewire::test($resource::getPages()['index']->getPage())->assertSuccessful();
            $table = $page->instance()->getTable();
            $this->assertTrue($table->persistsFiltersInSession(), $resource);
            $this->assertTrue($table->persistsSearchInSession(), $resource);
            $this->assertTrue($table->persistsColumnSearchesInSession(), $resource);
            $this->assertTrue($table->persistsSortInSession(), $resource);
            $this->assertSame('await $wire.applyTableFilters(); close()', $table->getFiltersApplyAction()->getAlpineClickHandler(), $resource);
        }
    }

    public function test_role_plugin_search_and_sort_survive_leaving_the_page(): void
    {
        $role = Role::findOrCreate('Export reviewer', 'web');
        $page = Livewire::test(ListRoles::class)->searchTable('Export')->sortTable('name', 'desc')->assertCanSeeTableRecords([$role]);
        $restored = Livewire::test(ListRoles::class)->assertCanSeeTableRecords([$role]);
        $this->assertSame($page->instance()->tableSearch, $restored->instance()->tableSearch);
        $this->assertSame($page->instance()->tableSort, $restored->instance()->tableSort);
    }

    public function test_relation_list_keeps_filter_after_save_cancel_and_remount(): void
    {
        $product = Product::firstOrFail();
        $color = Color::create(['product_id' => $product->id, 'code' => 'RD', 'name_hu' => 'Piros', 'active' => true]);
        $arguments = ['ownerRecord' => $product, 'pageClass' => EditProduct::class];
        $page = Livewire::test(ColorsRelationManager::class, $arguments)->filterTable('active', true)
            ->searchTable('Piros')->sortTable('code', 'asc');
        $before = $this->state($page);
        $page->mountTableAction('edit', $color)->setTableActionData(['name_hu' => 'Másik'])->call('unmountAction');
        $this->assertSame('Piros', $color->fresh()->name_hu);
        $this->assertSame($before, $this->state($page));
        $page->mountTableAction('edit', $color)->setTableActionData(['active' => false])->callMountedTableAction()
            ->assertHasNoTableActionErrors()->assertCountTableRecords(0);
        $this->assertEquals($before, $this->state($page));
        $restored = Livewire::test(ColorsRelationManager::class, $arguments)->assertCountTableRecords(0);
        $this->assertEquals($before, $this->state($restored));
    }

    public function test_shared_defaults_do_not_apply_outside_the_admin_panel(): void
    {
        $page = Livewire::test(ListPricingParameters::class);
        Filament::setCurrentPanel(null);
        $table = Table::make($page->instance());
        $this->assertFalse($table->persistsFiltersInSession());
        $this->assertFalse($table->persistsSearchInSession());
        $this->assertNull($table->getFiltersApplyAction()->getAlpineClickHandler());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // A plain Livewire HasTable outside Filament is not an admin list either.
        $plain = \Mockery::mock(HasTable::class);
        $this->assertFalse(Table::make($plain)->persistsFiltersInSession());
    }
}

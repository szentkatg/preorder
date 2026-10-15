<?php

namespace Tests\Feature;

use App\Exports\AdminTableExport;
use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Models\AdminUiPreference;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Enums\Alignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PricingDecimalPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private PricingCalculationRow $cost;

    private PricingCalculationRow $retail;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = $this->superAdmin();
        $this->actingAs($this->user);
        $season = Season::create(['code' => 'DEC', 'name' => 'Tizedes teszt', 'active' => true]);
        $huf = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'name_hu' => 'Forint', 'active' => true]);
        $usd = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'name_hu' => 'Dollár', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'DEC', 'name_hu' => 'Teszt', 'active' => true]);
        $product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
            'model_code' => 'DEC-001', 'name_hu' => 'Tizedes termék', 'active' => true]);
        $project = PricingProject::create(['season_id' => $season->id, 'name' => 'Tizedes teszt', 'status' => 'active', 'active' => true]);
        $list = PriceList::create(['code' => 'DEC-HUF', 'name_hu' => 'Kisker teszt', 'type' => 'retail', 'currency_id' => $huf->id, 'active' => true]);
        $this->cost = PricingCalculationRow::create(['pricing_project_id' => $project->id, 'product_id' => $product->id,
            'currency_id' => $huf->id, 'purchase_currency_id' => $usd->id, 'price_type' => 'cost',
            'purchase_price' => '8.8', 'exchange_rate' => '380.123456', 'calculated_price' => '123.4567',
            'shipping_cost_percent' => '8.125', 'customs_percent' => '12.125']);
        $this->retail = PricingCalculationRow::create(['pricing_project_id' => $project->id, 'product_id' => $product->id,
            'currency_id' => $huf->id, 'purchase_currency_id' => $usd->id, 'price_type' => 'retail', 'price_list_id' => $list->id,
            'purchase_price' => '8.8', 'base_price' => '123.4567', 'multiplier' => '4.7', 'unrounded_price' => '580.2468',
            'calculated_price' => '579.3456', 'manual_price' => '496.1299']);
    }

    public function test_modal_saves_per_column_settings_and_closes_without_changing_calculation_data(): void
    {
        $before = $this->retail->fresh()->getAttributes();
        $page = Livewire::test(PricingHufRetailCalculations::class)
            ->callAction('decimalPlaces', ['final_price' => 2, 'purchase_price' => 6, 'multiplier' => 0])
            ->assertHasNoErrors()->assertActionNotMounted('decimalPlaces');
        $this->assertSame(['purchase_price' => 6, 'multiplier' => 0, 'final_price' => 2], $page->instance()->pricingDecimalPlaces);
        $this->assertSame('496,13', $this->formatted($page, 'final_price', $this->retail));
        $this->assertSame('8,800000', $this->formatted($page, 'purchase_price', $this->retail));
        $this->assertSame('4', $this->formatted($page, 'multiplier', $this->retail));
        $page->assertSee('data-pricing-average-multiplier="Átlag: 4,02"', false);
        $this->assertSame($before, $this->retail->fresh()->getAttributes());
        $this->assertSame(0, PricingParameter::count());
        $input = $page->instance()->getTable()->getColumn('manual_price')->record($this->retail);
        $this->assertSame('496.1299', $input->getState());
    }

    public function test_settings_survive_page_reentry_without_disturbing_layout_or_filters(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $page->call('savePricingColumnWidths', ['final-price' => 120]);
        $columns = $page->instance()->tableColumns;
        $page->filterTable('price_list_id', $this->retail->price_list_id)
            ->callAction('decimalPlaces', ['final_price' => 0]);
        $this->assertSame($columns, $page->instance()->tableColumns);
        $this->assertSame(['final-price' => 120], $page->instance()->pricingColumnWidths);
        $this->assertEquals($this->retail->price_list_id, $page->instance()->tableFilters['price_list_id']['value']);
        session()->flush();
        $fresh = Livewire::test(PricingHufRetailCalculations::class);
        $this->assertSame(['final_price' => 0], $fresh->instance()->pricingDecimalPlaces);
        $this->assertSame(['final-price' => 120], $fresh->instance()->pricingColumnWidths);
        $this->assertSame('496', $this->formatted($fresh, 'final_price', $this->retail));
    }

    public function test_preferences_are_separate_for_each_user_and_workspace(): void
    {
        Livewire::test(PricingHufRetailCalculations::class)->callAction('decimalPlaces', ['purchase_price' => 0]);
        $cost = Livewire::test(PricingCostCalculations::class)->callAction('decimalPlaces', ['purchase_price' => 3]);
        $this->assertSame('8,800', $this->formatted($cost, 'purchase_price', $this->cost));
        $other = $this->superAdmin();
        $this->actingAs($other);
        $otherPage = Livewire::test(PricingHufRetailCalculations::class);
        $this->assertSame([], $otherPage->instance()->pricingDecimalPlaces);
        $this->assertSame('8,8', $this->formatted($otherPage, 'purchase_price', $this->retail));
        $otherPage->callAction('decimalPlaces', ['purchase_price' => 2]);
        $this->actingAs($this->user);
        $this->assertSame(['purchase_price' => 0], Livewire::test(PricingHufRetailCalculations::class)->instance()->pricingDecimalPlaces);
        $this->assertSame(['purchase_price' => 3], Livewire::test(PricingCostCalculations::class)->instance()->pricingDecimalPlaces);
    }

    public function test_default_option_restores_original_formatting_and_zero_is_not_missing(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class)->callAction('decimalPlaces', ['purchase_price' => 0, 'final_price' => 6]);
        $this->assertSame('9', $this->formatted($page, 'purchase_price', $this->retail));
        $this->assertSame('496,129900', $this->formatted($page, 'final_price', $this->retail));
        $page->callAction('decimalPlaces', ['purchase_price' => null, 'final_price' => null]);
        $this->assertSame([], $page->instance()->pricingDecimalPlaces);
        $this->assertSame('8,8', $this->formatted($page, 'purchase_price', $this->retail));
        $this->assertSame('496,1299', $this->formatted($page, 'final_price', $this->retail));
        $this->assertSame('-', $page->instance()->formatPricingDecimal(null, 'final_price'));
        $this->assertSame('0', $page->instance()->formatPricingDecimal(0, 'final_price'));
    }

    public function test_cost_value_columns_are_independent_and_percent_inputs_keep_full_precision(): void
    {
        $before = $this->cost->fresh()->getAttributes();
        $page = Livewire::test(PricingCostCalculations::class)->callAction('decimalPlaces', [
            'purchase_price' => 2, 'exchange_rate' => 6, 'calculated_price' => 0, 'final_price' => 3,
        ])->assertHasNoErrors();
        $this->assertSame('8,80', $this->formatted($page, 'purchase_price', $this->cost));
        $this->assertSame('380,123456', $this->formatted($page, 'exchange_rate', $this->cost));
        $this->assertSame('123', $this->formatted($page, 'calculated_price', $this->cost));
        $this->assertSame('123,457', $this->formatted($page, 'final_price', $this->cost));
        $shipping = $page->instance()->getTable()->getColumn('shipping_cost_percent')->record($this->cost);
        $customs = $page->instance()->getTable()->getColumn('customs_percent')->record($this->cost);
        $this->assertSame('8.1250', $shipping->getState());
        $this->assertSame('12.1250', $customs->getState());
        $this->assertSame('8.125', $shipping->getExtraCellAttributes()['data-cost-value']);
        $this->assertSame('12.125', $customs->getExtraCellAttributes()['data-cost-value']);
        $this->assertSame($before, $this->cost->fresh()->getAttributes());
    }

    public function test_precision_cannot_be_saved_without_workspace_access(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $viewer = User::factory()->create();
        $role = Role::findOrCreate('cost_only_viewer', 'web');
        $role->givePermissionTo([
            Permission::findOrCreate('View:PricingCostCalculations', 'web'),
            Permission::findOrCreate('ViewAny:PricingCalculationRow', 'web'),
        ]);
        $viewer->assignRole($role);
        $this->actingAs($viewer);
        try {
            $page->instance()->savePricingDecimalPlaces(['final_price' => 2]);
            $this->fail('Workspace permission was ignored.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, AdminUiPreference::where('preference_key', 'pricing_huf_retail_calculation_decimal_places')->count());
    }

    public function test_out_of_range_preferences_are_rejected_and_corrupt_saved_entries_are_ignored(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $page->callAction('decimalPlaces', ['final_price' => 7])->assertHasActionErrors(['final_price'])->unmountAction();
        foreach ([-1, 7, '2.5', 'not-a-number'] as $digits) {
            try {
                $page->instance()->savePricingDecimalPlaces(['final_price' => $digits]);
                $this->fail('Invalid precision was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('final_price', $exception->errors());
            }
        }
        $this->assertSame(0, AdminUiPreference::where('preference_key', 'pricing_huf_retail_calculation_decimal_places')->count());
        AdminUiPreference::create(['user_id' => $this->user->id, 'preference_key' => 'pricing_huf_retail_calculation_decimal_places',
            'value' => ['final_price' => 100000, 'purchase_price' => '2', 'model_code' => 0, 'multiplier' => ['bad']]]);
        $this->assertSame(['purchase_price' => 2], Livewire::test(PricingHufRetailCalculations::class)->instance()->pricingDecimalPlaces);
    }

    public function test_read_only_user_can_change_own_display_settings_but_not_prices(): void
    {
        $viewer = User::factory()->create();
        $role = Role::findOrCreate('decimal_viewer', 'web');
        $role->givePermissionTo([
            Permission::findOrCreate('View:PricingHufRetailCalculations', 'web'),
            Permission::findOrCreate('ViewAny:PricingCalculationRow', 'web'),
        ]);
        $viewer->assignRole($role);
        $this->actingAs($viewer);
        Livewire::test(PricingHufRetailCalculations::class)->callAction('decimalPlaces', ['final_price' => 2])->assertHasNoErrors();
        $this->assertSame($viewer->id, AdminUiPreference::where('preference_key', 'pricing_huf_retail_calculation_decimal_places')->sole()->user_id);
        $this->assertSame('496.1299', $this->retail->fresh()->final_price);
        $this->assertFalse($viewer->can('Update:PricingCalculationRow'));
    }

    public function test_decimal_property_cannot_be_overwritten_by_a_forged_livewire_request(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('pricingDecimalPlaces.final_price', 9999);
    }

    public function test_excel_formats_match_selected_columns_without_rounding_values_or_formatting_identifiers(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class)->callAction('decimalPlaces', ['final_price' => 0, 'purchase_price' => 4, 'multiplier' => 2]);
        $instance = $page->instance();
        $table = $instance->getTable();
        $columns = [$table->getColumn('multiplier'), $table->getColumn('product.model_code'), $table->getColumn('purchase_price'), $table->getColumn('final_price')];
        $formats = $instance->pricingDecimalExportFormats($columns);
        $this->assertSame(['A' => '#,##0.00', 'C' => '#,##0.0000', 'D' => '#,##0'], $formats);
        $export = new AdminTableExport(PricingCalculationRow::query(), $columns, $formats);
        $mapped = $export->map($this->retail);
        $this->assertEqualsWithDelta(496.1299 / 123.4567, $mapped[0], 0.00000001);
        $this->assertSame('DEC-001', $mapped[1]);
        $this->assertSame(8.8, $mapped[2]);
        $this->assertSame(496.1299, $mapped[3]);
        $this->assertSame($formats, $export->columnFormats());
        Excel::fake();
        $this->travelTo(now()->setTime(12, 0, 0));
        $page->callTableAction('exportExcel');
        Excel::assertDownloaded('pricing_calculation_rows-'.now()->format('Ymd-His').'.xlsx', function (AdminTableExport $export): bool {
            return count($export->columnFormats()) === 3 && $export->query()->count() === 1;
        });
        $this->travelBack();
    }

    public function test_pricing_values_headers_and_editable_inputs_are_right_aligned(): void
    {
        foreach ([
            PricingCostCalculations::class => ['purchase_price', 'exchange_rate', 'shipping_cost_percent',
                'customs_percent', 'candidate_count', 'calculated_price', 'manual_price', 'final_price'],
            PricingHufRetailCalculations::class => ['purchase_price', 'base_price', 'multiplier', 'calculation_multiplier',
                'unrounded_price', 'calculated_price', 'manual_price', 'final_price', 'target_purchase_price'],
        ] as $pageClass => $names) {
            $page = Livewire::test($pageClass);
            $columns = $page->instance()->tableColumns;
            foreach ($columns as &$column) {
                $column['isToggled'] = true;
            }
            unset($column);
            $page->call('applyTableColumnManager', $columns)->assertSuccessful();
            $table = $page->instance()->getTable();
            foreach ($names as $name) {
                $this->assertSame(Alignment::End, $table->getColumn($name)->getAlignment(), $pageClass.': '.$name);
            }
            foreach (['product.model_code', 'product.name_hu', 'purchaseCurrency.code', 'status'] as $name) {
                $this->assertNotSame(Alignment::End, $table->getColumn($name)->getAlignment(), $name);
            }

            $dom = new \DOMDocument;
            $previousErrors = libxml_use_internal_errors(true);
            try {
                $dom->loadHTML('<?xml encoding="UTF-8">'.$page->html());
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousErrors);
            }
            $xpath = new \DOMXPath($dom);
            foreach ($names as $name) {
                $key = str_replace('_', '-', $name);
                $header = $xpath->query('//th[contains(@class, "fi-ta-header-cell-'.$key.'")]')->item(0);
                $this->assertNotNull($header, 'Missing header: '.$name);
                $this->assertStringContainsString('fi-align-end', $header->getAttribute('class'), $name);
            }
            if ($pageClass === PricingCostCalculations::class) {
                foreach (['shipping_cost_percent', 'customs_percent'] as $name) {
                    $key = str_replace('_', '-', $name);
                    $cell = $xpath->query('//td[contains(@class, "fi-ta-cell-'.$key.'")]')->item(0);
                    $this->assertStringContainsString('fi-align-end', $cell->getAttribute('class'));
                    $this->assertTrue($cell->hasAttribute('data-cost-value'));
                    $this->assertSame(0, $xpath->query('//td[contains(@class, "fi-ta-cell-'.$key.'")]//input')->length);
                }
            }
            $inputNames = $pageClass === PricingCostCalculations::class ? [] : ['manual_price'];
            foreach ($inputNames as $name) {
                $key = str_replace('_', '-', $name);
                $input = $xpath->query('//td[contains(@class, "fi-ta-cell-'.$key.'")]//input[contains(@class, "fi-input")]')->item(0);
                $this->assertNotNull($input, 'Missing input: '.$name);
                $this->assertStringContainsString('fi-align-end', $input->getAttribute('class'));
            }
        }
        $this->assertSame('496.1299', $this->retail->fresh()->final_price);
        $this->assertSame('123.4567', $this->cost->fresh()->final_price);
    }

    public function test_width_save_and_reset_do_not_resend_the_table_but_keep_user_preferences(): void
    {
        $before = $this->retail->fresh()->getAttributes();
        foreach ([PricingCostCalculations::class, PricingHufRetailCalculations::class] as $pageClass) {
            $page = Livewire::test($pageClass)->call('savePricingColumnWidths', ['final-price' => 110]);
            $this->assertArrayNotHasKey('html', $page->effects);
            $this->assertSame(['final-price' => 110], $page->instance()->pricingColumnWidths);
            $this->assertLessThan(20000, strlen(json_encode(['snapshot' => $page->snapshot, 'effects' => $page->effects])));
            $fresh = Livewire::test($pageClass);
            $this->assertSame(['final-price' => 110], $fresh->instance()->pricingColumnWidths);
            $fresh->call('resetPricingColumnWidths');
            $this->assertArrayNotHasKey('html', $fresh->effects);
            $this->assertSame([], $fresh->instance()->pricingColumnWidths);
            $this->assertSame([], Livewire::test($pageClass)->instance()->pricingColumnWidths);
        }
        $this->assertSame($before, $this->retail->fresh()->getAttributes());
    }

    public function test_width_save_does_not_suppress_another_bundled_table_change(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $columns = $page->instance()->tableColumns;
        $page->update([
            ['method' => 'savePricingColumnWidths', 'params' => [['final-price' => 110]]],
            ['method' => 'applyTableColumnManager', 'params' => [$columns]],
        ]);
        $this->assertArrayHasKey('html', $page->effects);
        $this->assertSame(['final-price' => 110], $page->instance()->pricingColumnWidths);
        $page->assertSee('DEC-001');
    }

    private function formatted($page, string $field, PricingCalculationRow $row): string
    {
        $column = $page->instance()->getTable()->getColumn($field)->record($row);

        return $column->formatState($column->getState());
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $user;
    }
}

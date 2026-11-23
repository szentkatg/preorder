<?php

namespace Tests\Feature;

use App\Filament\Pages\PricingForeignRetailCalculations;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Models\Color;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\PricingRoundingRule;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\HufRetailCalculationService;
use App\Services\Pricing\HufRetailClipboardImportService;
use App\Services\Pricing\RetailPriceReviewService;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HufRetailClipboardImportTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private PriceList $list;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8))]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $season = Season::create(['code' => 'W27', 'name' => 'Teszt', 'active' => true]);
        ItemMainGroup::create(['code' => 'BAG', 'name' => 'Táska', 'active' => true]);
        $huf = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Őszi árprojekt', 'status' => 'active', 'active' => true]);
        $this->list = PriceList::create(['code' => 'Retail_HU', 'name' => 'HUF kisker', 'type' => 'retail', 'currency_id' => $huf->id, 'active' => true]);
        PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => 'retail_multiplier', 'scope_type' => 'global', 'value' => 4.7, 'active' => true]);
        PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => 'HUF 990', 'price_type' => 'retail', 'round_to' => 1000, 'mode' => 'nearest', 'adjustment' => -10, 'active' => true]);
        $this->product = $this->addProduct('B10024W27');
    }

    private function addProduct(string $code): Product
    {
        $product = Product::create(['season_id' => $this->project->season_id, 'item_main_group_id' => ItemMainGroup::firstOrFail()->id, 'model_code' => $code, 'name_hu' => 'Teszt modell', 'active' => true]);
        $this->project->products()->attach($product);
        PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $product->id,
            'price_type' => 'cost', 'currency_id' => $this->list->currency_id, 'calculated_price' => 460]);
        app(HufRetailCalculationService::class)->recalculate($this->project, $this->list);

        return $product;
    }

    private function retail(?Product $product = null): PricingCalculationRow
    {
        return PricingCalculationRow::where('pricing_project_id', $this->project->id)->where('price_list_id', $this->list->id)
            ->where('product_id', ($product ?? $this->product)->id)->where('color_key', 0)->where('price_type', 'retail')->firstOrFail();
    }

    private function import(string $text): array
    {
        return app(HufRetailClipboardImportService::class)->import($this->project, $this->list, $text);
    }

    public function test_paste_normalizes_codes_parses_hungarian_prices_and_reports_original_line_numbers(): void
    {
        $equalProduct = $this->addProduct('B20000W27');
        $source = $this->retail();
        app(RetailPriceReviewService::class)->approveCurrentPrice($source);
        $dependent = PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $this->product->id,
            'price_list_id' => $this->list->id, 'price_type' => 'outlet', 'currency_id' => $this->list->currency_id,
            'source_retail_row_id' => $source->id, 'calculated_price' => 990, 'requires_review' => false]);
        $result = $this->import("Modellkód\tÁr\r\n B -10 024 W27 \t2\u{00a0}490,50 Ft\r\nMISSING\t1 990\r\n\r\nB20000W27\t1 990,00");
        $this->assertSame(1, $result['updated_models']);
        $this->assertSame(1, $result['updated_rows']);
        $this->assertSame(1, $result['equal']);
        $this->assertSame(1, $result['not_found']);
        $this->assertSame([2, 3, 5], array_column($result['entries'], 'line'));
        $this->assertSame(['updated', 'not_found', 'equal'], array_column($result['entries'], 'status'));
        $this->assertSame('B10024W27', $result['entries'][0]['model_code']);
        $this->assertSame('2490.5000', $source->fresh()->manual_price);
        $this->assertSame('2490.5000', $source->fresh()->final_price);
        $this->assertSame('1990.0000', $source->fresh()->calculated_price);
        $this->assertSame('calculated', $source->fresh()->status);
        $this->assertNull($source->fresh()->approved_by);
        $this->assertNull($this->retail($equalProduct)->manual_price);
        $this->assertTrue($dependent->fresh()->requires_review);
        $this->assertSame(0, DB::table('price_list_items')->count());
    }

    public function test_equal_calculated_price_and_identical_existing_manual_price_preserve_approvals(): void
    {
        $review = app(RetailPriceReviewService::class);
        $review->setManualPrice($this->retail(), '2490');
        $review->approveCurrentPrice($this->retail());
        $before = $this->retail()->getAttributes();
        $this->assertSame('equal', $this->import("B10024W27\t1990")['entries'][0]['status']);
        $this->assertSame($before, $this->retail()->getAttributes());
        $this->assertSame('unchanged', $this->import("B10024W27\t2490")['entries'][0]['status']);
        $this->assertSame($before, $this->retail()->getAttributes());
    }

    public function test_invalid_rows_and_duplicate_normalized_codes_are_reported_without_writes(): void
    {
        $before = $this->retail()->getAttributes();
        $result = $this->import("B10024W27\t2490\nB-10024 W27\t2990\nBAD\t0\nBAD2\t-3\nBAD3\tNaN\nBAD4\t=1+2\nBAD5\t1e5\nBAD6\t2.12345\nBAD7\t200\textra\n\t100");
        $this->assertSame(10, $result['errors']);
        $this->assertSame(0, $result['updated_rows']);
        $this->assertSame($before, $this->retail()->getAttributes());
    }

    public function test_ambiguous_cleaned_model_codes_and_models_without_calculations_are_not_imported(): void
    {
        $this->addProduct('B-10024W27');
        $uncalculated = Product::create(['season_id' => $this->project->season_id, 'item_main_group_id' => ItemMainGroup::firstOrFail()->id, 'model_code' => 'UNCALCULATED', 'name_hu' => 'Még nincs ára', 'active' => true]);
        $this->project->products()->attach($uncalculated);
        $result = $this->import("B10024W27\t2490\nUNCALCULATED\t2990");
        $this->assertSame(['error', 'not_found'], array_column($result['entries'], 'status'));
        $this->assertSame(0, $result['updated_rows']);
        $this->assertNull($this->retail()->manual_price);
    }

    public function test_only_selected_project_and_price_list_are_changed(): void
    {
        $otherList = $this->list->replicate();
        $otherList->code = 'OTHER_HU';
        $otherList->save();
        app(HufRetailCalculationService::class)->recalculate($this->project, $otherList);
        $otherProject = PricingProject::create(['season_id' => $this->project->season_id, 'name' => 'Másik', 'status' => 'active', 'active' => true]);
        $otherRow = $this->retail()->replicate();
        $otherRow->pricing_project_id = $otherProject->id;
        $otherRow->save();
        $this->import("B10024W27\t2490");
        $this->assertSame('2490.0000', $this->retail()->manual_price);
        $this->assertNull($otherRow->fresh()->manual_price);
        $this->assertNull(PricingCalculationRow::where('price_list_id', $otherList->id)->where('price_type', 'retail')->firstOrFail()->manual_price);
    }

    public function test_color_rows_are_compared_individually_and_model_changes_are_atomic(): void
    {
        $color = Color::create(['product_id' => $this->product->id, 'code' => 'BK', 'name_hu' => 'BLACK', 'active' => true]);
        $colorCost = PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $this->product->id,
            'color_id' => $color->id, 'price_type' => 'cost', 'currency_id' => $this->list->currency_id, 'calculated_price' => 660]);
        app(HufRetailCalculationService::class)->recalculate($this->project, $this->list);
        $colorRetail = PricingCalculationRow::where('price_type', 'retail')->where('color_id', $color->id)->firstOrFail();
        $result = $this->import("B10024W27\t1990");
        $this->assertSame(1, $result['updated_rows']);
        $this->assertSame(1, $result['entries'][0]['equal_rows']);
        $this->assertNull($this->retail()->manual_price);
        $this->assertSame('1990.0000', $colorRetail->fresh()->manual_price);
        $colorCost->update(['calculated_price' => 1000]);
        $before = $this->retail()->getAttributes();
        $result = $this->import("B10024W27\t2490");
        $this->assertSame('error', $result['entries'][0]['status']);
        $this->assertSame(0, $result['updated_rows']);
        $this->assertSame($before, $this->retail()->getAttributes());
        $this->assertSame('1990.0000', $colorRetail->fresh()->manual_price);
    }

    public function test_closed_project_and_invalid_price_list_are_rejected(): void
    {
        $this->list->update(['type' => 'outlet']);
        try {
            $this->import("B10024W27\t2490");
            $this->fail('Outlet list must not be imported.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('HUF kisker árlistát', $exception->getMessage());
        }
        $this->list->update(['type' => 'retail']);
        $this->project->update(['status' => PricingProject::STATUS_CLOSED]);
        $this->expectException(DomainException::class);
        $this->import("B10024W27\t2490");
    }

    public function test_empty_and_oversized_inputs_do_not_write_any_prices(): void
    {
        foreach (["Modellkód\tÁr\n", str_repeat('x', HufRetailClipboardImportService::MAX_LENGTH + 1), str_repeat("B10024W27\t2490\n", HufRetailClipboardImportService::MAX_ROWS + 1)] as $input) {
            try {
                $this->import($input);
                $this->fail('Invalid input was accepted.');
            } catch (DomainException) {
                $this->assertNull($this->retail()->manual_price);
            }
        }
    }

    public function test_action_shows_the_report_updates_averages_and_preserves_filters_and_sort(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class)
            ->assertActionVisible('importHufRetailClipboard')->sortTable('final_price', 'desc')
            ->searchTable('B10024W27');
        $page->callAction('importHufRetailClipboard', ['price_list_id' => $this->list->id,
            'clipboard_data' => "B10024W27\t2490\nMISSING\t2990"])->assertHasNoErrors()
            ->assertActionMounted('hufRetailClipboardResult')
            ->assertSet('tableSearch', 'B10024W27')->assertSet('tableSort', 'final_price:desc');
        $this->assertStringContainsString('Nem található modell', $page->effects['partials']['action-modals'] ?? '');
        $this->assertSame('2490.0000', $this->retail()->manual_price);
        $this->assertEqualsWithDelta(2490 / 460, $page->instance()->averageRetailMultiplier, 0.000001);
        $this->assertSame(1, $page->get('hufRetailClipboardResult')['not_found']);
        $this->assertArrayNotHasKey('entries', $page->get('hufRetailClipboardResult'));
        $page->call('unmountAction')->assertActionNotMounted('hufRetailClipboardResult');
        $this->assertSame('2490.0000', $this->retail()->manual_price);
    }

    public function test_read_only_user_cannot_call_import_or_service_and_closed_action_is_hidden(): void
    {
        $this->project->update(['status' => PricingProject::STATUS_CLOSED]);
        Livewire::test(PricingHufRetailCalculations::class)->assertActionHidden('importHufRetailClipboard');
        $this->project->update(['status' => 'active']);
        $reader = User::factory()->create();
        $reader->givePermissionTo([Permission::findOrCreate('View:PricingHufRetailCalculations', 'web'), Permission::findOrCreate('ViewAny:PricingCalculationRow', 'web')]);
        $this->actingAs($reader);
        Livewire::test(PricingHufRetailCalculations::class)->assertActionHidden('importHufRetailClipboard')
            ->mountAction('importHufRetailClipboard')->assertActionNotMounted('importHufRetailClipboard');
        $this->expectException(AuthorizationException::class);
        $this->import("B10024W27\t2490");
    }

    public function test_foreign_retail_page_cannot_mount_inherited_huf_import(): void
    {
        Livewire::test(PricingForeignRetailCalculations::class)->mountAction('importHufRetailClipboard')
            ->assertActionNotMounted('importHufRetailClipboard');
        $this->assertNull($this->retail()->manual_price);
    }

    public function test_editor_can_import_without_cost_or_project_write_permissions_and_revocation_is_rechecked(): void
    {
        $editor = User::factory()->create();
        $editor->givePermissionTo(array_map(fn ($name) => Permission::findOrCreate($name, 'web'), [
            'View:PricingHufRetailCalculations', 'ViewAny:PricingCalculationRow', 'Update:PricingCalculationRow',
        ]));
        $this->actingAs($editor);
        $page = Livewire::test(PricingHufRetailCalculations::class)->assertActionVisible('importHufRetailClipboard')
            ->callAction('importHufRetailClipboard', ['price_list_id' => $this->list->id, 'clipboard_data' => "B10024W27\t2490"])
            ->assertHasNoErrors()->assertActionMounted('hufRetailClipboardResult')->call('unmountAction')
            ->mountAction('importHufRetailClipboard');
        $this->assertSame('2490.0000', $this->retail()->manual_price);
        $editor->revokePermissionTo('Update:PricingCalculationRow');
        $page->fillForm(['price_list_id' => $this->list->id, 'clipboard_data' => "B10024W27\t2990"])
            ->callMountedAction();
        $this->assertSame('2490.0000', $this->retail()->manual_price);
    }

    public function test_unexpected_failure_rolls_back_the_whole_batch(): void
    {
        $other = $this->addProduct('OTHER');
        $this->mock(RetailPriceReviewService::class, function ($mock) use ($other): void {
            $mock->shouldReceive('setManualPrice')->once()->withArgs(fn ($row, $price) => $row->product_id === $this->product->id)
                ->andReturnUsing(function ($row, $price) {
                    $row->update(['manual_price' => $price]);

                    return $row;
                });
            $mock->shouldReceive('setManualPrice')->once()->withArgs(fn ($row, $price) => $row->product_id === $other->id)
                ->andThrow(new \RuntimeException('Unexpected test failure'));
        });
        try {
            $this->import("B10024W27\t2490\nOTHER\t2990");
            $this->fail('An unexpected error must be propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Unexpected test failure', $exception->getMessage());
            $this->assertNull($this->retail()->manual_price);
            $this->assertNull($this->retail($other)->manual_price);
        }
    }
}

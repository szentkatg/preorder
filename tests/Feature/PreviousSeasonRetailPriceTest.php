<?php

namespace Tests\Feature;

use App\Exports\AdminTableExport;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Models\Brand;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\PreviousSeasonRetailPriceService;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PreviousSeasonRetailPriceTest extends TestCase
{
    use RefreshDatabase;

    private Season $current;

    private Season $previous;

    private PricingProject $project;

    private PriceList $list;

    private Product $product;

    private Product $pair;

    private PricingCalculationRow $row;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $this->current = Season::create(['code' => 'W27', 'name' => 'Tél 27', 'active' => true]);
        $this->previous = Season::create(['code' => 'W26', 'name' => 'Tél 26', 'active' => false]);
        ItemMainGroup::create(['code' => 'TEST', 'name_hu' => 'Teszt', 'active' => true]);
        $huf = Currency::create(['code' => 'HUF', 'name_hu' => 'Forint', 'active' => true]);
        $this->list = PriceList::create(['code' => 'HU', 'name_hu' => 'Kisker', 'type' => 'retail', 'currency_id' => $huf->id, 'active' => true]);
        $this->project = PricingProject::create(['name' => 'Tél', 'season_id' => $this->current->id, 'status' => 'active', 'active' => true]);
        $this->product = $this->product($this->current, 'NACKW27', 'NEW');
        $this->pair = $this->product($this->previous, 'NACKW26', 'OLD');
        PriceListItem::create(['price_list_id' => $this->list->id, 'season_id' => $this->previous->id,
            'product_id' => $this->pair->id, 'net_price' => 9990]);
        $this->row = PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $this->product->id,
            'currency_id' => $huf->id, 'price_type' => 'retail', 'price_list_id' => $this->list->id, 'calculated_price' => 12990]);
    }

    public function test_previous_season_uses_the_same_season_type_not_the_previous_database_id(): void
    {
        $service = app(PreviousSeasonRetailPriceService::class);
        Season::create(['code' => 'S26', 'name' => 'Nyár 26']);
        $summer = Season::create(['code' => 'S27', 'name' => 'Nyár 27']);
        $this->assertSame('W26', $service->previousSeason($this->current)->code);
        $this->assertSame('S26', $service->previousSeason($summer)->code);
        $this->assertNull($service->previousSeason(Season::create(['code' => 'CUSTOM', 'name' => 'Egyedi'])));
    }

    public function test_matching_uses_hungarian_name_and_shows_price_list_item_not_calculation_price(): void
    {
        $row = $this->query()->firstOrFail();
        $this->assertEquals(9990, $row->previous_season_price);
        $this->assertEquals($this->pair->id, $row->matched_previous_product_id);
        $this->assertNotEquals($this->product->model_code, $this->pair->model_code);
        $oldProject = PricingProject::create(['season_id' => $this->previous->id, 'name' => 'Régi', 'status' => 'closed']);
        PricingCalculationRow::create(['pricing_project_id' => $oldProject->id, 'product_id' => $this->pair->id, 'price_type' => 'retail',
            'currency_id' => $this->list->currency_id, 'price_list_id' => $this->list->id, 'calculated_price' => 5555]);
        $this->assertEquals(9990, $this->query()->firstOrFail()->previous_season_price);
    }

    public function test_first_season_name_without_a_suffix_can_match_a_later_suffixed_name(): void
    {
        $this->pair->update(['name_hu' => 'NACK']);
        $this->assertEquals(9990, $this->query()->firstOrFail()->previous_season_price);
        $this->product->update(['name_hu' => 'NACK']);
        $this->assertEquals(9990, $this->query()->firstOrFail()->previous_season_price);
    }

    public function test_accented_names_and_whitespace_are_matched_without_truncating_extra_characters(): void
    {
        $this->product->update(['name_hu' => '  TÁSKAW27  ']);
        $this->pair->update(['name_hu' => 'TÁSKAW26']);
        $this->assertEquals(9990, $this->query()->firstOrFail()->previous_season_price);
        $this->product->update(['name_hu' => 'W27']);
        $this->pair->update(['name_hu' => 'W26']);
        $this->assertNull($this->query()->firstOrFail()->previous_season_price);
    }

    public function test_only_the_matching_season_suffix_is_removed(): void
    {
        $this->pair->update(['name_hu' => 'NACKW25']);
        $this->assertNull($this->query()->firstOrFail()->previous_season_price);
        $this->product->update(['name_hu' => 'NACKW25']);
        $this->assertEquals(9990, $this->query()->firstOrFail()->previous_season_price);
    }

    public function test_ambiguous_matches_and_missing_price_items_return_null_not_an_arbitrary_price(): void
    {
        $duplicate = $this->product($this->previous, 'NACK', 'DUP');
        $this->assertNull($this->query()->firstOrFail()->previous_season_price);
        $this->assertNull($this->query()->firstOrFail()->matched_previous_product_id);
        $this->assertEquals(2, $this->query()->firstOrFail()->previous_season_match_count);
        $duplicate->delete();
        PriceListItem::query()->delete();
        $this->assertNull($this->query()->firstOrFail()->previous_season_price);
        $this->assertEquals($this->pair->id, $this->query()->firstOrFail()->matched_previous_product_id);
    }

    public function test_price_from_another_list_or_a_wrong_season_is_never_used(): void
    {
        PriceListItem::query()->delete();
        $other = PriceList::create(['code' => 'OTHER', 'name_hu' => 'Másik', 'type' => 'retail', 'currency_id' => $this->list->currency_id, 'active' => true]);
        PriceListItem::create(['price_list_id' => $other->id, 'season_id' => $this->previous->id, 'product_id' => $this->pair->id, 'net_price' => 3333]);
        PriceListItem::create(['price_list_id' => $this->list->id, 'season_id' => $this->current->id, 'product_id' => $this->pair->id, 'net_price' => 4444]);
        $this->assertNull($this->query()->firstOrFail()->previous_season_price);
    }

    public function test_manual_pair_none_and_restored_automatic_mode_persist_for_all_retail_lists(): void
    {
        $alternative = $this->product($this->previous, 'OTHERW26', 'OTHER');
        PriceListItem::create(['price_list_id' => $this->list->id, 'season_id' => $this->previous->id, 'product_id' => $alternative->id, 'net_price' => 1111]);
        $service = app(PreviousSeasonRetailPriceService::class);
        $service->setPair($this->project, $this->product, 'manual', $alternative->id);
        $this->assertTrue((bool) $this->product->fresh()->previous_season_match_override);
        $this->assertEquals(1111, $this->query()->firstOrFail()->previous_season_price);
        $service->setPair($this->project, $this->product, 'none', null);
        $this->assertNull($this->query()->firstOrFail()->previous_season_price);
        $service->setPair($this->project, $this->product, 'auto', null);
        $this->assertFalse((bool) $this->product->fresh()->previous_season_match_override);
        $this->assertEquals(9990, $this->query()->firstOrFail()->previous_season_price);
    }

    public function test_manual_pair_outside_previous_season_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        app(PreviousSeasonRetailPriceService::class)->setPair($this->project, $this->product, 'manual', $this->product->id);
    }

    public function test_read_only_users_cannot_change_pairs(): void
    {
        $this->actingAs(User::factory()->create());
        $this->expectException(AuthorizationException::class);
        app(PreviousSeasonRetailPriceService::class)->setPair($this->project, $this->product, 'none', null);
    }

    public function test_closed_project_cannot_change_a_pair_even_using_a_stale_open_project(): void
    {
        $stale = $this->project->fresh();
        $this->project->update(['status' => 'closed']);
        $this->expectException(DomainException::class);
        app(PreviousSeasonRetailPriceService::class)->setPair($stale, $this->product, 'none', null);
    }

    public function test_previous_price_column_is_sortable_searchable_and_numeric_in_excel(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class)->assertSee('Előző szezon kisker HUF')
            ->sortTable('previous_season_price')->assertCanSeeTableRecords([$this->row])
            ->searchTableColumns(['previous_season_price' => '9990'])->assertCanSeeTableRecords([$this->row]);
        $column = $page->instance()->getTable()->getColumn('previous_season_price');
        $export = new AdminTableExport($page->instance()->getTableQueryForExport(), [$column]);
        $this->assertEquals([9990], $export->map($this->query()->firstOrFail()));
        $file = tempnam(sys_get_temp_dir(), 'previous-retail-');
        $previousBinder = Cell::getValueBinder();
        try {
            file_put_contents($file, \Maatwebsite\Excel\Facades\Excel::raw($export, Excel::XLSX));
            Cell::setValueBinder(new DefaultValueBinder);
            $sheet = IOFactory::load($file)->getActiveSheet();
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType());
            $this->assertEquals(9990, $sheet->getCell('A2')->getValue());
        } finally {
            Cell::setValueBinder($previousBinder);
            @unlink($file);
        }
        $page->searchTableColumns(['previous_season_price' => '123'])->assertCanNotSeeTableRecords([$this->row]);
    }

    public function test_pairing_action_is_available_on_the_retail_workspace(): void
    {
        Livewire::test(PricingHufRetailCalculations::class)->callTableAction('pairPreviousSeasonProduct', $this->row, ['mode' => 'none'])
            ->assertHasNoErrors();
        $this->assertTrue((bool) $this->product->fresh()->previous_season_match_override);
        $this->assertNull($this->query()->firstOrFail()->previous_season_price);
    }

    public function test_same_name_from_another_brand_does_not_override_the_same_brand_pair(): void
    {
        $brand = Brand::create(['code' => 'OTHER', 'name' => 'Másik', 'active' => true]);
        $other = $this->product($this->previous, 'NACKW26', 'OTHERBRAND');
        $other->update(['brand_id' => $brand->id]);
        $this->assertEquals($this->pair->id, $this->query()->firstOrFail()->matched_previous_product_id);
        $this->pair->update(['brand_id' => $brand->id]);
        $this->assertNull($this->query()->firstOrFail()->matched_previous_product_id);
    }

    public function test_manual_pair_is_shared_with_other_currency_prices_but_each_list_has_its_own_price(): void
    {
        $eur = Currency::create(['code' => 'EUR', 'name_hu' => 'Euro', 'active' => true]);
        $list = PriceList::create(['code' => 'EUR', 'name_hu' => 'Euro kisker', 'type' => 'retail', 'currency_id' => $eur->id, 'active' => true]);
        $alternative = $this->product($this->previous, 'OTHERW26', 'MANUAL');
        app(PreviousSeasonRetailPriceService::class)->setPair($this->project, $this->product, 'manual', $alternative->id);
        PriceListItem::create(['price_list_id' => $list->id, 'season_id' => $this->previous->id, 'product_id' => $alternative->id, 'net_price' => 24.95]);
        $row = PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $this->product->id,
            'price_list_id' => $list->id, 'currency_id' => $eur->id, 'price_type' => 'retail', 'calculated_price' => 29.95]);
        $selected = app(PreviousSeasonRetailPriceService::class)->addColumns(PricingCalculationRow::whereKey($row->id), $this->current->id)->firstOrFail();
        $this->assertEquals(24.95, $selected->previous_season_price);
        $this->assertEquals($alternative->id, $selected->matched_previous_product_id);
        $this->assertNull($this->query()->firstOrFail()->previous_season_price);
    }

    private function query(): Builder
    {
        return app(PreviousSeasonRetailPriceService::class)->addColumns(PricingCalculationRow::whereKey($this->row->id), $this->current->id);
    }

    public function test_four_hundred_models_are_matched_in_one_sql_query_not_with_per_row_lookups(): void
    {
        for ($index = 1; $index < 400; $index++) {
            $current = $this->product($this->current, 'MODEL'.$index.'W27', 'NEW'.$index);
            $previous = $this->product($this->previous, 'MODEL'.$index.'W26', 'OLD'.$index);
            PriceListItem::create(['price_list_id' => $this->list->id, 'season_id' => $this->previous->id, 'product_id' => $previous->id, 'net_price' => 1000 + $index]);
            PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $current->id,
                'currency_id' => $this->list->currency_id, 'price_type' => 'retail', 'price_list_id' => $this->list->id, 'calculated_price' => 2000 + $index]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $records = app(PreviousSeasonRetailPriceService::class)->addColumns(
                PricingCalculationRow::where('pricing_project_id', $this->project->id), $this->current->id,
            )->get();
            $this->assertCount(400, $records);
            $this->assertCount(400, $records->filter(fn ($record): bool => $record->previous_season_price !== null));
            $this->assertLessThanOrEqual(12, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
        }
    }

    private function product(Season $season, string $name, string $code): Product
    {
        return Product::create(['season_id' => $season->id, 'item_main_group_id' => ItemMainGroup::firstOrFail()->id,
            'model_code' => $code, 'name_hu' => $name, 'active' => true]);
    }
}

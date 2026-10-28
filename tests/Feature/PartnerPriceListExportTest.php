<?php

namespace Tests\Feature;

use App\Exports\PartnerPriceListExport as PriceListWorkbook;
use App\Filament\Pages\PartnerPriceListExport;
use App\Models\Brand;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\ItemMainGroup;
use App\Models\Language;
use App\Models\OrderSheetType;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Season;
use App\Models\Translation;
use App\Models\User;
use App\Services\PartnerPriceListService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\Width;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PartnerPriceListExportTest extends TestCase
{
    use RefreshDatabase;

    protected array $configuration;

    protected Product $product;

    protected PriceList $net;

    protected PriceList $retail;

    protected Season $season;

    protected Language $hu;

    protected Language $en;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $this->hu = Language::create(['code' => 'HU', 'name' => 'Magyar', 'active' => true]);
        $this->en = Language::create(['code' => 'EN', 'name' => 'Angol', 'active' => true]);
        $this->season = Season::create(['code' => 'S27', 'name' => '2027 Nyár', 'active' => true]);
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'active' => true]);
        $usd = Currency::create(['code' => 'USD', 'name' => 'Dollár', 'active' => true]);
        ExchangeRate::create(['season_id' => $this->season->id, 'currency_id' => $usd->id, 'rate_to_huf' => 380, 'active' => true]);
        $brand = Brand::create(['code' => 'HT', 'name' => 'Heavy Tools', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'BAG', 'name' => 'Táska', 'active' => true]);
        $type = OrderSheetType::create(['code' => 'BAGS', 'name' => 'Táskák', 'active' => true]);
        $this->retail = PriceList::create(['code' => 'RETAIL_HU', 'name' => 'Kisker', 'type' => 'retail', 'currency_id' => $currency->id, 'active' => true]);
        $this->net = PriceList::create(['code' => 'WHOLE_HU', 'name' => 'Nagyker', 'type' => 'wholesale', 'currency_id' => $currency->id,
            'retail_price_list_id' => $this->retail->id, 'active' => true]);
        $this->product = Product::create(['season_id' => $this->season->id, 'brand_id' => $brand->id, 'item_main_group_id' => $group->id,
            'order_sheet_type_id' => $type->id, 'model_code' => 'B13-019S27', 'name_hu' => 'Őszi táska',
            'catalog_group_name_hu' => 'Hátizsák', 'catalog_page' => 7,
            'promised_delivery_date' => '2027-04-01', 'active' => true]);
        foreach ([$this->net->id => 7200.5, $this->retail->id => 15990] as $listId => $price) {
            PriceListItem::create(['price_list_id' => $listId, 'season_id' => $this->season->id, 'product_id' => $this->product->id, 'net_price' => $price]);
        }
        $this->configuration = ['season_id' => $this->season->id, 'price_list_id' => $this->net->id,
            'retail_price_list_id' => $this->retail->id, 'language_id' => $this->hu->id, 'format' => 'xlsx',
            'brand_ids' => [], 'item_main_group_ids' => [], 'catalog_groups' => []];
    }

    public function test_page_loads_preview_and_selects_all_products_by_default(): void
    {
        $page = Livewire::test(PartnerPriceListExport::class)->assertSuccessful()->assertSet('appliedFilters', null)
            ->fillForm($this->configuration)->call('loadProducts')->assertHasNoFormErrors()->assertCanSeeTableRecords([$this->product]);
        $this->assertSame([(string) $this->product->id], $page->instance()->selectedTableRecords);
        $this->assertEquals(7200.5, $page->instance()->getTableRecords()->first()->export_net_price);
        $page->assertSee('data-compact-column-manager="true"', false);
        $table = $page->instance()->getTable();
        $this->assertSame(1, $table->getColumnManagerColumns());
        $this->assertSame(Width::Small, $table->getColumnManagerWidth());
        $this->assertArrayHasKey('x-on:partner-price-list-selection.window', $table->getExtraAttributes());
        $this->assertStringContainsString('if (! error) close()', $table->getColumnManagerApplyAction()->getAlpineClickHandler());
    }

    public function test_preview_refresh_keeps_table_search_and_sort(): void
    {
        $page = Livewire::test(PartnerPriceListExport::class)->fillForm($this->configuration)->call('loadProducts')
            ->searchTable($this->product->model_code)->sortTable('model_code', 'desc');
        $this->assertTrue($page->instance()->getTable()->persistsSearchInSession());
        $page->call('clearPreview')->assertSet('appliedFilters', null);
        $this->assertSame($this->product->model_code, $page->instance()->tableSearch);
        $sort = $page->instance()->tableSort;
        $page->call('loadProducts')->assertCanSeeTableRecords([$this->product]);
        $this->assertSame($this->product->model_code, $page->instance()->tableSearch);
        $this->assertSame($sort, $page->instance()->tableSort);
    }

    public function test_filters_are_combined_and_do_not_mix_seasons_or_inactive_products(): void
    {
        $other = $this->product->replicate();
        $other->model_code = 'OTHER';
        $other->brand_id = Brand::create(['code' => 'OTHER', 'name' => 'Másik', 'active' => true])->id;
        $other->save();
        $inactive = $this->product->replicate();
        $inactive->model_code = 'INACTIVE';
        $inactive->active = false;
        $inactive->save();
        $foreign = $this->product->replicate();
        $foreign->model_code = 'W26';
        $foreign->season_id = Season::create(['code' => 'W26', 'name' => 'Másik szezon', 'active' => true])->id;
        $foreign->save();
        $config = array_replace($this->configuration, ['brand_ids' => [$this->product->brand_id], 'item_main_group_ids' => [$this->product->item_main_group_id], 'catalog_groups' => ['Hátizsák']]);
        $page = Livewire::test(PartnerPriceListExport::class)->fillForm($config)->call('loadProducts')->assertCanSeeTableRecords([$this->product])->assertCanNotSeeTableRecords([$other, $inactive, $foreign]);
        $this->assertCount(1, $page->instance()->selectedTableRecords);
    }

    public function test_selection_buttons_work_and_empty_selection_does_not_export(): void
    {
        $page = Livewire::test(PartnerPriceListExport::class)->fillForm($this->configuration)->call('loadProducts')
            ->call('clearProductSelection')->call('exportPriceList')->assertHasErrors('selection');
        $page->call('selectAllProducts')->assertSet('selectedTableRecords', [(string) $this->product->id]);
        $page->call('exportPriceList')->assertFileDownloaded('partner-arlista-S27-HU.xlsx');
    }

    public function test_excel_is_numeric_date_typed_translated_and_does_not_change_prices(): void
    {
        $before = PriceListItem::all()->map->getAttributes()->all();
        $config = array_replace($this->configuration, ['language_id' => $this->en->id]);
        Translation::create(['entity' => 'item_main_group', 'entity_code' => 'BAG', 'field' => 'name', 'language_id' => $this->en->id, 'value' => 'Translated bags']);
        $doc = app(PartnerPriceListService::class)->document($config, [$this->product->id]);
        $this->assertSame('Őszi táska', $doc['sections'][0]['rows'][0]['name']);
        $this->assertSame('Translated bags', $doc['sections'][0]['rows'][0]['main_group']);
        $this->assertSame('Hátizsák', $doc['sections'][0]['rows'][0]['catalog_group']);
        $path = tempnam(sys_get_temp_dir(), 'partner-price-');
        $binder = Cell::getValueBinder();
        try {
            file_put_contents($path, Excel::raw(new PriceListWorkbook($doc), \Maatwebsite\Excel\Excel::XLSX));
            Cell::setValueBinder(new DefaultValueBinder);
            $book = IOFactory::load($path);
            $sheet = $book->getSheet(0);
            $this->assertSame(7200.5, $sheet->getCell('C7')->getValue());
            $this->assertEquals(15990, $sheet->getCell('D7')->getValue());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D7')->getDataType());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('E7')->getDataType());
            $this->assertSame('2027.04.01', $sheet->getCell('E7')->getFormattedValue());
            $this->assertSame('Expected arrival', $sheet->getCell('E5')->getValue());
            $this->assertSame('Catalogue page', $sheet->getCell('F5')->getValue());
            $this->assertSame(7, $sheet->getCell('F7')->getValue());
            $this->assertSame('Hátizsák', $sheet->getCell('A6')->getValue());
            $this->assertSame('F', $sheet->getHighestDataColumn());
            $this->assertSame('portrait', $sheet->getPageSetup()->getOrientation());
            $lastRow = $sheet->getHighestDataRow();
            $this->assertSame($doc['footer_lines'], array_map(fn ($row) => $sheet->getCell('A'.$row)->getValue(), range($lastRow - 2, $lastRow)));
            $this->assertSame('C6', $sheet->getFreezePane());
            $this->assertNull($sheet->getCell('A4')->getValue());
            $this->assertStringContainsString('&7&K8A919BWHOLE_HU / RETAIL_HU', $sheet->getHeaderFooter()->getOddFooter());
            $this->assertSame($sheet->getHeaderFooter()->getOddFooter(), $sheet->getHeaderFooter()->getEvenFooter());
        } finally {
            Cell::setValueBinder($binder);
            @unlink($path);
        }
        $this->assertSame($before, PriceListItem::all()->map->getAttributes()->all());
        $this->assertSame('hu', app()->getLocale());
    }

    public function test_pdf_download_uses_real_pdf_renderer_and_preserves_hungarian_unicode(): void
    {
        $page = Livewire::test(PartnerPriceListExport::class)->fillForm(array_replace($this->configuration, ['format' => 'pdf']))->call('loadProducts')
            ->call('exportPriceList')->assertFileDownloaded('partner-arlista-S27-HU.pdf');
        $pdf = base64_decode($page->effects['download']['content']);
        $this->assertStringStartsWith('%PDF-', $pdf);
        preg_match('/\/MediaBox\s*\[([\d.\s]+)\]/', $pdf, $matches);
        $size = preg_split('/\s+/', trim($matches[1]));
        $this->assertLessThan((float) $size[3], (float) $size[2]);
    }

    public function test_missing_prices_are_not_zero_and_other_season_prices_are_not_used(): void
    {
        PriceListItem::where('price_list_id', $this->net->id)->delete();
        $otherSeason = Season::create(['code' => 'S26', 'name' => 'Másik', 'active' => true]);
        PriceListItem::create(['price_list_id' => $this->net->id, 'season_id' => $otherSeason->id, 'product_id' => $this->product->id, 'net_price' => 999]);
        $doc = app(PartnerPriceListService::class)->document($this->configuration, [$this->product->id]);
        $this->assertSame(1, $doc['missing_prices']);
        $this->assertNull($doc['sections'][0]['rows'][0]['net_price']);
        $this->assertSame('-', (new PriceListWorkbook($doc))->sheets()[0]->array()[6][2]);
    }

    public function test_unauthorised_page_and_forged_direct_export_are_denied(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test(PartnerPriceListExport::class)->assertForbidden();
        $this->expectException(HttpException::class);
        app(PartnerPriceListService::class)->document($this->configuration, [$this->product->id]);
    }

    public function test_out_of_scope_ids_are_rejected(): void
    {
        $other = $this->product->replicate();
        $other->model_code = 'OTHER';
        $other->active = false;
        $other->save();
        $this->expectException(ValidationException::class);
        app(PartnerPriceListService::class)->document($this->configuration, [$other->id]);
    }

    public function test_cross_currency_retail_and_outlet_lists_keep_their_own_prices_and_currency(): void
    {
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euró', 'active' => true]);
        $retail = PriceList::create(['code' => 'RETAIL_EUR', 'name' => 'EUR kisker', 'type' => 'retail', 'currency_id' => $eur->id, 'active' => true]);
        $outlet = PriceList::create(['code' => 'OUTLET_EUR', 'name' => 'EUR outlet', 'type' => 'outlet', 'currency_id' => $eur->id, 'source_retail_price_list_id' => $retail->id, 'active' => true]);
        foreach ([$retail, $outlet] as $list) {
            PriceListItem::create(['price_list_id' => $list->id, 'season_id' => $this->season->id, 'product_id' => $this->product->id, 'net_price' => 29.95]);
            Livewire::test(PartnerPriceListExport::class)->fillForm($this->configuration)
                ->set('data.retail_price_list_id', $list->id)->call('loadProducts')->assertHasNoFormErrors();
            $doc = app(PartnerPriceListService::class)->document(array_replace($this->configuration, ['retail_price_list_id' => $list->id]), [$this->product->id]);
            $this->assertSame('HUF', $doc['currency']);
            $this->assertSame('EUR', $doc['retail_currency']);
            $this->assertEquals(29.95, $doc['sections'][0]['rows'][0]['retail_price']);
            $rows = (new PriceListWorkbook($doc))->sheets()[0]->array();
            $this->assertStringContainsString('EUR', $rows[4][3]);
            $html = view('exports.partner-price-list', ['document' => $doc])->render();
            $this->assertStringNotContainsString('price or information is not yet available', $html);
        }
    }

    public function test_default_selection_includes_every_page_and_subset_export_respects_deselection(): void
    {
        for ($index = 1; $index <= 104; $index++) {
            $copy = $this->product->replicate();
            $copy->model_code = sprintf('B%04d', $index);
            $copy->save();
        }
        $page = Livewire::test(PartnerPriceListExport::class)->fillForm($this->configuration)->call('loadProducts');
        $this->assertCount(105, $page->instance()->selectedTableRecords);
        $this->assertCount(100, $page->instance()->getTableRecords());
        $this->assertSame('selectedTableRecords', $page->instance()->getTable()->getCurrentSelectionLivewireProperty());
        $this->assertFalse($page->instance()->getTable()->canTrackDeselectedRecords());
        $page->call('selectAllProducts')->assertDispatched('partner-price-list-selection');
        $page->set('selectedTableRecords', [(string) $this->product->id])->call('exportPriceList')->assertFileDownloaded();
        $this->assertSame([$this->product->id], $page->instance()->getSelectedTableRecords()->modelKeys());
    }

    public function test_changing_configuration_invalidates_preview_and_selection(): void
    {
        Livewire::test(PartnerPriceListExport::class)->fillForm($this->configuration)->call('loadProducts')
            ->set('data.format', 'pdf')->assertSet('appliedFilters', null)->assertSet('selectedTableRecords', [])
            ->call('exportPriceList')->assertHasErrors('selection');
    }

    public function test_read_only_role_can_export_but_loses_access_when_price_read_permission_is_revoked(): void
    {
        $user = User::factory()->create();
        $permissions = ['View:PartnerPriceListExport', 'ViewAny:Product', 'View:Product', 'ViewAny:PriceList', 'View:PriceList', 'ViewAny:PriceListItem', 'View:PriceListItem'];
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);
        $page = Livewire::test(PartnerPriceListExport::class)->assertSuccessful()->fillForm($this->configuration)->call('loadProducts');
        $page->call('exportPriceList')->assertFileDownloaded();
        $user->revokePermissionTo('View:PriceListItem');
        $page->call('exportPriceList')->assertForbidden();
    }

    public function test_model_read_permissions_without_new_page_permission_do_not_grant_page_access(): void
    {
        $user = User::factory()->create();
        foreach (['ViewAny:Product', 'View:Product', 'ViewAny:PriceList', 'View:PriceList', 'ViewAny:PriceListItem', 'View:PriceListItem'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);
        Livewire::test(PartnerPriceListExport::class)->assertForbidden();
    }

    public function test_revoking_page_permission_also_blocks_further_table_requests(): void
    {
        $user = User::factory()->create();
        foreach (['View:PartnerPriceListExport', 'ViewAny:Product', 'View:Product', 'ViewAny:PriceList', 'View:PriceList', 'ViewAny:PriceListItem', 'View:PriceListItem'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);
        $page = Livewire::test(PartnerPriceListExport::class)->fillForm($this->configuration)->call('loadProducts');
        $user->revokePermissionTo('View:PartnerPriceListExport');
        $page->set('tableSearch', 'B13')->assertForbidden();
    }

    public function test_download_rechecks_products_and_reads_latest_saved_prices(): void
    {
        $page = Livewire::test(PartnerPriceListExport::class)->fillForm($this->configuration)->call('loadProducts');
        PriceListItem::where('price_list_id', $this->net->id)->update(['net_price' => 8123.45]);
        $doc = app(PartnerPriceListService::class)->document($this->configuration, [$this->product->id]);
        $this->assertSame(8123.45, $doc['sections'][0]['rows'][0]['net_price']);
        $this->product->update(['active' => false]);
        $page->call('exportPriceList')->assertHasErrors('selection')->assertNoFileDownloaded();
    }

    public function test_excel_sheet_titles_are_safe_and_unique_even_after_truncation(): void
    {
        $doc = app(PartnerPriceListService::class)->document($this->configuration, [$this->product->id]);
        $first = $doc['sections'][0];
        $first['name'] = "'".str_repeat('Hosszú / név:*[]?', 6)."'";
        $doc['sections'] = [$first, $first];
        $sheets = (new PriceListWorkbook($doc))->sheets();
        $this->assertNotSame($sheets[0]->title(), $sheets[1]->title());
        foreach ($sheets as $sheet) {
            $this->assertLessThanOrEqual(31, mb_strlen($sheet->title()));
            $this->assertDoesNotMatchRegularExpression('/[\\\\\/?*\[\]:]/u', $sheet->title());
            $this->assertStringStartsNotWith("'", $sheet->title());
        }
    }

    public function test_zero_is_exported_as_numeric_and_formula_like_text_remains_text_without_retail_column(): void
    {
        $this->product->update(['name_hu' => '=1+1']);
        PriceListItem::where('price_list_id', $this->net->id)->update(['net_price' => 0]);
        $doc = app(PartnerPriceListService::class)->document(array_replace($this->configuration, ['retail_price_list_id' => null]), [$this->product->id]);
        $sheetExport = (new PriceListWorkbook($doc))->sheets()[0];
        $this->assertSame(0.0, $sheetExport->array()[6][2]);
        $this->assertCount(5, $sheetExport->array()[4]);
        $this->assertSame(0, $doc['missing_prices']);
        $sheet = new Spreadsheet;
        $sheetExport->bindValue($sheet->getActiveSheet()->getCell('A1'), '=1+1');
        $this->assertSame(DataType::TYPE_STRING, $sheet->getActiveSheet()->getCell('A1')->getDataType());
    }

    public function test_distributor_price_list_uses_its_saved_price_and_caption(): void
    {
        $this->net->update(['type' => 'distributor']);
        $doc = app(PartnerPriceListService::class)->document($this->configuration, [$this->product->id]);
        $this->assertSame('Nettó disztribútori ár', $doc['net_label']);
        $this->assertSame(7200.5, $doc['sections'][0]['rows'][0]['net_price']);
    }

    public function test_invalid_selection_shape_is_rejected_without_php_conversion_warning(): void
    {
        $this->expectException(ValidationException::class);
        app(PartnerPriceListService::class)->document($this->configuration, [['invalid']]);
    }

    public function test_footer_uses_exact_selected_season_usd_rate_and_translates_without_rounding_to_integer(): void
    {
        ExchangeRate::query()->update(['rate_to_huf' => 381.275]);
        $otherSeason = Season::create(['code' => 'S26', 'name' => 'Korábbi', 'active' => true]);
        ExchangeRate::create(['season_id' => $otherSeason->id, 'currency_id' => Currency::where('code', 'USD')->value('id'), 'rate_to_huf' => 370, 'active' => true]);
        $service = app(PartnerPriceListService::class);
        $hu = $service->document($this->configuration, [$this->product->id]);
        $this->assertSame(381.275, $hu['usd_huf_rate']);
        $this->assertSame(['www.heavytools.com, info@heavytools.com', 'Az árváltozás jogát fenntartjuk.', 'A megrendelési árakat 381,275 HUF/USD árfolyamszintig garantáljuk.'], $hu['footer_lines']);
        $en = $service->document(array_replace($this->configuration, ['language_id' => $this->en->id]), [$this->product->id]);
        $this->assertStringContainsString('381.275 HUF/USD', $en['footer_lines'][2]);
        $this->assertStringContainsString('We reserve the right', $en['footer_lines'][1]);
    }

    public function test_missing_inactive_and_zero_usd_rate_do_not_emit_an_unfounded_price_guarantee(): void
    {
        $page = Livewire::test(PartnerPriceListExport::class)->fillForm($this->configuration)->call('loadProducts');
        foreach ([['active' => false, 'rate_to_huf' => 380], ['active' => true, 'rate_to_huf' => 0]] as $invalid) {
            ExchangeRate::query()->update($invalid);
            $page->call('exportPriceList')->assertHasErrors('data.season_id')->assertNoFileDownloaded();
        }
        ExchangeRate::query()->delete();
        $page->call('exportPriceList')->assertHasErrors('data.season_id')->assertNoFileDownloaded();
        $page->assertSet('settingsOpen', true);
    }

    public function test_catalogue_group_is_a_single_heading_not_a_repeated_product_column(): void
    {
        $ids = [$this->product->id];
        foreach (['Másik csoport', 'Hátizsák'] as $index => $group) {
            $copy = $this->product->replicate();
            $copy->model_code = 'MODEL'.$index;
            $copy->catalog_group_name_hu = $group;
            $copy->catalog_sort = $index + 1;
            $copy->save();
            $ids[] = $copy->id;
        }
        $doc = app(PartnerPriceListService::class)->document($this->configuration, $ids);
        $this->assertSame([$ids[0], $ids[2], $ids[1]], array_column($doc['sections'][0]['rows'], 'id'));
        $rows = (new PriceListWorkbook($doc))->sheets()[0]->array();
        $this->assertCount(6, $rows[4]);
        $this->assertSame(1, count(array_filter($rows, fn ($row) => $row === ['Hátizsák'])));
        $this->assertSame(1, count(array_filter($rows, fn ($row) => $row === ['Másik csoport'])));
        $html = view('exports.partner-price-list', ['document' => $doc])->render();
        $this->assertStringNotContainsString('<th style="width:18%">', $html);
        $this->assertStringContainsString('colspan="6">Hátizsák', $html);
        $this->assertStringContainsString('380 HUF/USD', $html);
    }

    public function test_english_pdf_uses_comma_prices_without_exposing_price_codes_in_the_body(): void
    {
        $doc = app(PartnerPriceListService::class)->document(array_replace($this->configuration, ['language_id' => $this->en->id]), [$this->product->id]);
        $html = view('exports.partner-price-list', ['document' => $doc])->render();
        $this->assertStringContainsString('7 200,50', $html);
        $this->assertStringContainsString('15 990,00', $html);
        $this->assertStringNotContainsString('7 200.50', $html);
        $this->assertStringNotContainsString('WHOLE_HU', $html);
        $this->assertStringNotContainsString('RETAIL_HU', $html);
    }

    public function test_multiple_brands_main_groups_and_catalogue_groups_match_with_or_inside_each_filter(): void
    {
        $brand = Brand::create(['code' => 'SECOND', 'name' => 'Másik márka', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'SECOND', 'name' => 'Másik főcsoport', 'active' => true]);
        $other = $this->product->replicate();
        $other->model_code = 'SECOND';
        $other->brand_id = $brand->id;
        $other->item_main_group_id = $group->id;
        $other->catalog_group_name_hu = 'Másik katalóguscsoport';
        $other->save();
        $config = array_replace($this->configuration, ['brand_ids' => [$this->product->brand_id, $brand->id],
            'item_main_group_ids' => [$this->product->item_main_group_id, $group->id], 'catalog_groups' => ['Hátizsák', 'Másik katalóguscsoport']]);
        $page = Livewire::test(PartnerPriceListExport::class)->fillForm($config)->call('loadProducts')
            ->assertHasNoFormErrors()->assertCanSeeTableRecords([$this->product, $other]);
        $this->assertCount(2, $page->instance()->selectedTableRecords);
        $this->assertSame($config['brand_ids'], $page->instance()->appliedFilters['brand_ids']);
        $this->assertSame($config['item_main_group_ids'], $page->instance()->appliedFilters['item_main_group_ids']);
        $this->assertSame($config['catalog_groups'], $page->instance()->appliedFilters['catalog_groups']);
    }

    public function test_product_filters_are_compact_searchable_multi_selects_without_changing_single_value_export_settings(): void
    {
        $page = Livewire::test(PartnerPriceListExport::class)->assertSuccessful();
        foreach (['brand_ids', 'item_main_group_ids', 'catalog_groups'] as $name) {
            $page->assertFormFieldExists($name, fn ($field) => $field instanceof Select
                && $field->isMultiple() && $field->isSearchable() && $field->isPreloaded()
                && $field->getPlaceholder() === 'Mindegyik');
        }
        foreach (['season_id', 'price_list_id', 'retail_price_list_id', 'language_id', 'format'] as $name) {
            $page->assertFormFieldExists($name, fn ($field) => $field instanceof Select && ! $field->isMultiple());
        }
    }

    public function test_settings_collapse_after_loading_and_toggling_preserves_preview_selection_and_table_state(): void
    {
        $page = Livewire::test(PartnerPriceListExport::class)->assertSet('settingsOpen', true)
            ->fillForm($this->configuration)->call('loadProducts')->assertSet('settingsOpen', false)
            ->set('tableSearch', 'B13')->sortTable('model_code', 'desc');
        $filters = $page->instance()->appliedFilters;
        $page->set('settingsOpen', true)->set('settingsOpen', false)
            ->assertSet('appliedFilters', $filters)
            ->assertSet('selectedTableRecords', [(string) $this->product->id])
            ->assertSet('tableSearch', 'B13')->assertSet('tableSort', 'model_code:desc')
            ->assertCanSeeTableRecords([$this->product]);
        $page->set('data.format', 'pdf')->assertSet('settingsOpen', true)
            ->assertSet('appliedFilters', null)->assertSet('selectedTableRecords', [])
            ->call('loadProducts')->assertHasNoFormErrors()->assertSet('settingsOpen', false);
    }

    public function test_invalid_configuration_keeps_settings_open_and_does_not_select_products(): void
    {
        Livewire::test(PartnerPriceListExport::class)->set('settingsOpen', false)
            ->call('loadProducts')->assertHasErrors(['data.season_id', 'data.price_list_id'])
            ->assertSet('settingsOpen', true)->assertSet('appliedFilters', null)
            ->assertSet('selectedTableRecords', []);
    }

    public function test_collapsed_summary_describes_applied_configuration_and_escapes_labels(): void
    {
        $this->net->update(['code' => '<b>WHOLE</b>']);
        $page = Livewire::test(PartnerPriceListExport::class)->fillForm(array_replace($this->configuration, [
            'brand_ids' => [$this->product->brand_id], 'catalog_groups' => ['Hátizsák'],
        ]))->call('loadProducts');
        $this->assertSame([
            'Szezon' => 'S27', 'Nettó' => '<b>WHOLE</b> (HUF)', 'Kisker / outlet' => 'RETAIL_HU (HUF)',
            'Fájl' => 'HU / XLSX', 'Márka' => 1, 'Főcsoport' => 'Mindegyik', 'Katalóguscsoport' => 1,
        ], $page->instance()->appliedSummary);
        $page->assertSee('&lt;b&gt;WHOLE&lt;/b&gt; (HUF)', false)
            ->assertDontSee('<strong><b>WHOLE</b>', false)
            ->assertSee('x-show="settingsOpen"', false)
            ->assertSee('aria-controls="partner-price-list-settings-form"', false);
        $page->set('data.retail_price_list_id', null)->call('loadProducts');
        $this->assertSame('Nincs', $page->instance()->appliedSummary['Kisker / outlet']);
    }

    public function test_adding_filter_values_keeps_valid_catalogue_selections_and_removes_only_invalid_ones(): void
    {
        $brand = Brand::create(['code' => 'SECOND', 'name' => 'Másik márka', 'active' => true]);
        $other = $this->product->replicate();
        $other->model_code = 'SECOND';
        $other->brand_id = $brand->id;
        $other->catalog_group_name_hu = 'Másik katalóguscsoport';
        $other->save();
        $page = Livewire::test(PartnerPriceListExport::class)
            ->fillForm(array_replace($this->configuration, ['brand_ids' => [$this->product->brand_id], 'catalog_groups' => ['Hátizsák']]))
            ->call('loadProducts')->set('data.brand_ids', [$this->product->brand_id, $brand->id])
            ->assertSet('data.catalog_groups', ['Hátizsák'])->assertSet('appliedFilters', null);
        $page->set('data.catalog_groups', ['Hátizsák', 'Másik katalóguscsoport'])
            ->set('data.brand_ids', [$brand->id])->assertSet('data.catalog_groups', ['Másik katalóguscsoport']);
        $page->call('loadProducts')->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$this->product]);
    }
}

<?php

namespace Tests\Feature\Partner;

use App\Exports\ErpOrderExport;
use App\Exports\ErpOrderSheet;
use App\Livewire\Partner\SalesRepOrderSummary;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Currency;
use App\Models\ItemAssortment;
use App\Models\ItemMainGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSheetType;
use App\Models\OrderType;
use App\Models\Partner;
use App\Models\PartnerAddress;
use App\Models\PartnerUser;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Season;
use App\Models\Size;
use App\Models\Sku;
use App\Models\SkuReplacement;
use App\Services\Partner\ErpOrderExportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Tests\TestCase;

class ErpOrderExportTest extends TestCase
{
    use RefreshDatabase;

    private Season $season;

    private PartnerUser $user;

    private Order $order;

    private Order $otherOrder;

    private Order $deniedOrder;

    private array $skus;

    private array $input = ['erpExportNote' => 'Teszt megjegyzés', 'erpExportDeliveryDate' => '2027.02.01'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        session(['partner.locale' => 'hu']);
        $this->travelTo(CarbonImmutable::parse('2026-11-16 23:30:00', 'UTC'));
        $this->season = Season::create(['code' => 'W27', 'name' => 'Tél', 'visible_to_partners' => true]);
        session(['partner.season_id' => $this->season->id]);
        $brand = Brand::create(['code' => 'HT', 'name' => 'Heavy Tools']);
        $sheet = OrderSheetType::create(['code' => 'BAG', 'name' => 'Táska']);
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint']);
        $list = PriceList::create(['code' => 'WHOLE', 'name' => 'Nagyker', 'type' => 'wholesale', 'currency_id' => $currency->id]);
        $group = ItemMainGroup::create(['code' => 'B01', 'name' => 'Táska']);
        $product = Product::create(['season_id' => $this->season->id, 'item_main_group_id' => $group->id,
            'model_code' => 'B01W27', 'name_hu' => 'MODELL', 'brand_id' => $brand->id, 'order_sheet_type_id' => $sheet->id]);
        $sizes = [];
        foreach (['XL' => 1, 'M' => 2] as $code => $sort) {
            $sizes[$code] = Size::create(['code' => $code, 'sort_order' => $sort]);
        }
        foreach (['black' => '01', 'navy' => '02', 'red' => '03'] as $name => $code) {
            $color = Color::create(['product_id' => $product->id, 'code' => $code, 'name_hu' => strtoupper($name)]);
            foreach ($sizes as $sizeCode => $size) {
                $this->skus[$name.'-'.$sizeCode] = Sku::create(['product_id' => $product->id, 'color_id' => $color->id,
                    'size_id' => $size->id, 'sku_code' => '001-'.$code.'-'.$sizeCode,
                    'sku_name' => 'MODELL '.strtoupper($name).' '.$sizeCode, 'type' => 'piece']);
            }
        }
        $this->skus['pack'] = Sku::create(['product_id' => $product->id, 'color_id' => $this->skus['black-M']->color_id,
            'sku_code' => '001-PACK', 'sku_name' => 'MODELL BLACK PACK', 'type' => 'assortment']);
        ItemAssortment::create(['product_id' => $product->id, 'color_id' => $this->skus['black-M']->color_id,
            'assortment_sku_id' => $this->skus['pack']->id, 'component_sku_id' => $this->skus['black-M']->id, 'quantity' => 6]);
        $orders = [];
        $partners = [];
        foreach (['000021', '000001', 'DENIED'] as $index => $code) {
            $partners[] = $partner = Partner::create(['erp_partner_code' => $code, 'name' => 'Partner '.$code]);
            $address = PartnerAddress::create(['partner_id' => $partner->id, 'addrid' => '0011353'.$index, 'name' => 'Bolt '.$code]);
            $orders[] = Order::create(['partner_id' => $partner->id, 'partner_address_id' => $address->id,
                'season_id' => $this->season->id, 'brand_id' => $brand->id, 'order_sheet_type_id' => $sheet->id,
                'order_type_id' => OrderType::where('code', 'VRELO')->firstOrFail()->id, 'reference_number' => 'REF-00'.$index,
                'price_list_id' => $list->id, 'currency_id' => $currency->id, 'status' => 'submitted']);
        }
        [$this->order, $this->otherOrder, $this->deniedOrder] = $orders;
        $this->user = PartnerUser::create(['partner_id' => $partners[0]->id, 'role' => 'sales_rep',
            'name' => 'Képviselő', 'email' => 'erp-export@example.test', 'password' => 'password', 'active' => true]);
        $this->user->partners()->attach([$partners[0]->id, $partners[1]->id]);
        $this->actingAs($this->user, 'partner');
    }

    private function item(string $sku, int $quantity, ?Order $order = null): void
    {
        OrderItem::create(['order_id' => ($order ?? $this->order)->id, 'sku_id' => $this->skus[$sku]->id,
            'quantity' => $quantity, 'unit_price' => 100, 'line_total' => $quantity * 100]);
    }

    private function replacement(string $old, string $new): SkuReplacement
    {
        return SkuReplacement::create(['season_id' => $this->season->id, 'old_sku_code' => $this->skus[$old]->sku_code,
            'new_sku_code' => $new === 'TÖRÖLT' ? $new : $this->skus[$new]->sku_code]);
    }

    private function export(?array $ids = null, array $input = []): ErpOrderExport
    {
        return app(ErpOrderExportService::class)->build($ids ?? [$this->order->id], array_replace($this->input, $input));
    }

    public function test_exact_template_mapping_preserves_sku_codes_and_intentionally_empty_columns(): void
    {
        $this->item('black-M', 3);
        $export = $this->export();
        $row = $export->rows[0];
        $this->assertCount(23, $row);
        $this->assertCount(23, (new ErpOrderSheet([]))->headings());
        $this->assertSame(['HUPS', 'VRELO'], array_slice($row, 0, 2));
        $this->assertSame('2026-11-17', $row[2]->format('Y-m-d')); // Budapest day, not UTC day.
        $this->assertSame(['000021', '00113530', 'Bolt 000021'], array_slice($row, 3, 3));
        $this->assertSame(['REF-000', 'Teszt megjegyzés', $this->skus['black-M']->sku_code, 'MODELL BLACK M'], array_slice($row, 10, 4));
        $this->assertSame(3, $row[18]);
        $this->assertSame('2027-02-01', $row[22]->format('Y-m-d'));
        foreach ([6, 7, 8, 9, 14, 15, 16, 17, 19, 20, 21] as $column) {
            $this->assertNull($row[$column]);
        }
    }

    public function test_substitution_deletion_and_target_aggregation_reconcile_without_changing_orders(): void
    {
        foreach (['black-M' => 3, 'navy-M' => 2, 'black-XL' => 4, 'red-M' => 5] as $sku => $quantity) {
            $this->item($sku, $quantity);
        }
        $this->replacement('black-M', 'navy-M');
        $this->replacement('black-XL', 'TÖRÖLT');
        $before = DB::table('order_items')->orderBy('id')->get()->toJson();
        $orderBefore = $this->order->fresh()->getAttributes();
        $export = $this->export();
        $this->assertCount(2, $export->rows);
        $quantities = collect($export->rows)->mapWithKeys(fn ($row) => [$row[12] => $row[18]])->all();
        $this->assertSame([$this->skus['navy-M']->sku_code => 5, $this->skus['red-M']->sku_code => 5], $quantities);
        $this->assertSame([7, 3, 4, null, 14, null], array_slice($export->auditRows[0], 8));
        $this->assertSame($before, DB::table('order_items')->orderBy('id')->get()->toJson());
        $this->assertSame($orderBefore, $this->order->fresh()->getAttributes());
    }

    public function test_assortments_are_not_expanded_and_component_replacements_do_not_affect_them(): void
    {
        $this->item('pack', 2);
        $this->replacement('black-M', 'TÖRÖLT');
        $export = $this->export();
        $this->assertSame('001-PACK', $export->rows[0][12]);
        $this->assertSame(2, $export->rows[0][18]);
        $this->assertSame([2, 0, 0, null, 2, null], array_slice($export->auditRows[0], 8));
        $this->replacement('pack', 'TÖRÖLT');
        $export = $this->export();
        $this->assertSame([], $export->rows);
        $this->assertSame([0, 0, 2, null, 2, null], array_slice($export->auditRows[0], 8));
    }

    public function test_rows_use_partner_product_color_and_numeric_size_order_and_keep_orders_separate(): void
    {
        $this->item('black-M', 1);
        $this->item('black-XL', 2);
        $this->item('navy-XL', 3);
        $this->item('red-M', 4, $this->otherOrder);
        $copy = $this->order->replicate()->fill(['reference_number' => 'REF-999']);
        $copy->save();
        $this->item('black-M', 7, $copy);
        $export = $this->export([$copy->id, $this->order->id, $this->otherOrder->id]);
        $this->assertSame(['000001', '000021', '000021', '000021', '000021'], array_column($export->rows, 3));
        $this->assertSame([$this->skus['red-M']->sku_code, $this->skus['black-XL']->sku_code, $this->skus['black-M']->sku_code,
            $this->skus['navy-XL']->sku_code, $this->skus['black-M']->sku_code], array_column($export->rows, 12));
        $this->assertSame([4, 2, 1, 3, 7], array_column($export->rows, 18));
        $this->assertCount(3, $export->auditRows);
    }

    public function test_replacement_chains_are_resolved_and_chains_ending_in_deletion_are_counted_once(): void
    {
        $this->item('black-M', 3);
        $this->replacement('black-M', 'navy-M');
        $this->replacement('navy-M', 'red-M');
        $export = $this->export();
        $this->assertSame($this->skus['red-M']->sku_code, $export->rows[0][12]);
        $this->assertSame([0, 3, 0], array_slice($export->auditRows[0], 8, 3));
        $this->replacement('red-M', 'TÖRÖLT');
        $export = $this->export();
        $this->assertSame([], $export->rows);
        $this->assertSame([0, 0, 3], array_slice($export->auditRows[0], 8, 3));
    }

    public function test_sorting_uses_the_replacement_products_code_not_the_original_sku_code(): void
    {
        $product = $this->skus['black-M']->product->replicate()->fill(['model_code' => 'A00W27']);
        $product->save();
        $color = Color::create(['product_id' => $product->id, 'code' => '99', 'name_hu' => 'NAVY']);
        $target = $this->skus['black-M']->replicate()->fill(['product_id' => $product->id,
            'color_id' => $color->id, 'sku_code' => 'ZZZ-TARGET']);
        $target->save();
        SkuReplacement::create(['season_id' => $this->season->id, 'old_sku_code' => $this->skus['black-M']->sku_code,
            'new_sku_code' => 'ZZZ-TARGET']);
        $this->item('black-XL', 1);
        $this->item('black-M', 2);
        $this->assertSame(['ZZZ-TARGET', $this->skus['black-XL']->sku_code], array_column($this->export()->rows, 12));
    }

    public function test_other_seasons_rules_do_not_change_selected_orders(): void
    {
        $other = Season::create(['code' => 'W28', 'name' => 'Másik szezon']);
        DB::table('sku_replacements')->insert(['season_id' => $other->id,
            'old_sku_code' => $this->skus['black-M']->sku_code, 'new_sku_code' => 'TÖRÖLT']);
        $this->item('black-M', 3);
        $this->assertSame($this->skus['black-M']->sku_code, $this->export()->rows[0][12]);
    }

    public function test_export_reads_updated_quantities_after_the_modal_was_opened(): void
    {
        Excel::fake();
        $this->item('black-M', 3);
        $page = Livewire::test(SalesRepOrderSummary::class)->set('selectedSummaryOrderIds', [(string) $this->order->id])
            ->call('openErpExport');
        OrderItem::where('order_id', $this->order->id)->update(['quantity' => 9]);
        $page->set('erpExportDeliveryDate', '2027-02-01')->call('exportErpOrders')->assertHasNoErrors();
        Excel::assertDownloaded('ERP-rendelesek-'.now('Europe/Budapest')->format('Ymd-His').'.xlsx', function (ErpOrderExport $export): bool {
            $this->assertSame(9, $export->rows[0][18]);
            $this->assertSame(9, $export->auditRows[0][12]);

            return true;
        });
    }

    public function test_hidden_seasons_and_disabled_users_cannot_be_exported(): void
    {
        $this->season->update(['visible_to_partners' => false]);
        Livewire::test(SalesRepOrderSummary::class)->assertNotFound();
        $this->season->update(['visible_to_partners' => true]);
        $page = Livewire::test(SalesRepOrderSummary::class)->set('selectedSummaryOrderIds', [(string) $this->order->id])
            ->call('openErpExport');
        $this->user->update(['active' => false]);
        $page->set('erpExportDeliveryDate', '2027-02-01')->call('exportErpOrders')->assertForbidden();
    }

    public function test_tampered_circular_rules_are_rejected_during_export(): void
    {
        $this->item('black-M', 1);
        DB::table('sku_replacements')->insert([
            ['season_id' => $this->season->id, 'old_sku_code' => $this->skus['black-M']->sku_code, 'new_sku_code' => $this->skus['navy-M']->sku_code],
            ['season_id' => $this->season->id, 'old_sku_code' => $this->skus['navy-M']->sku_code, 'new_sku_code' => $this->skus['black-M']->sku_code],
        ]);
        $this->expectException(ValidationException::class);
        $this->export();
    }

    public function test_unknown_replacement_target_does_not_disappear_silently(): void
    {
        $this->item('black-M', 1);
        DB::table('sku_replacements')->insert(['season_id' => $this->season->id,
            'old_sku_code' => $this->skus['black-M']->sku_code, 'new_sku_code' => 'MISSING']);
        $this->expectException(ValidationException::class);
        $this->export();
    }

    public function test_only_sales_reps_can_open_or_call_the_export_and_access_is_rechecked(): void
    {
        foreach (['partner_admin', 'address_user'] as $role) {
            $this->user->update(['role' => $role]);
            Livewire::test(SalesRepOrderSummary::class)->call('loadSummary')->assertDontSee('Kijelölt rendelések ERP-exportja')
                ->set('selectedSummaryOrderIds', [(string) $this->order->id])->call('openErpExport')->assertForbidden();
            Livewire::test(SalesRepOrderSummary::class)->call('exportErpOrders')->assertForbidden();
        }
        $this->user->update(['role' => 'sales_rep']);
        $page = Livewire::test(SalesRepOrderSummary::class)->set('selectedSummaryOrderIds', [(string) $this->order->id])
            ->call('openErpExport')->assertSet('showErpExportModal', true);
        $this->user->partners()->detach();
        $page->set('erpExportDeliveryDate', '2027-02-01')->call('exportErpOrders')->assertForbidden();
    }

    public function test_mixed_authorized_and_unauthorized_selection_fails_instead_of_partial_download(): void
    {
        $this->item('black-M', 3);
        Livewire::test(SalesRepOrderSummary::class)
            ->set('selectedSummaryOrderIds', [(string) $this->order->id, (string) $this->deniedOrder->id])
            ->call('openErpExport')->assertForbidden();
    }

    public function test_modal_validates_note_and_date_and_exports_only_the_frozen_selection(): void
    {
        Excel::fake();
        $this->item('black-M', 3);
        $this->item('navy-M', 5, $this->otherOrder);
        $page = Livewire::test(SalesRepOrderSummary::class)->call('openErpExport')
            ->assertHasErrors(['selectedSummaryOrderIds']);
        $page->set('selectedSummaryOrderIds', [(string) $this->order->id])->call('openErpExport')
            ->set('erpExportDeliveryDate', '2027-02-30')->call('exportErpOrders')->assertHasErrors(['erpExportDeliveryDate']);
        $page->set('erpExportDeliveryDate', '2027-02-01')->set('erpExportNote', str_repeat('á', 251))
            ->call('exportErpOrders')->assertHasErrors(['erpExportNote']);
        $page->set('erpExportNote', str_repeat('á', 250))->set('selectedSummaryOrderIds', [(string) $this->otherOrder->id])
            ->call('exportErpOrders')->assertHasNoErrors()->assertSet('showErpExportModal', false);
        Excel::assertDownloaded('ERP-rendelesek-'.now('Europe/Budapest')->format('Ymd-His').'.xlsx', function (ErpOrderExport $export): bool {
            $this->assertSame(3, $export->rows[0][18]);
            $this->assertCount(1, $export->auditRows);
            $this->assertSame($this->order->id, $export->auditRows[0][0]);

            return true;
        });
    }

    public function test_cancel_changes_nothing_and_empty_orders_are_reconciled_as_zero(): void
    {
        $page = Livewire::test(SalesRepOrderSummary::class)->set('selectedSummaryOrderIds', [(string) $this->order->id])
            ->call('openErpExport')->call('closeErpExport')->assertSet('showErpExportModal', false)->assertSet('erpExportOrderIds', []);
        $export = $this->export();
        $this->assertSame([], $export->rows);
        $this->assertSame([0, 0, 0, null, 0, null], array_slice($export->auditRows[0], 8));
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_actual_xlsx_has_two_sheets_safe_text_numeric_dates_and_reconciled_formulas(): void
    {
        $this->item('black-M', 3);
        $this->item('black-XL', 2);
        $this->replacement('black-M', 'navy-M');
        $this->replacement('black-XL', 'TÖRÖLT');
        $path = tempnam(sys_get_temp_dir(), 'erp-order-export-');
        $previousBinder = Cell::getValueBinder();
        try {
            file_put_contents($path, Excel::raw($this->export(input: ['erpExportNote' => '=1+1']), ExcelFormat::XLSX));
            // The XLSX reader must not reuse the export's identifier/formula binder.
            Cell::setValueBinder(new DefaultValueBinder);
            $book = IOFactory::load($path);
            $this->assertSame(['Munka1', 'Ellenőrzés'], $book->getSheetNames());
            $sheet = $book->getSheet(0);
            $this->assertSame('W', $sheet->getHighestColumn());
            $this->assertSame('000021', $sheet->getCell('D2')->getValue());
            $this->assertSame('000021', $sheet->getCell('D2')->getFormattedValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('D2')->getDataType());
            $this->assertSame('00113530', $sheet->getCell('E2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('M2')->getDataType());
            $this->assertSame('=1+1', $sheet->getCell('L2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('L2')->getDataType());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('S2')->getDataType());
            $this->assertEquals(3, $sheet->getCell('S2')->getValue());
            $this->assertSame('2026-11-17', Date::excelToDateTimeObject($sheet->getCell('C2')->getValue())->format('Y-m-d'));
            $this->assertSame('2027-02-01', Date::excelToDateTimeObject($sheet->getCell('W2')->getValue())->format('Y-m-d'));
            $this->assertSame('yyyy.mm.dd', $sheet->getStyle('C2')->getNumberFormat()->getFormatCode());
            $this->assertSame('2026.11.17', $sheet->getCell('C2')->getFormattedValue());
            $this->assertSame('2027.02.01', $sheet->getCell('W2')->getFormattedValue());
            $audit = $book->getSheet(1);
            $this->assertEquals(5, $audit->getCell('L2')->getOldCalculatedValue());
            $this->assertEquals(5, $audit->getCell('L2')->getCalculatedValue());
            $this->assertEquals(5, $audit->getCell('M2')->getValue());
            $this->assertEquals(0, $audit->getCell('N2')->getCalculatedValue());
            $this->assertEquals(5, $audit->getCell('L3')->getCalculatedValue());
            $this->assertEquals(5, $audit->getCell('L3')->getOldCalculatedValue());
            $this->assertEquals(0, $audit->getCell('N3')->getCalculatedValue());
            $book->disconnectWorksheets();
        } finally {
            Cell::setValueBinder($previousBinder);
            unlink($path);
        }
    }
}

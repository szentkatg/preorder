<?php

namespace Tests\Feature\Manual;

use App\Livewire\Partner\Auth\ForgotPassword;
use App\Livewire\Partner\Auth\Login;
use App\Livewire\Partner\CatalogGroupOrder;
use App\Livewire\Partner\OrderBulkSubmission;
use App\Livewire\Partner\OrderOverview;
use App\Livewire\Partner\OrderSelector;
use App\Livewire\Partner\OrderSummary;
use App\Livewire\Partner\PartnerOrderCoverage;
use App\Livewire\Partner\SalesRepOrderSummary;
use App\Livewire\Partner\SharedCatalogGroupOrder;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Currency;
use App\Models\ItemAssortment;
use App\Models\ItemMainGroup;
use App\Models\Language;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShareLink;
use App\Models\OrderSheetType;
use App\Models\OrderType;
use App\Models\Partner;
use App\Models\PartnerAddress;
use App\Models\PartnerUser;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Season;
use App\Models\Size;
use App\Models\SizeRange;
use App\Models\SizeRangeItem;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class PartnerGuideScreenshotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_current_portal_with_demo_data_only(): void
    {
        if (getenv('BUILD_PARTNER_GUIDE_DEMO') !== '1') {
            $this->markTestSkipped('Set BUILD_PARTNER_GUIDE_DEMO=1 to generate local, in-memory demo HTML.');
        }
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.url' => 'https://rendeles.szeganet.hu', 'app.locale' => 'hu']);
        URL::forceRootUrl('https://rendeles.szeganet.hu');
        URL::forceScheme('https');
        $hu = Language::create(['code' => 'HU', 'name' => 'Magyar', 'active' => true]);
        Language::create(['code' => 'EN', 'name' => 'English', 'active' => true]);
        session(['partner.locale' => 'hu', 'partner.language_id' => $hu->id]);
        $ui = Language::where('code', strtoupper(getenv('GUIDE_LOCALE') ?: 'HU'))->firstOrFail();
        session(['partner.locale' => strtolower($ui->code), 'partner.language_id' => $ui->id]);
        $season = Season::create(['code' => 'S27', 'name' => '2027 Spring/Summer', 'active' => true, 'visible_to_partners' => true]);
        Season::create(['code' => 'W27', 'name' => '2027 Autumn/Winter', 'active' => true, 'visible_to_partners' => true]);
        $huf = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'symbol' => 'Ft', 'active' => true]);
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'active' => true]);
        $retail = PriceList::create(['code' => 'Retail_HU', 'name' => 'Magyar kisker', 'type' => 'retail', 'currency_id' => $huf->id, 'active' => true]);
        $whole = PriceList::create(['code' => 'Wholesale_HU', 'name' => 'Magyar nagyker', 'type' => 'wholesale', 'currency_id' => $huf->id, 'retail_price_list_id' => $retail->id, 'active' => true]);
        $retailEur = PriceList::create(['code' => 'Retail_EU', 'name' => 'Euró kisker', 'type' => 'retail', 'currency_id' => $eur->id, 'active' => true]);
        $wholeEur = PriceList::create(['code' => 'Wholesale_EU', 'name' => 'Euró nagyker', 'type' => 'wholesale', 'currency_id' => $eur->id, 'retail_price_list_id' => $retailEur->id, 'active' => true]);
        $brand = Brand::create(['code' => 'HT', 'name' => 'Heavy Tools', 'active' => true]);
        $type = OrderSheetType::create(['code' => 'T', 'name' => 'Textil', 'active' => true]);
        $bags = OrderSheetType::create(['code' => 'B', 'name' => 'Táska', 'active' => true]);
        $document = OrderType::where('code', 'VRELO')->firstOrFail();
        if ($ui->code === 'EN') {
            $type->update(['name' => 'Textile']);
            $bags->update(['name' => 'Bags']);
            $document->update(['name' => 'Normal preorder']);
        }
        $repPartner = Partner::create(['erp_partner_code' => 'P900001', 'name' => 'Minta Képviselő', 'active' => true]);
        $partner = Partner::create(['erp_partner_code' => 'P000001', 'name' => 'Mintabolt Kft.', 'sales_rep_erp_partner_code' => 'P900001', 'active' => true]);
        $other = Partner::create(['erp_partner_code' => 'P000002', 'name' => 'Próbabolt Kft.', 'sales_rep_erp_partner_code' => 'P900001', 'active' => true]);
        $addresses = [];
        foreach ([[$partner, '1001', 'Budapesti üzlet', '1111', 'Budapest', $whole, $huf],
            [$partner, '1002', 'Győri üzlet', '9021', 'Győr', $wholeEur, $eur],
            [$other, '2001', 'Debreceni üzlet', '4024', 'Debrecen', $whole, $huf]] as [$owner, $code, $name, $zip, $city, $list, $currency]) {
            $address = PartnerAddress::create(['partner_id' => $owner->id, 'addrid' => $code, 'name' => $name, 'zip' => $zip,
                'city' => $city, 'street' => 'Minta utca 1.', 'country' => 'HU', 'language_id' => $hu->id, 'currency_id' => $currency->id,
                'price_list_id' => $list->id, 'active' => true, 'allow_assortment_ordering' => $code === '1001']);
            $address->brands()->attach($brand);
            $address->orderSheetTypes()->attach([$type->id, $bags->id]);
            $address->orderTypes()->attach($document);
            $addresses[] = $address;
        }
        $user = PartnerUser::create(['partner_id' => $partner->id, 'name' => 'Minta Partnerfelhasználó', 'email' => 'partner@example.invalid', 'password' => 'demo-password', 'role' => 'partner_admin', 'active' => true]);
        $rep = PartnerUser::create(['partner_id' => $repPartner->id, 'name' => 'Minta Képviselő', 'email' => 'kepviselo@example.invalid', 'password' => 'demo-password', 'role' => 'sales_rep', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'T35', 'name' => 'Férfi dzseki', 'active' => true]);
        $sizeRange = SizeRange::create(['code' => 'S-XXL', 'name_hu' => 'S-XXL', 'matrix_group' => 'S-XXL', 'active' => true]);
        $sizes = [];
        foreach (['S', 'M', 'L', 'XL', 'XXL'] as $i => $code) {
            $sizes[] = $size = Size::create(['code' => $code, 'sort_order' => $i + 1, 'active' => true]);
            SizeRangeItem::create(['size_range_id' => $sizeRange->id, 'size_id' => $size->id, 'sort_order' => $i + 1]);
        }
        $pieceSkus = [];
        $assortments = [];
        foreach ([['T35045S27', 'NIKOLAY S27', 'FÉRFI DZSEKIK ÉS MELLÉNYEK', 14990, 32990],
            ['T35051S27', 'NUTRIAS S27', 'FÉRFI DZSEKIK ÉS MELLÉNYEK', 13990, 29990],
            ['T37027S27', 'WAFEN S27', 'FÉRFI NADRÁGOK', 9990, 21990]] as $i => [$code, $name, $catalog, $net, $gross]) {
            $product = Product::create(['visible_to_partners' => true, 'season_id' => $season->id, 'brand_id' => $brand->id, 'order_sheet_type_id' => $type->id,
                'item_main_group_id' => $group->id, 'size_range_id' => $sizeRange->id, 'model_code' => $code, 'name_hu' => $name,
                'catalog_group_name_hu' => $catalog, 'catalog_group_sort' => $i < 2 ? 1 : 2, 'catalog_sort' => $i + 1, 'active' => true]);
            foreach ([[$whole, $net], [$retail, $gross], [$wholeEur, round($net / 400, 2)], [$retailEur, round($gross / 400, 2)]] as [$list, $price]) {
                PriceListItem::create(['price_list_id' => $list->id, 'season_id' => $season->id, 'product_id' => $product->id, 'net_price' => $price]);
            }
            foreach (['NAVY', 'BLACK'] as $j => $colorName) {
                $color = Color::create(['product_id' => $product->id, 'code' => (string) ($j + 1), 'name_hu' => $colorName, 'sort_order' => $j, 'active' => true]);
                $components = [];
                foreach ($sizes as $k => $size) {
                    $sku = Sku::create(['product_id' => $product->id, 'color_id' => $color->id, 'size_id' => $size->id,
                        'sku_code' => $code.'-'.($j + 1).'-'.$size->code, 'sku_name' => $name.' '.$colorName.' '.$size->code, 'type' => 'piece', 'active' => true]);
                    $pieceSkus[] = $sku;
                    $components[] = $sku;
                }
                if ($i === 0 && $j === 0) {
                    $pack = Sku::create(['product_id' => $product->id, 'color_id' => $color->id, 'size_id' => null,
                        'sku_code' => $code.'-1-GY', 'sku_name' => $name.' NAVY GYŰJTŐ', 'type' => 'assortment', 'active' => true]);
                    foreach ($components as $k => $sku) {
                        ItemAssortment::create(['product_id' => $product->id, 'color_id' => $color->id, 'assortment_sku_id' => $pack->id,
                            'component_sku_id' => $sku->id, 'quantity' => $k === 2 ? 2 : 1]);
                    }
                    $assortments[] = $pack;
                }
            }
        }
        $orders = [];
        foreach ([[$addresses[0], 'ELŐ-S27-001', 'draft'], [$addresses[0], 'ELŐ-S27-002', 'draft'],
            [$addresses[1], 'ELŐ-S27-003', 'submitted'], [$addresses[2], 'ELŐ-S27-004', 'draft']] as $i => [$address, $reference, $status]) {
            $order = Order::create(['partner_id' => $address->partner_id, 'partner_address_id' => $address->id,
                'season_id' => $season->id, 'brand_id' => $brand->id, 'order_sheet_type_id' => $type->id, 'order_type_id' => $document->id,
                'price_list_id' => $address->price_list_id, 'currency_id' => $address->currency_id, 'language_id' => $ui->id,
                'reference_number' => $reference, 'status' => $status, 'submitted_at' => $status === 'submitted' ? now() : null]);
            foreach (array_slice($pieceSkus, 0, 15) as $k => $sku) {
                $quantity = ($k + $i) % 4 + 1;
                $price = (float) PriceListItem::where('price_list_id', $order->price_list_id)->where('product_id', $sku->product_id)->value('net_price');
                OrderItem::create(['order_id' => $order->id, 'sku_id' => $sku->id, 'quantity' => $quantity, 'unit_price' => $price, 'line_total' => $price * $quantity]);
            }
            if ($i === 0) {
                OrderItem::create(['order_id' => $order->id, 'sku_id' => $assortments[0]->id, 'quantity' => 2, 'unit_price' => 14990, 'line_total' => 14990 * 12]);
            }
            $orders[] = $order;
        }

        auth('partner')->logout();
        $this->capture('01-belepes', Livewire::test(Login::class)->set('languageId', $ui->id));
        $this->capture('02-elfelejtett-jelszo', Livewire::test(ForgotPassword::class));
        $this->actingAs($user, 'partner');
        $this->capture('00-update-notice', Livewire::test(Login::class)->set('languageId', $ui->id));
        $this->capture('03-szezonvalasztas', Livewire::test(Login::class)->set('languageId', $ui->id)->call('acknowledgeUpdateNotice')->set('seasonId', $season->id));
        session(['partner.season_id' => $season->id]);
        $selector = Livewire::test(OrderSelector::class)->set('partnerAddressId', $addresses[0]->id)->set('brandId', $brand->id)
            ->set('orderSheetTypeId', $type->id)->set('orderTypeId', $document->id)->set('referenceNumber', 'ELŐ-S27-005');
        $this->capture('04-rendeles-valasztas', $selector);
        $this->capture('05-rendeleslista', Livewire::test(SalesRepOrderSummary::class)->call('loadSummary'));
        $orderPage = Livewire::test(CatalogGroupOrder::class, ['order' => $orders[0], 'catalogGroupName' => 'FÉRFI DZSEKIK ÉS MELLÉNYEK']);
        $this->capture('06-rendelolap', $orderPage);
        $this->capture('07-megosztas', $orderPage->set('showSharePanel', true)->call('generateShareLink'));
        $link = OrderShareLink::firstOrFail();
        $this->capture('08-osszesitett-adatok', Livewire::test(OrderOverview::class, ['order' => $orders[0]]));
        $previews = ['success' => true, 'message' => '2 fájl ellenőrizve, mindkettő importálható.', 'files' => []];
        foreach (array_slice($orders, 0, 2) as $i => $order) {
            $previews['files'][] = ['key' => 'file-'.$i, 'index' => $i, 'filename' => 'Mintabolt-'.$order->reference_number.'.xlsx',
                'address_code' => '1001', 'reference_number' => $order->reference_number, 'success' => true, 'message' => 'Importálásra kész',
                'stats' => ['existing_quantity' => 48 + $i, 'existing_value' => 719520, 'changed' => 8 + $i, 'new_quantity' => 56 + $i, 'new_value' => 839440]];
        }
        $previews['files'][0]['missing_reference_number'] = true;
        $previews['files'][0]['reference_number'] = '';
        $previews['files'][0]['success'] = false;
        $previews['files'][0]['message'] = __('partner.import_missing_reference_number');
        $this->capture('09-import-ellenorzes', Livewire::test(CatalogGroupOrder::class, ['order' => $orders[0], 'catalogGroupName' => 'FÉRFI DZSEKIK ÉS MELLÉNYEK'])
            ->set('importPreviews', $previews)->set('selectedImportPreviewKeys', ['file-1']));
        $this->capture('10-bekuldott-rendeles', Livewire::test(OrderOverview::class, ['order' => $orders[2]]));
        $this->capture('11-tobb-rendeles-osszesitese', Livewire::withQueryParams(['orders' => $orders[0]->id.','.$orders[1]->id])
            ->test(OrderSummary::class, ['season' => $season, 'brand' => $brand, 'orderSheetType' => $type]));
        auth('partner')->logout();
        $this->capture('12-megosztott-rendelolap', Livewire::test(SharedCatalogGroupOrder::class, ['shareLink' => $link, 'catalogGroupName' => 'FÉRFI DZSEKIK ÉS MELLÉNYEK']));
        $this->actingAs($rep, 'partner');
        $repSummary = Livewire::test(SalesRepOrderSummary::class)->call('loadSummary')->set('selectedSummaryOrderIds', array_map(fn ($order) => (string) $order->id, $orders));
        $this->capture('13-kepviseloi-rendeleslista', $repSummary);
        $this->capture('14-erp-export', $repSummary->call('openErpExport')->set('erpExportNote', 'S27 előrendelés - minta')
            ->set('erpExportDeliveryDate', '2027-02-15'));
        $this->capture('15-lefedettseg', Livewire::test(PartnerOrderCoverage::class));
        $this->capture('16-visszanyitas', Livewire::test(CatalogGroupOrder::class, ['order' => $orders[2], 'catalogGroupName' => 'FÉRFI DZSEKIK ÉS MELLÉNYEK']));
        $this->capture('17-tomeges-bekuldes', Livewire::test(OrderBulkSubmission::class)
            ->call('prepareBulkSubmission', [$orders[0]->id, $orders[1]->id, $orders[2]->id]));
        $this->assertTrue(true);
    }

    private function capture(string $name, $page): void
    {
        if (property_exists($page->instance(), 'interfaceLanguageId')) {
            $page->set('interfaceLanguageId', Language::where('code', strtoupper(getenv('GUIDE_LOCALE') ?: 'HU'))->value('id'));
        }
        $page->assertSuccessful();
        $body = preg_replace('~<script\b[^>]*>.*?</script>~si', '', $page->html());
        if (get_class($page->instance()) === OrderSelector::class) {
            $summary = Livewire::test(SalesRepOrderSummary::class)->call('loadSummary')->html();
            $body = preg_replace_callback(
                '~(<section id="order-summary-panel"[^>]*>)[\s\S]*?(</section>)~',
                static fn ($match) => $match[1].$summary.$match[2],
                $body,
            );
        }
        $html = '<!doctype html><html lang="'.strtolower(getenv('GUIDE_LOCALE') ?: 'hu').'"><head><meta charset="utf-8"><title>Partner portal - demo data</title>'
            .'<script src="tailwind.js"></script><style>[x-cloak]{display:none!important}body{margin:0;background:#f1f5f9}input,select{border:1px solid #cbd5e1;border-radius:4px;padding:4px}button{cursor:default}</style></head><body>'
            .'<div style="position:fixed;inset:0;z-index:-1;background:url(\''.str_replace('\\', '/', base_path('public/images/partner_bg.jpg')).'\') center/cover;opacity:.15"></div>'
            .$body.'</body></html>';
        $folder = base_path('.tmp/partner-manual-v2').'/'.strtolower(getenv('GUIDE_LOCALE') ?: 'hu');
        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }
        copy(base_path('.tmp/partner-manual-v2/tailwind.js'), $folder.'/tailwind.js');
        file_put_contents($folder.'/'.$name.'.html', $html);
        $state = get_object_vars($page->instance());
        file_put_contents($folder.'/'.$name.'.json', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}

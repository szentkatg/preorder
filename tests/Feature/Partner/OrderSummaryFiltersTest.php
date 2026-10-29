<?php

namespace Tests\Feature\Partner;

use App\Exports\SalesRepSummaryExport;
use App\Livewire\Partner\SalesRepOrderSummary;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSheetType;
use App\Models\Partner;
use App\Models\PartnerAddress;
use App\Models\PartnerUser;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Season;
use App\Models\Size;
use App\Models\Sku;
use App\Services\Partner\SalesRepOrderSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class OrderSummaryFiltersTest extends TestCase
{
    use RefreshDatabase;

    private PartnerUser $user;

    private Season $season;

    private Brand $brand;

    private OrderSheetType $type;

    private array $partners = [];

    private array $addresses = [];

    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        session(['partner.locale' => 'hu']);
        Cache::flush();
        $this->season = Season::create(['code' => 'W27', 'name' => 'Tél', 'visible_to_partners' => true]);
        $this->brand = Brand::create(['code' => 'HT', 'name' => 'Heavy Tools']);
        $this->type = OrderSheetType::create(['code' => 'BAG', 'name' => 'Táska']);
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'symbol' => 'Ft']);
        $list = PriceList::create(['code' => 'WHOLE', 'name' => 'Nagyker', 'type' => 'wholesale', 'currency_id' => $currency->id]);
        foreach (['P000444' => 'Első képviselő', 'P000555' => 'Második képviselő', 'P000666' => 'Tiltott képviselő'] as $code => $name) {
            Partner::create(['erp_partner_code' => $code, 'name' => $name]);
        }
        foreach (['a' => 'P000444', 'b' => 'P000555', 'c' => null, 'denied' => 'P000666'] as $key => $code) {
            $partner = $this->partners[$key] = Partner::create(['erp_partner_code' => 'CUSTOMER-'.$key,
                'name' => 'Partner '.$key, 'sales_rep_erp_partner_code' => $code]);
            $this->addresses[$key] = PartnerAddress::create(['partner_id' => $partner->id, 'addrid' => $key, 'name' => 'Cím '.$key]);
        }
        $this->user = PartnerUser::create(['partner_id' => $this->partners['c']->id, 'role' => 'sales_rep',
            'name' => 'Képviselő', 'email' => 'summary@example.test', 'password' => 'password', 'active' => true]);
        $this->user->partners()->attach(collect($this->partners)->except('denied')->pluck('id'));
        $this->actingAs($this->user, 'partner');

        $group = ItemMainGroup::create(['code' => 'BAG', 'name' => 'Táska']);
        $product = Product::create(['season_id' => $this->season->id, 'item_main_group_id' => $group->id,
            'model_code' => 'B01W27', 'name_hu' => 'EMIRO', 'brand_id' => $this->brand->id, 'order_sheet_type_id' => $this->type->id]);
        $color = Color::create(['product_id' => $product->id, 'code' => '01', 'name_hu' => 'BLACK']);
        $size = Size::create(['code' => 'ONE', 'name_hu' => 'ONE']);
        $sku = Sku::create(['product_id' => $product->id, 'color_id' => $color->id, 'size_id' => $size->id,
            'sku_code' => 'B01W27-01', 'sku_name' => 'EMIRO', 'type' => 'piece']);
        PriceListItem::create(['price_list_id' => $list->id, 'season_id' => $this->season->id, 'product_id' => $product->id, 'net_price' => 100]);
        foreach (['a-draft' => ['a', 'draft', 2], 'a-submitted' => ['a', 'submitted', 3], 'a-editing' => ['a', 'editing', 4],
            'b-draft' => ['b', 'draft', 5], 'b-submitted' => ['b', 'submitted', 6], 'c-empty' => ['c', 'draft', 0],
            'denied' => ['denied', 'submitted', 99]] as $key => [$partnerKey, $status, $quantity]) {
            $order = $this->orders[$key] = Order::create(['season_id' => $this->season->id, 'brand_id' => $this->brand->id,
                'order_sheet_type_id' => $this->type->id, 'partner_id' => $this->partners[$partnerKey]->id,
                'partner_address_id' => $this->addresses[$partnerKey]->id, 'reference_number' => $key, 'status' => $status,
                'price_list_id' => $list->id, 'currency_id' => $currency->id]);
            OrderItem::create(['order_id' => $order->id, 'sku_id' => $sku->id, 'quantity' => $quantity, 'unit_price' => 100, 'line_total' => $quantity * 100]);
        }
    }

    private function ids($page): array
    {
        return $page->instance()->filteredSalesRepOrderSummaries->pluck('order_id')->sort()->values()->all();
    }

    private function expectedIds(array $keys): array
    {
        return collect($keys)->map(fn ($key) => $this->orders[$key]->id)->sort()->values()->all();
    }

    public function test_status_filter_supports_draft_submitted_and_legacy_editing_orders(): void
    {
        $page = Livewire::test(SalesRepOrderSummary::class)->call('loadSummary');
        $this->assertCount(6, $this->ids($page));
        foreach (['draft' => ['a-draft', 'b-draft', 'c-empty'], 'submitted' => ['a-submitted', 'b-submitted'], 'editing' => ['a-editing']] as $status => $keys) {
            $page->set('summaryStatus', $status);
            $this->assertSame($this->expectedIds($keys), $this->ids($page));
        }
        $page->set('summaryStatus', '');
        $this->assertCount(6, $this->ids($page));
    }

    public function test_representative_comes_from_partner_master_not_the_assigned_login_user(): void
    {
        $page = Livewire::test(SalesRepOrderSummary::class)->call('loadSummary')
            ->assertSee('P000444 – Első képviselő')->assertSee('P000555 – Második képviselő')
            ->assertDontSee('Tiltott képviselő')->assertDontSee('P000666');
        $page->set('summarySalesRepCode', 'P000444');
        $this->assertSame($this->expectedIds(['a-draft', 'a-submitted', 'a-editing']), $this->ids($page));
        $page->set('summarySalesRepCode', 'P000555');
        $this->assertSame($this->expectedIds(['b-draft', 'b-submitted']), $this->ids($page));
        $page->set('summarySalesRepCode', 'P000666');
        $this->assertSame([], $this->ids($page));
        $page->set('summarySalesRepCode', '');
        $this->assertCount(6, $this->ids($page));
    }

    public function test_filters_combine_with_existing_filters_and_do_not_recalculate_order_items(): void
    {
        $page = Livewire::test(SalesRepOrderSummary::class)->call('loadSummary');
        $this->mock(SalesRepOrderSummaryService::class)->shouldNotReceive('build');
        $page->set('summarySeasonId', $this->season->id)->set('summaryBrandId', $this->brand->id)
            ->set('summaryOrderSheetTypeId', $this->type->id)->set('summaryCurrency', 'Ft')
            ->set('summaryFilledFilter', 'filled')->set('summarySearch', 'Partner a')
            ->set('summaryStatus', 'submitted')->set('summarySalesRepCode', 'P000444');
        $this->assertSame($this->expectedIds(['a-submitted']), $this->ids($page));
        $rows = $page->instance()->filteredSalesRepOrderSummaries;
        $this->assertSame(3, $rows->sum('quantity'));
        $this->assertEquals(300, $rows->sum('wholesale_value_huf'));
        $page->set('summaryFilledFilter', 'empty');
        $this->assertSame([], $this->ids($page));
        $page->set('summaryStatus', '')->set('summarySalesRepCode', '')->set('summarySearch', '');
        $this->assertSame($this->expectedIds(['c-empty']), $this->ids($page));
    }

    public function test_selection_is_cleared_on_filter_change_and_select_all_only_selects_matching_orders(): void
    {
        $page = Livewire::test(SalesRepOrderSummary::class)->call('loadSummary')->call('selectAllSummaryOrders');
        $this->assertCount(6, $page->get('selectedSummaryOrderIds'));
        $page->set('summaryStatus', 'submitted')->assertSet('selectedSummaryOrderIds', [])->call('selectAllSummaryOrders');
        $this->assertEqualsCanonicalizing(array_map('strval', $this->expectedIds(['a-submitted', 'b-submitted'])), $page->get('selectedSummaryOrderIds'));
        $page->set('summarySalesRepCode', 'P000555')->assertSet('selectedSummaryOrderIds', [])->call('selectAllSummaryOrders');
        $page->assertSet('selectedSummaryOrderIds', [(string) $this->orders['b-submitted']->id]);
    }

    public function test_filters_are_restored_and_can_be_cleared_on_returning_to_the_page(): void
    {
        Livewire::test(SalesRepOrderSummary::class)->set('summaryStatus', 'draft')->set('summarySalesRepCode', 'P000444');
        $this->assertSame('draft', session('order_selector.summary_filters.status'));
        $this->assertSame('P000444', session('order_selector.summary_filters.sales_rep_code'));
        $page = Livewire::test(SalesRepOrderSummary::class)->assertSet('summaryStatus', 'draft')
            ->assertSet('summarySalesRepCode', 'P000444')->call('loadSummary');
        $this->assertSame($this->expectedIds(['a-draft']), $this->ids($page));
        $page->set('summaryStatus', '')->set('summarySalesRepCode', '');
        $reopened = Livewire::test(SalesRepOrderSummary::class)->call('loadSummary');
        $this->assertCount(6, $this->ids($reopened));
    }

    public function test_excel_export_only_contains_filtered_orders_and_totals(): void
    {
        Excel::fake();
        $page = Livewire::test(SalesRepOrderSummary::class)->call('loadSummary')
            ->set('summarySalesRepCode', 'P000555')->set('summaryStatus', 'submitted');
        $page->call('exportSalesRepSummary');
        Excel::assertDownloaded(__('partner.sales_rep_export_filename').'-'.now()->format('Ymd-His').'.xlsx', function (SalesRepSummaryExport $export): bool {
            $rows = $export->collection()->values();
            $this->assertSame('b-submitted', $rows[0][6]);
            $this->assertSame(6, $rows[0][10]);
            $this->assertEquals(600, $rows->last()[13]);
            $this->assertCount(4, $rows);

            return true;
        });
    }

    public function test_partner_admin_and_address_user_cannot_expand_access_using_filters(): void
    {
        foreach (['partner_admin', 'address_user'] as $role) {
            session()->forget('order_selector.summary_filters');
            $user = PartnerUser::create(['partner_id' => $this->partners['a']->id, 'role' => $role, 'name' => $role,
                'email' => $role.'@example.test', 'password' => 'password', 'active' => true]);
            $user->addresses()->attach($this->addresses['a']->id);
            $this->actingAs($user, 'partner');
            $page = Livewire::test(SalesRepOrderSummary::class)->call('loadSummary')
                ->assertSee('Első képviselő')->assertDontSee('Második képviselő')->assertDontSee('Tiltott képviselő');
            $this->assertSame($this->expectedIds(['a-draft', 'a-submitted', 'a-editing']), $this->ids($page));
            $page->set('summarySalesRepCode', 'P000555');
            $this->assertSame([], $this->ids($page));
            $page->call('openSummaryOrder', $this->orders['b-submitted']->id)->assertForbidden();
        }
    }

    public function test_revoked_access_cannot_leak_cached_rows_or_representative_options(): void
    {
        Livewire::test(SalesRepOrderSummary::class)->call('loadSummary');
        $this->user->partners()->detach($this->partners['b']->id);
        $page = Livewire::test(SalesRepOrderSummary::class)->set('summaryLoaded', true)
            ->assertDontSee('Második képviselő')->assertDontSee('b-submitted');
        $this->assertSame($this->expectedIds(['a-draft', 'a-submitted', 'a-editing', 'c-empty']), $this->ids($page));
    }

    public function test_filters_are_translated_in_english_and_hungarian(): void
    {
        foreach (['hu' => ['Minden rendelésstátusz', 'Minden területi képviselő'], 'en' => ['All order statuses', 'All sales representatives']] as $locale => [$status, $rep]) {
            session(['partner.locale' => $locale]);
            Livewire::test(SalesRepOrderSummary::class)->call('loadSummary')->assertSee($status)->assertSee($rep);
        }
    }
}

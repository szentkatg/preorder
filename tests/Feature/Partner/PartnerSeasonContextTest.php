<?php

namespace Tests\Feature\Partner;

use App\Livewire\Partner\Auth\Login;
use App\Livewire\Partner\CatalogGroupOrder;
use App\Livewire\Partner\Dashboard;
use App\Livewire\Partner\OrderSelector;
use App\Livewire\Partner\PartnerOrderCoverage;
use App\Livewire\Partner\SalesRepOrderSummary;
use App\Livewire\Partner\SeasonSelector;
use App\Livewire\Partner\SharedCatalogGroupOrder;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Currency;
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
use App\Models\Sku;
use App\Models\Translation;
use App\Services\Partner\ErpOrderExportService;
use App\Services\Partner\PartnerSeasonContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PartnerSeasonContextTest extends TestCase
{
    use RefreshDatabase;

    private Season $season;

    private Season $other;

    private Season $hidden;

    private PartnerUser $user;

    private Brand $brand;

    private OrderSheetType $type;

    private Language $english;

    private array $orders = [];

    private array $skus = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        session(['partner.locale' => 'hu']);
        $this->season = Season::create(['code' => 'W27', 'name' => 'Selected winter', 'visible_to_partners' => true, 'active' => true]);
        $this->other = Season::create(['code' => 'S27', 'name' => 'Other summer', 'visible_to_partners' => true, 'active' => false]);
        $this->hidden = Season::create(['code' => 'W28', 'name' => 'Unpublished next season', 'active' => true]);
        $hu = Language::create(['code' => 'HU', 'name' => 'Magyar', 'active' => true]);
        session(['partner.language_id' => $hu->id]);
        $this->english = Language::create(['code' => 'EN', 'name' => 'English', 'active' => true]);
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'symbol' => 'Ft', 'active' => true]);
        $list = PriceList::create(['code' => 'WHOLE', 'name' => 'Wholesale', 'type' => 'wholesale', 'currency_id' => $currency->id, 'active' => true]);
        $partner = Partner::create(['erp_partner_code' => 'P01', 'name' => 'Partner', 'active' => true]);
        $address = PartnerAddress::create(['partner_id' => $partner->id, 'addrid' => 'A01', 'name' => 'Address', 'language_id' => $hu->id,
            'price_list_id' => $list->id, 'currency_id' => $currency->id, 'active' => true]);
        $this->brand = Brand::create(['code' => 'HT', 'name' => 'Márka', 'active' => true]);
        $this->type = OrderSheetType::create(['code' => 'B', 'name' => 'Táskák', 'active' => true]);
        $address->brands()->attach($this->brand);
        $address->orderSheetTypes()->attach($this->type);
        $document = OrderType::firstOrCreate(['code' => 'VRELO'], ['name' => 'Előrendelés', 'active' => true]);
        $this->user = PartnerUser::create(['partner_id' => $partner->id, 'name' => 'User', 'email' => 'season@example.test',
            'password' => 'password', 'role' => 'sales_rep', 'active' => true]);
        $this->user->partners()->attach($partner);
        $this->user->addresses()->attach($address);
        $group = ItemMainGroup::create(['code' => 'B01', 'name' => 'Bags', 'active' => true]);
        $size = Size::create(['code' => 'ONE', 'name_hu' => 'ONE', 'active' => true]);
        foreach ([$this->season, $this->other, $this->hidden] as $season) {
            $product = Product::create(['model_code' => 'MODEL'.$season->code, 'name_hu' => 'Product '.$season->code,
                'season_id' => $season->id, 'item_main_group_id' => $group->id, 'brand_id' => $this->brand->id,
                'order_sheet_type_id' => $this->type->id, 'catalog_group_name_hu' => 'Bags', 'active' => true]);
            $color = Color::create(['product_id' => $product->id, 'code' => '01', 'name_hu' => 'BLACK', 'active' => true]);
            $sku = $this->skus[$season->id] = Sku::create(['product_id' => $product->id, 'color_id' => $color->id,
                'size_id' => $size->id, 'sku_code' => 'SKU'.$season->code, 'sku_name' => 'Product '.$season->code, 'type' => 'piece', 'active' => true]);
            $order = $this->orders[$season->id] = Order::create(['season_id' => $season->id, 'partner_id' => $partner->id,
                'partner_address_id' => $address->id, 'brand_id' => $this->brand->id, 'order_sheet_type_id' => $this->type->id,
                'reference_number' => 'REF'.$season->code, 'order_type_id' => $document->id, 'price_list_id' => $list->id, 'currency_id' => $currency->id, 'status' => 'draft']);
            OrderItem::create(['order_id' => $order->id, 'sku_id' => $sku->id, 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200]);
            PriceListItem::create(['price_list_id' => $list->id, 'season_id' => $season->id, 'product_id' => $product->id, 'net_price' => 100]);
        }
        $this->actingAs($this->user, 'partner');
        session([PartnerSeasonContext::SESSION_KEY => $this->season->id]);
    }

    public function test_login_lists_only_published_seasons_including_historical_ones(): void
    {
        auth('partner')->logout();
        Livewire::test(Login::class)->assertSee('Selected winter')->assertSee('Other summer')
            ->assertDontSee('Unpublished next season')->assertSet('seasonId', null);
    }

    public function test_login_sets_session_season_and_language_and_discards_previous_order_filters(): void
    {
        auth('partner')->logout();
        session(['partner_order_selector.order_id' => 987, 'order_selector.summary_filters.season_id' => $this->season->id]);
        Livewire::test(Login::class)->set('languageId', $this->english->id)->set('seasonId', $this->other->id)
            ->set('email', $this->user->email)->set('password', 'password')->call('login')
            ->assertHasNoErrors()->assertRedirect(route('partner.orders.select'));
        $this->assertSame($this->other->id, session(PartnerSeasonContext::SESSION_KEY));
        $this->assertSame('en', session('partner.locale'));
        $this->assertNull(session('partner_order_selector.order_id'));
        $this->assertNull(session('order_selector.summary_filters'));
        $this->assertAuthenticatedAs($this->user, 'partner');
    }

    public function test_missing_hidden_and_nonexistent_login_seasons_are_rejected_before_authentication(): void
    {
        auth('partner')->logout();
        session()->forget(PartnerSeasonContext::SESSION_KEY);
        foreach ([null, $this->hidden->id, 99999] as $id) {
            Livewire::test(Login::class)->set('seasonId', $id)->set('email', $this->user->email)
                ->set('password', 'password')->call('login')->assertHasErrors('seasonId');
            $this->assertGuest('partner');
            $this->assertNull(session(PartnerSeasonContext::SESSION_KEY));
        }
    }

    public function test_bad_password_does_not_set_the_selected_season(): void
    {
        auth('partner')->logout();
        session()->forget(PartnerSeasonContext::SESSION_KEY);
        Livewire::test(Login::class)->set('seasonId', $this->other->id)->set('email', $this->user->email)
            ->set('password', 'wrong-password')->call('login')->assertHasErrors('email');
        $this->assertGuest('partner');
        $this->assertNull(session(PartnerSeasonContext::SESSION_KEY));
    }

    public function test_existing_sessions_without_a_valid_season_go_to_selection_without_logging_out(): void
    {
        foreach ([null, $this->hidden->id, 99999] as $id) {
            session([PartnerSeasonContext::SESSION_KEY => $id]);
            $this->get(route('partner.orders.select'))->assertRedirect(route('partner.seasons.select'));
            $this->assertAuthenticatedAs($this->user, 'partner');
        }
        session([PartnerSeasonContext::SESSION_KEY => $this->season->id]);
        $this->get(route('partner.orders.select'))->assertOk();
    }

    public static function roles(): array
    {
        return [['partner_admin'], ['address_user'], ['sales_rep']];
    }

    #[DataProvider('roles')]
    public function test_every_role_only_sees_orders_of_the_login_season(string $role): void
    {
        $this->user->update(['role' => $role]);
        // A stale list filter cannot override the session's selection.
        session(['order_selector.summary_filters.season_id' => $this->other->id]);
        $selector = Livewire::test(OrderSelector::class)->assertSee('Selected winter')
            ->assertDontSeeHtml('wire:model.live="seasonId"')->assertDontSeeHtml('max-w-screen-2xl');
        $this->assertSame([$this->orders[$this->season->id]->id], $selector->instance()->accessibleOrders->modelKeys());
        $summary = Livewire::test(SalesRepOrderSummary::class)->assertSet('summarySeasonId', $this->season->id)->call('loadSummary');
        $this->assertSame([$this->orders[$this->season->id]->id], $summary->instance()->filteredSalesRepOrderSummaries->pluck('order_id')->all());
        Livewire::test(Dashboard::class)->assertSee('Selected winter')->assertDontSee('Other summer');
    }

    public static function lockedComponents(): array
    {
        return [[OrderSelector::class, 'seasonId'], [SalesRepOrderSummary::class, 'summarySeasonId'], [PartnerOrderCoverage::class, 'seasonId']];
    }

    #[DataProvider('lockedComponents')]
    public function test_season_properties_cannot_be_changed_by_a_browser_request(string $component, string $property): void
    {
        $page = Livewire::test($component)->assertSuccessful();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set($property, $this->other->id);
    }

    public function test_direct_links_to_another_visible_season_are_rejected_but_shares_remain_independent(): void
    {
        $otherOrder = $this->orders[$this->other->id];
        $this->get(route('partner.seasons.show', $this->other))->assertNotFound();
        $this->get(route('partner.catalog-group-order', ['order' => $otherOrder, 'catalogGroupName' => 'Bags']))->assertNotFound();
        $share = OrderShareLink::create(['order_id' => $otherOrder->id, 'token' => str_repeat('c', 64), 'expires_at' => now()->addDay(),
            'price_list_id' => $otherOrder->price_list_id, 'created_by_partner_user_id' => $this->user->id]);
        Livewire::test(SharedCatalogGroupOrder::class, ['shareLink' => $share, 'catalogGroupName' => 'Bags'])
            ->assertSuccessful()->assertSee('Other summer')->assertDontSeeHtml('href="'.route('partner.seasons.select').'"');
        $this->assertSame($this->season->id, session(PartnerSeasonContext::SESSION_KEY));
    }

    public function test_order_header_shows_season_and_translated_brand_and_sheet_type(): void
    {
        Translation::create(['entity' => 'brand', 'entity_code' => $this->brand->code, 'field' => 'name', 'language_id' => $this->english->id, 'value' => 'English brand']);
        Translation::create(['entity' => 'order_sheet_type', 'entity_code' => $this->type->code, 'field' => 'name', 'language_id' => $this->english->id, 'value' => 'English bags']);
        session(['partner.locale' => 'en', 'partner.language_id' => $this->english->id]);
        Livewire::test(CatalogGroupOrder::class, ['order' => $this->orders[$this->season->id], 'catalogGroupName' => 'Bags'])
            ->assertSuccessful()->assertSee('Selected winter')->assertSee('W27')->assertSee('English brand')->assertSee('English bags')
            ->assertSeeHtml('data-partner-season-banner');
    }

    public function test_new_orders_use_login_season_without_a_season_form_field(): void
    {
        Livewire::test(OrderSelector::class)->assertSet('seasonId', $this->season->id)->set('referenceNumber', 'NEW-REF')
            ->call('proceed')->assertHasNoErrors();
        $this->assertDatabaseHas('orders', ['reference_number' => 'NEW-REF', 'season_id' => $this->season->id]);
        $this->assertDatabaseMissing('orders', ['reference_number' => 'NEW-REF', 'season_id' => $this->other->id]);
    }

    public function test_an_old_tab_cannot_save_after_the_session_changes_season(): void
    {
        $order = $this->orders[$this->season->id];
        $page = Livewire::test(CatalogGroupOrder::class, ['order' => $order, 'catalogGroupName' => 'Bags'])->assertSuccessful();
        Livewire::test(SeasonSelector::class)->set('seasonId', $this->other->id)->call('selectSeason')->assertHasNoErrors();
        $page->call('savePieceQuantity', $this->skus[$this->season->id]->id, 99)->assertNotFound();
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->value('quantity'));
    }

    public function test_erp_export_rejects_orders_from_another_login_season(): void
    {
        $this->expectException(HttpException::class);
        app(ErpOrderExportService::class)->selectedOrders([$this->orders[$this->season->id]->id, $this->orders[$this->other->id]->id]);
    }

    public function test_summary_cache_is_separate_for_each_selected_season(): void
    {
        Livewire::test(SalesRepOrderSummary::class)->call('loadSummary');
        session([PartnerSeasonContext::SESSION_KEY => $this->other->id]);
        $page = Livewire::test(SalesRepOrderSummary::class);
        $this->assertCount(0, $page->instance()->salesRepOrderSummaries);
        $page->call('loadSummary');
        $this->assertSame([$this->other->id], $page->instance()->salesRepOrderSummaries->pluck('season_id')->all());
    }

    public function test_logout_removes_season_but_preserves_language_choice(): void
    {
        session(['partner.locale' => 'en', 'partner.language_id' => $this->english->id]);
        $this->post(route('partner.logout'))->assertRedirect(route('partner.login'));
        $this->assertNull(session(PartnerSeasonContext::SESSION_KEY));
        $this->assertSame('en', session('partner.locale'));
    }

    #[DataProvider('roles')]
    public function test_season_switch_keeps_every_role_logged_in_without_a_password(string $role): void
    {
        $this->user->update(['role' => $role]);
        session(['partner.locale' => 'en', 'partner.language_id' => $this->english->id,
            'partner_order_selector.order_id' => $this->orders[$this->season->id]->id,
            'order_selector.summary_filters.status' => 'draft', 'order_selector.open_order_id' => 42]);
        $beforeOrders = Order::orderBy('id')->get()->toArray();
        $beforeItems = OrderItem::orderBy('id')->get()->toArray();
        Livewire::test(SeasonSelector::class)->assertSet('seasonId', $this->season->id)->assertDontSeeHtml('type="password"')
            ->assertSee('Change season')->set('seasonId', $this->other->id)->call('selectSeason')
            ->assertHasNoErrors()->assertRedirect(route('partner.orders.select'));
        $this->assertAuthenticatedAs($this->user, 'partner');
        $this->assertSame($this->other->id, session(PartnerSeasonContext::SESSION_KEY));
        $this->assertSame('en', session('partner.locale'));
        $this->assertSame($this->english->id, session('partner.language_id'));
        $this->assertNull(session('partner_order_selector'));
        $this->assertNull(session('order_selector'));
        $this->assertSame($beforeOrders, Order::orderBy('id')->get()->toArray());
        $this->assertSame($beforeItems, OrderItem::orderBy('id')->get()->toArray());
        $selector = Livewire::test(OrderSelector::class)->assertSee('Other summer');
        $this->assertSame([$this->orders[$this->other->id]->id], $selector->instance()->accessibleOrders->modelKeys());
    }

    public function test_visiting_or_abandoning_the_season_form_does_not_change_the_current_selection(): void
    {
        session(['partner_order_selector.order_id' => $this->orders[$this->season->id]->id]);
        $this->get(route('partner.seasons.select'))->assertOk()->assertSee('Szezonváltás')->assertDontSee('type="password"', false);
        Livewire::test(SeasonSelector::class)->set('seasonId', $this->other->id);
        $this->assertSame($this->season->id, session(PartnerSeasonContext::SESSION_KEY));
        $this->assertSame($this->orders[$this->season->id]->id, session('partner_order_selector.order_id'));
    }

    public function test_hidden_and_missing_seasons_cannot_be_selected_during_a_switch(): void
    {
        foreach ([null, $this->hidden->id, 99999] as $id) {
            Livewire::test(SeasonSelector::class)->set('seasonId', $id)->call('selectSeason')->assertHasErrors('seasonId');
            $this->assertSame($this->season->id, session(PartnerSeasonContext::SESSION_KEY));
            $this->assertAuthenticatedAs($this->user, 'partner');
        }
        $page = Livewire::test(SeasonSelector::class)->set('seasonId', $this->other->id);
        $this->other->update(['visible_to_partners' => false]);
        $page->call('selectSeason')->assertHasErrors('seasonId');
        $this->assertSame($this->season->id, session(PartnerSeasonContext::SESSION_KEY));
    }

    public function test_season_switch_rechecks_disabled_accounts(): void
    {
        $page = Livewire::test(SeasonSelector::class)->assertSuccessful();
        $this->user->update(['active' => false]);
        $page->call('selectSeason')->assertForbidden();
        $this->assertSame($this->season->id, session(PartnerSeasonContext::SESSION_KEY));
    }

    public function test_season_switch_requires_authentication(): void
    {
        auth('partner')->logout();
        $this->get(route('partner.seasons.select'))->assertRedirect(route('login'));
        Livewire::test(SeasonSelector::class)->assertForbidden();
    }

    public function test_older_sessions_can_choose_a_season_without_credentials(): void
    {
        session()->forget(PartnerSeasonContext::SESSION_KEY);
        Livewire::test(SeasonSelector::class)->assertSuccessful()->set('seasonId', $this->other->id)
            ->call('selectSeason')->assertHasNoErrors()->assertRedirect(route('partner.orders.select'));
        $this->assertSame($this->other->id, session(PartnerSeasonContext::SESSION_KEY));
        $this->assertAuthenticatedAs($this->user, 'partner');
    }

    public function test_switch_button_is_present_on_order_selector_and_order_sheet(): void
    {
        Livewire::test(OrderSelector::class)->assertSee('Szezonváltás')->assertSeeHtml('href="'.route('partner.seasons.select').'"');
        Livewire::test(CatalogGroupOrder::class, ['order' => $this->orders[$this->season->id], 'catalogGroupName' => 'Bags'])
            ->assertSee('Szezonváltás')->assertSeeHtml('href="'.route('partner.seasons.select').'"');
    }

    public function test_missing_season_on_a_real_livewire_request_redirects_to_selection_without_losing_login(): void
    {
        $html = $this->get(route('partner.orders.select'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', $html, $match));
        $snapshot = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        session()->forget(PartnerSeasonContext::SESSION_KEY);
        $this->postJson('/livewire/update', ['_token' => csrf_token(), 'components' => [[
            'snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]]])->assertRedirect(route('partner.seasons.select'));
        $this->assertAuthenticatedAs($this->user, 'partner');
    }
}

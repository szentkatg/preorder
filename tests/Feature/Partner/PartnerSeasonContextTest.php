<?php

namespace Tests\Feature\Partner;

use App\Livewire\Partner\Auth\Login;
use App\Livewire\Partner\CatalogGroupOrder;
use App\Livewire\Partner\Dashboard;
use App\Livewire\Partner\OrderOverview;
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
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
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
        $address->orderTypes()->attach($document);
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

    public function test_login_shows_credentials_first_and_lists_only_published_seasons_after_authentication(): void
    {
        auth('partner')->logout();
        Livewire::test(Login::class)->assertDontSee('Selected winter')->assertDontSee('Other summer')
            ->assertDontSeeHtml('data-login-season-modal')->assertSet('seasonId', null)
            ->set('email', $this->user->email)->set('password', 'password')->call('login')
            ->assertRedirect(route('partner.login'))->assertSet('password', '');
        $this->assertNull(session(PartnerSeasonContext::SESSION_KEY));
        Livewire::test(Login::class)->assertSee('Selected winter')->assertSee('Other summer')
            ->assertDontSee('Unpublished next season')->assertSeeHtml('data-login-season-modal')->assertSet('seasonId', null);
    }

    public function test_login_season_tooltip_and_validation_follow_language_changes_without_resetting_selection(): void
    {
        $huId = Language::where('code', 'HU')->value('id');
        $page = Livewire::test(Login::class)->set('seasonId', $this->season->id);
        foreach ([[$this->english->id, 'Select season', 'Select an available season to continue.'],
            [$huId, 'Válassz szezont', 'Válassz elérhető szezont a folytatáshoz.']] as [$languageId, $title, $message]) {
            $page->set('languageId', $languageId)->assertSet('seasonId', $this->season->id)
                ->assertSeeHtml('title="'.$title.'"')->assertSeeHtml('data-required-message="'.$message.'"')
                ->assertSeeHtml('id="partner-season-help" role="note"')
                ->assertSeeHtml('data-login-season-modal')
                ->assertSeeHtml('oninvalid="this.setCustomValidity(this.value ? \'\' : this.dataset.requiredMessage)"')
                ->assertSeeHtml('oninput="this.setCustomValidity(\'\')"')->assertSeeHtml('onchange="this.setCustomValidity(\'\')"');
            $page->set('seasonId', null)->call('selectSeason')
                ->assertHasErrors('seasonId')->assertSee($message)->set('seasonId', $this->season->id);
            $this->assertAuthenticatedAs($this->user, 'partner');
        }
    }

    #[DataProvider('tooltipLanguages')]
    public function test_authenticated_season_selection_uses_the_selected_language_for_tooltips(string $locale, string $title, string $message): void
    {
        $languageId = Language::where('code', strtoupper($locale))->value('id');
        session(['partner.locale' => $locale, 'partner.language_id' => $languageId]);
        Livewire::test(SeasonSelector::class)->assertSeeHtml('title="'.$title.'"')
            ->assertSeeHtml('data-required-message="'.$message.'"')
            ->assertSeeHtml('id="selected-partner-season-help" role="note"')
            ->assertSeeHtml('<strong class="mb-1 block">'.$message.'</strong>')
            ->assertSeeHtml('oninvalid="this.setCustomValidity(this.value ? \'\' : this.dataset.requiredMessage)"')
            ->assertSeeHtml('oninput="this.setCustomValidity(\'\')"')->assertSeeHtml('onchange="this.setCustomValidity(\'\')"')
            ->set('seasonId', null)->call('selectSeason')->assertHasErrors('seasonId')->assertSee($message);
        $this->assertSame($this->season->id, session(PartnerSeasonContext::SESSION_KEY));
    }

    public static function tooltipLanguages(): array
    {
        return [
            'Hungarian' => ['hu', 'Válassz szezont', 'Válassz elérhető szezont a folytatáshoz.'],
            'English' => ['en', 'Select season', 'Select an available season to continue.'],
        ];
    }

    public function test_login_sets_session_season_and_language_and_discards_previous_order_filters(): void
    {
        auth('partner')->logout();
        session(['partner_order_selector.order_id' => 987, 'order_selector.summary_filters.season_id' => $this->season->id]);
        Livewire::test(Login::class)->set('languageId', $this->english->id)->set('seasonId', $this->other->id)
            ->set('email', $this->user->email)->set('password', 'password')->call('login')
            ->assertHasNoErrors()->assertRedirect(route('partner.login'))->assertSet('password', '');
        $this->assertNull(session(PartnerSeasonContext::SESSION_KEY));
        $this->get(route('partner.orders.select'))->assertRedirect(route('partner.seasons.select'));
        Livewire::test(Login::class)->assertSet('showSeasonModal', true)->set('seasonId', $this->other->id)
            ->call('selectSeason')->assertHasNoErrors()->assertRedirect(route('partner.orders.select'));
        $this->assertSame($this->other->id, session(PartnerSeasonContext::SESSION_KEY));
        $this->assertSame('en', session('partner.locale'));
        $this->assertNull(session('partner_order_selector.order_id'));
        $this->assertNull(session('order_selector.summary_filters'));
        $this->assertAuthenticatedAs($this->user, 'partner');
    }

    public function test_missing_hidden_and_nonexistent_modal_seasons_are_rejected_after_authentication(): void
    {
        auth('partner')->logout();
        session()->forget(PartnerSeasonContext::SESSION_KEY);
        Livewire::test(Login::class)->set('email', $this->user->email)->set('password', 'password')->call('login')
            ->assertRedirect(route('partner.login'));
        $modal = Livewire::test(Login::class)->assertSet('showSeasonModal', true);
        foreach ([null, $this->hidden->id, 99999] as $id) {
            $modal->set('seasonId', $id)->call('selectSeason')->assertHasErrors('seasonId');
            $this->assertAuthenticatedAs($this->user, 'partner');
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

    public static function languageSelectorPages(): array
    {
        return [[OrderSelector::class], [CatalogGroupOrder::class], [SharedCatalogGroupOrder::class]];
    }

    #[DataProvider('languageSelectorPages')]
    public function test_header_language_switch_preserves_the_season_order_and_business_data(string $component): void
    {
        $order = $this->orders[$this->season->id];
        session(['partner_order_selector.season_id' => $this->season->id,
            'partner_order_selector.partner_address_id' => $order->partner_address_id,
            'partner_order_selector.brand_id' => $order->brand_id,
            'partner_order_selector.order_sheet_type_id' => $order->order_sheet_type_id,
            'partner_order_selector.order_id' => $order->id]);
        Translation::create(['entity' => 'brand', 'entity_code' => $this->brand->code, 'field' => 'name', 'language_id' => $this->english->id, 'value' => 'English brand']);
        Translation::create(['entity' => 'order_sheet_type', 'entity_code' => $this->type->code, 'field' => 'name', 'language_id' => $this->english->id, 'value' => 'English bags']);
        $params = $component === OrderSelector::class ? [] : ['order' => $order, 'catalogGroupName' => 'Bags'];
        if ($component === SharedCatalogGroupOrder::class) {
            $link = OrderShareLink::create(['order_id' => $order->id, 'token' => str_repeat('d', 64),
                'price_list_id' => $order->price_list_id, 'expires_at' => now()->addDay(), 'created_by_partner_user_id' => $this->user->id]);
            $params = ['shareLink' => $link, 'catalogGroupName' => 'Bags'];
            auth('partner')->logout();
            session()->forget(PartnerSeasonContext::SESSION_KEY);
        }
        $beforeOrders = Order::orderBy('id')->get()->toArray();
        $beforeItems = OrderItem::orderBy('id')->get()->toArray();
        $huId = Language::where('code', 'HU')->value('id');
        $page = Livewire::test($component, $params)->assertSuccessful()->assertSet('interfaceLanguageId', $huId)
            ->assertSeeHtml('wire:model.live="interfaceLanguageId"');
        if ($component === OrderSelector::class) {
            $page->set('referenceNumber', 'UNSAVED-REFERENCE');
        }
        $page->set('interfaceLanguageId', $this->english->id)->assertHasNoErrors()->assertSee('Language')
            ->assertSee('English brand')->assertSee('English bags')
            ->assertDispatched('partner-interface-language-changed', locale: 'en');
        $this->assertSame('en', session('partner.locale'));
        $this->assertSame($this->english->id, session('partner.language_id'));
        $this->assertSame('en', Cookie::queued('partner_locale')->getValue());
        $this->assertSame($component === SharedCatalogGroupOrder::class ? null : $this->season->id, session(PartnerSeasonContext::SESSION_KEY));
        if ($component === OrderSelector::class) {
            $page->assertSet('selectedOrderId', $order->id)->assertSet('referenceNumber', 'UNSAVED-REFERENCE');
        } else {
            $page->assertSet('order.id', $order->id);
        }
        $page->set('interfaceLanguageId', $huId)->assertHasNoErrors()->assertSee('Nyelv');
        $this->assertSame($beforeOrders, Order::orderBy('id')->get()->toArray());
        $this->assertSame($beforeItems, OrderItem::orderBy('id')->get()->toArray());
    }

    public function test_header_language_options_exclude_inactive_and_unsupported_languages(): void
    {
        $inactive = Language::create(['code' => 'SK', 'name' => 'Inactive', 'active' => false]);
        $unsupported = Language::create(['code' => 'FR', 'name' => 'Unsupported', 'active' => true]);
        $page = Livewire::test(OrderSelector::class);
        $this->assertSame(['HU', 'EN'], $page->instance()->interfaceLanguages->pluck('code')->all());
        foreach ([null, 99999, $inactive->id, $unsupported->id] as $id) {
            $page->set('interfaceLanguageId', $id)->assertHasErrors('interfaceLanguageId');
            $this->assertSame('hu', session('partner.locale'));
            $this->assertSame($this->season->id, session(PartnerSeasonContext::SESSION_KEY));
        }
    }

    public function test_language_event_refreshes_summary_translations_without_losing_filters_or_selected_orders(): void
    {
        Translation::create(['entity' => 'order_sheet_type', 'entity_code' => $this->type->code,
            'field' => 'name', 'language_id' => $this->english->id, 'value' => 'English bags']);
        $orderId = (string) $this->orders[$this->season->id]->id;
        $summary = Livewire::test(SalesRepOrderSummary::class)->call('loadSummary')->set('summaryStatus', 'draft')
            ->set('selectedSummaryOrderIds', [$orderId]);
        Livewire::test(OrderSelector::class)->set('interfaceLanguageId', $this->english->id)
            ->assertDispatched('partner-interface-language-changed', locale: 'en');
        $summary->dispatch('partner-interface-language-changed', locale: 'hu')->assertSuccessful()
            ->assertSee('English bags')->assertSet('summaryStatus', 'draft')->assertSet('selectedSummaryOrderIds', [$orderId]);
        $this->assertSame('English bags', $summary->instance()->filteredSalesRepOrderSummaries->first()['type']);
        $this->assertSame('en', session('partner.locale'));
        $this->get(route('partner.seasons.select'))->assertOk()->assertSee('Change season');
    }

    public function test_language_events_do_not_eagerly_load_the_summary(): void
    {
        Livewire::test(SalesRepOrderSummary::class)->assertSet('summaryLoaded', false)
            ->dispatch('partner-interface-language-changed', locale: 'en')->assertSet('summaryLoaded', false);
    }

    public function test_order_sheet_footer_has_scroll_space_and_wrapping_buttons_in_normal_and_shared_views(): void
    {
        $order = $this->orders[$this->season->id];
        $link = OrderShareLink::create(['order_id' => $order->id, 'token' => str_repeat('e', 64),
            'price_list_id' => $order->price_list_id, 'expires_at' => now()->addDay(), 'created_by_partner_user_id' => $this->user->id]);
        foreach ([
            [CatalogGroupOrder::class, ['order' => $order, 'catalogGroupName' => 'Bags']],
            [SharedCatalogGroupOrder::class, ['shareLink' => $link, 'catalogGroupName' => 'Bags']],
        ] as [$component, $params]) {
            $html = Livewire::test($component, $params)->assertSuccessful()->html();
            $this->assertStringContainsString('data-partner-order-sheet', $html);
            $this->assertStringContainsString('padding-bottom: calc(4rem + env(safe-area-inset-bottom, 0px));', $html);
            $this->assertSame(1, preg_match('/<div data-partner-order-actions class="([^"]+)">/', $html, $actions));
            $this->assertContains('flex-wrap', explode(' ', $actions[1]));
            $this->assertNotContains('fixed', explode(' ', $actions[1]));
            $this->assertStringContainsString('wire:click="save"', $html);
            if ($component === CatalogGroupOrder::class) {
                $this->assertStringContainsString('wire:click="exportExcel"', $html);
                $this->assertStringContainsString('id="excelFiles"', $html);
            }
        }
    }

    public function test_catalog_summary_is_separate_and_navigation_has_the_requested_order(): void
    {
        $order = $this->orders[$this->season->id];
        $product = $this->skus[$this->season->id]->product;
        $product->update(['catalog_group_sort' => 2]);
        foreach ([['PREV', 'Previous category', 1], ['NEXT', 'Next category', 3]] as [$code, $name, $sort]) {
            $product->replicate()->fill(['model_code' => $code, 'catalog_group_name_hu' => $name, 'catalog_group_sort' => $sort])->save();
        }
        session(['partner_order_selector.season_id' => $this->season->id, 'partner_order_selector.order_id' => $order->id,
            'partner_order_selector.partner_address_id' => $order->partner_address_id, 'partner_order_selector.brand_id' => $order->brand_id,
            'partner_order_selector.order_sheet_type_id' => $order->order_sheet_type_id]);
        Livewire::test(OrderSelector::class)->assertSeeHtml('data-order-selection-panel')->assertSeeHtml('background-color: #f0f9ff;')
            ->assertDontSeeHtml('wire:click="submitSelectedOrder"')->assertDontSeeHtml('wire:click="openCatalogGroup(');
        $html = Livewire::test(CatalogGroupOrder::class, ['order' => $order, 'catalogGroupName' => 'Bags'])->assertSuccessful()->html();
        $navigation = substr($html, strpos($html, 'data-order-navigation'));
        $this->assertLessThan(strpos($navigation, 'Összesített adatok'), strpos($navigation, 'Vissza a rendelésekhez'));
        $this->assertLessThan(strpos($navigation, 'Previous category'), strpos($navigation, 'Összesített adatok'));
        $this->assertLessThan(strpos($navigation, 'Next category'), strpos($navigation, 'Previous category'));
        $page = Livewire::test(OrderOverview::class, ['order' => $order->id])->assertSuccessful()->assertSee('Összesített adatok')
            ->assertSee('Bags')->assertSee('Previous category')->assertSee('Next category')->assertSeeHtml('wire:click="submitSelectedOrder"');
        $this->assertSame(2, $page->instance()->catalogGroups->sum('quantity'));
        $this->assertEquals(200, $page->instance()->catalogGroups->sum('wholesale_value'));
        $page->call('openCatalogGroup', 'Bags')->assertRedirect(route('partner.catalog-group-order', ['order' => $order, 'catalogGroupName' => 'Bags']));
    }

    public function test_overview_blocks_other_seasons_and_revoked_address_access(): void
    {
        Livewire::test(OrderOverview::class, ['order' => $this->orders[$this->other->id]->id])->assertNotFound();
        $page = Livewire::test(OrderOverview::class, ['order' => $this->orders[$this->season->id]->id])->assertSuccessful();
        $this->user->partners()->detach();
        $this->user->addresses()->detach();
        $page->call('openOrder')->assertForbidden();
    }

    #[DataProvider('roles')]
    public function test_only_sales_reps_see_coverage_and_may_reopen_an_order(string $role): void
    {
        $this->user->update(['role' => $role]);
        $summary = Livewire::test(SalesRepOrderSummary::class)->call('loadSummary');
        $coverage = 'href="'.route('partner.order-coverage', ['locale' => 'hu']).'"';
        $role === 'sales_rep' ? $summary->assertSeeHtml($coverage) : $summary->assertDontSeeHtml($coverage);
        $order = $this->orders[$this->season->id];
        $order->update(['status' => 'submitted']);
        $overview = Livewire::test(OrderOverview::class, ['order' => $order->id])->assertSuccessful();
        if ($role === 'sales_rep') {
            $overview->assertSeeHtml('wire:click="resetSelectedOrderToDraft"')->call('resetSelectedOrderToDraft');
            $this->assertSame('draft', $order->fresh()->status);
        } else {
            $overview->assertDontSeeHtml('wire:click="resetSelectedOrderToDraft"')->call('resetSelectedOrderToDraft')->assertForbidden();
            $this->assertSame('submitted', $order->fresh()->status);
        }
    }

    public function test_address_type_permissions_restrict_choices_and_update_when_switching_addresses(): void
    {
        $order = $this->orders[$this->season->id];
        $address = $order->partnerAddress;
        $alternativeType = OrderType::where('code', 'VRBELO')->firstOrFail();
        $secondAddress = $address->replicate()->fill(['addrid' => 'A02', 'name' => 'Second address']);
        $secondAddress->save();
        $secondAddress->brands()->attach($this->brand);
        $secondAddress->orderSheetTypes()->attach($this->type);
        $secondAddress->orderTypes()->attach($alternativeType);
        $page = Livewire::test(OrderSelector::class)->set('partnerAddressId', $address->id);
        $this->assertSame(['VRELO'], $page->instance()->orderTypes->pluck('code')->all());
        $page->set('partnerAddressId', $secondAddress->id)->assertSet('orderTypeId', $alternativeType->id);
        $this->assertSame(['VRBELO'], $page->instance()->orderTypes->pluck('code')->all());
        $page->set('brandId', $this->brand->id)->set('orderSheetTypeId', $this->type->id)->set('referenceNumber', 'BOLT-REF')
            ->call('proceed')->assertHasNoErrors();
        $this->assertDatabaseHas('orders', ['reference_number' => 'BOLT-REF', 'partner_address_id' => $secondAddress->id, 'order_type_id' => $alternativeType->id]);
    }

    public function test_removed_type_permission_blocks_creation_but_does_not_hide_existing_orders(): void
    {
        $order = $this->orders[$this->season->id];
        $page = Livewire::test(OrderSelector::class)->set('referenceNumber', 'NOT-ALLOWED');
        $order->partnerAddress->orderTypes()->detach();
        $count = Order::count();
        $page->call('proceed')->assertStatus(422);
        $this->assertSame($count, Order::count());
        Livewire::test(OrderSelector::class)->assertSee('REFW27')->assertSee(__('partner.no_allowed_order_types'))->assertDontSee(__('partner.create_order'));
        Livewire::test(OrderOverview::class, ['order' => $order->id])->assertSuccessful();
    }

    public function test_server_validation_rejects_a_forged_order_type_even_if_it_is_active(): void
    {
        $order = $this->orders[$this->season->id];
        $probe = new class extends OrderSelector
        {
            public function attemptCreate(): Order
            {
                return $this->createOrder();
            }
        };
        $probe->seasonId = $this->season->id;
        $probe->partnerAddressId = $order->partner_address_id;
        $probe->brandId = $order->brand_id;
        $probe->orderSheetTypeId = $order->order_sheet_type_id;
        $probe->referenceNumber = 'FORGED';
        $probe->orderTypeId = OrderType::where('code', 'VRBELO')->value('id');
        try {
            $probe->attemptCreate();
            $this->fail('An order type without address permission was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('orderTypeId', $error->errors());
            $this->assertDatabaseMissing('orders', ['reference_number' => 'FORGED']);
        }
    }

    public function test_migration_grants_only_vrelo_to_existing_addresses_and_no_implicit_grants_to_new_addresses(): void
    {
        $before = Order::orderBy('id')->get()->toArray();
        OrderType::where('code', 'TSHARE')->update(['active' => false]);
        $migration = require database_path('migrations/2026_11_16_150000_add_address_order_types_and_pool_suggestions.php');
        $migration->down();
        $migration->up();
        $address = $this->orders[$this->season->id]->partnerAddress;
        $this->assertSame(['VRELO'], $address->orderTypes()->pluck('code')->all());
        $new = $address->replicate()->fill(['addrid' => 'NEW-ADDRESS']);
        $new->save();
        $this->assertCount(0, $new->orderTypes);
        $this->assertSame($before, Order::orderBy('id')->get()->toArray());
    }
}

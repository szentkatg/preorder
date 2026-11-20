<?php

namespace Tests\Feature\Partner;

use App\Livewire\Partner\CatalogGroupOrder;
use App\Livewire\Partner\SharedCatalogGroupOrder;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShareLink;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderSharePriceListAccessTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;

    private Partner $foreignPartner;

    private PartnerUser $user;

    private PartnerAddress $address;

    private Order $order;

    private Product $product;

    private Sku $sku;

    private array $lists = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        session(['partner.locale' => 'hu']);
        $season = Season::create(['code' => 'S27', 'name' => 'Summer', 'visible_to_partners' => true]);
        session(['partner.season_id' => $season->id]);
        $huf = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'symbol' => 'Ft']);
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€']);
        foreach (['retail' => ['retail', $huf], 'retail_dist' => ['retail', $eur], 'whole' => ['wholesale', $huf],
            'dist' => ['distributor', $eur], 'foreign' => ['wholesale', $huf], 'unassigned' => ['wholesale', $huf],
            'inactive' => ['wholesale', $huf], 'inactive_address' => ['wholesale', $huf], 'outlet' => ['outlet', $huf]] as $key => [$type, $currency]) {
            $this->lists[$key] = PriceList::create(['code' => strtoupper($key), 'name' => 'Price '.$key,
                'type' => $type, 'currency_id' => $currency->id, 'active' => $key !== 'inactive']);
        }
        $this->lists['whole']->update(['retail_price_list_id' => $this->lists['retail']->id]);
        $this->lists['dist']->update(['retail_price_list_id' => $this->lists['retail_dist']->id]);
        $this->partner = Partner::create(['erp_partner_code' => 'P1', 'name' => 'Order owner']);
        $this->foreignPartner = Partner::create(['erp_partner_code' => 'P2', 'name' => 'Other partner']);
        foreach (['whole', 'dist', 'inactive', 'inactive_address', 'retail', 'outlet'] as $key) {
            $address = PartnerAddress::create(['partner_id' => $this->partner->id, 'addrid' => 'A-'.$key,
                'name' => 'Address '.$key, 'price_list_id' => $this->lists[$key]->id, 'active' => $key !== 'inactive_address']);
            if ($key === 'whole') {
                $this->address = $address;
            }
        }
        PartnerAddress::create(['partner_id' => $this->partner->id, 'addrid' => 'A-duplicate', 'name' => 'Same list again',
            'price_list_id' => $this->lists['whole']->id]);
        PartnerAddress::create(['partner_id' => $this->foreignPartner->id, 'addrid' => 'FOREIGN', 'name' => 'Other address',
            'price_list_id' => $this->lists['foreign']->id]);
        $brand = Brand::create(['code' => 'HT', 'name' => 'HT']);
        $sheet = OrderSheetType::create(['code' => 'B', 'name' => 'Bags']);
        $group = ItemMainGroup::create(['code' => 'B01', 'name' => 'Bags']);
        $this->product = Product::create(['model_code' => 'MODEL-S27', 'name_hu' => 'Model', 'season_id' => $season->id,
            'brand_id' => $brand->id, 'order_sheet_type_id' => $sheet->id, 'item_main_group_id' => $group->id,
            'catalog_group_name_hu' => 'Bags', 'active' => true]);
        $color = Color::create(['product_id' => $this->product->id, 'code' => '01', 'name_hu' => 'BLACK', 'active' => true]);
        $size = Size::create(['code' => 'ONE', 'sort_order' => 1]);
        $this->sku = Sku::create(['product_id' => $this->product->id, 'color_id' => $color->id, 'size_id' => $size->id,
            'sku_code' => 'MODEL-S27-01-ONE', 'sku_name' => 'Model BLACK', 'type' => 'piece', 'active' => true]);
        $this->order = Order::create(['partner_id' => $this->partner->id, 'partner_address_id' => $this->address->id,
            'season_id' => $season->id, 'brand_id' => $brand->id, 'order_sheet_type_id' => $sheet->id,
            'reference_number' => 'SHARE-TEST', 'price_list_id' => $this->lists['whole']->id, 'currency_id' => $huf->id, 'status' => 'draft']);
        foreach (['whole' => 1000, 'retail' => 2500, 'dist' => 20, 'retail_dist' => 95.5, 'foreign' => 1] as $key => $price) {
            PriceListItem::create(['price_list_id' => $this->lists[$key]->id, 'season_id' => $season->id,
                'product_id' => $this->product->id, 'net_price' => $price]);
        }
        $this->user = PartnerUser::create(['partner_id' => $this->partner->id, 'role' => 'partner_admin', 'name' => 'Owner',
            'email' => 'share-owner@example.test', 'password' => 'password', 'active' => true]);
        $this->actingAs($this->user, 'partner');
    }

    private function page()
    {
        return Livewire::test(CatalogGroupOrder::class, ['order' => $this->order, 'catalogGroupName' => 'Bags']);
    }

    private function link(?int $listId = null): OrderShareLink
    {
        return OrderShareLink::create(['order_id' => $this->order->id, 'token' => str_repeat('a', 64),
            'price_list_id' => $listId, 'created_by_partner_user_id' => $this->user->id, 'expires_at' => now()->addDay()]);
    }

    public function test_choices_are_unique_active_wholesale_or_distributor_lists_of_this_partners_active_addresses(): void
    {
        $page = $this->page()->assertSet('sharePriceListId', $this->lists['whole']->id);
        $this->assertEqualsCanonicalizing([$this->lists['whole']->id, $this->lists['dist']->id],
            array_keys($page->instance()->sharePriceListOptions()));
    }

    public static function sharingPanelContexts(): array
    {
        return [['partner_admin', 'hu'], ['partner_admin', 'en'], ['address_user', 'hu'], ['address_user', 'en']];
    }

    #[DataProvider('sharingPanelContexts')]
    public function test_opening_and_reopening_the_share_panel_renders_translated_controls_and_only_allowed_options(string $role, string $locale): void
    {
        session(['partner.locale' => $locale]);
        $this->user->update(['role' => $role]);
        $this->user->addresses()->attach($this->address);
        $page = $this->page()->set('showSharePanel', true)->assertSuccessful();
        $this->assertRenderedSharePanel($page);
        $page->assertSeeHtml('<option value="'.$this->lists['whole']->id.'">');
        if ($role === 'partner_admin') {
            $page->assertSeeHtml('<option value="'.$this->lists['dist']->id.'">');
        } else {
            $page->assertDontSeeHtml('<option value="'.$this->lists['dist']->id.'">');
        }
        foreach (['retail', 'outlet', 'foreign', 'unassigned', 'inactive', 'inactive_address'] as $key) {
            $page->assertDontSeeHtml('<option value="'.$this->lists[$key]->id.'">');
        }
        $page->set('showSharePanel', false)->assertDontSeeHtml('wire:model="sharePriceListId"');
        $this->assertRenderedSharePanel($page->set('showSharePanel', true));
    }

    private function assertRenderedSharePanel($page): void
    {
        $page->assertSee(__('partner.share_price_list'))->assertSee(__('partner.share_select_price_list'))
            ->assertSee(__('partner.share_expires_in_days'))->assertSee(__('partner.generate_share_link'))
            ->assertDontSeeHtml('{{')->assertDontSeeHtml('@if')->assertDontSeeHtml('@error')
            ->assertDontSeeHtml('@php')->assertDontSeeHtml('sharePriceListOptions()');
    }

    public function test_open_share_panel_displays_generated_link_and_allows_revoking_it(): void
    {
        $page = $this->page()->set('showSharePanel', true)->call('generateShareLink')->assertHasNoErrors();
        $link = OrderShareLink::firstOrFail();
        $this->assertRenderedSharePanel($page);
        $page->assertSee(__('partner.generated_share_link'))->assertSee(__('partner.revoke_share_link'))
            ->assertSeeHtml($page->get('generatedShareUrl'));
        $this->assertTrue($link->isAvailable());
        $page->call('revokeShareLink', $link->id)->assertHasNoErrors();
        $this->assertNotNull($link->fresh()->revoked_at);
    }

    public function test_open_share_panel_without_eligible_lists_renders_the_notice_and_disables_creation(): void
    {
        PartnerAddress::where('partner_id', $this->partner->id)->update(['active' => false]);
        $page = $this->page()->set('showSharePanel', true)->assertSee(__('partner.share_price_list_no_options'));
        $this->assertRenderedSharePanel($page);
        $this->assertSame(1, preg_match('/<button\b[^>]*wire:click="generateShareLink"[^>]*>/s', $page->html(), $matches));
        $this->assertMatchesRegularExpression('/\bdisabled(?:[ =>])/', $matches[0]);
        $page->call('generateShareLink')->assertHasErrors(['sharePriceListId']);
        $this->assertDatabaseCount('order_share_links', 0);
    }

    public function test_address_user_cannot_select_or_share_a_list_of_an_unassigned_address_of_the_same_partner(): void
    {
        $this->user->update(['role' => 'address_user']);
        $this->user->addresses()->attach($this->address);
        $page = $this->page()->assertSet('sharePriceListId', $this->lists['whole']->id);
        $this->assertSame([$this->lists['whole']->id], array_keys($page->instance()->sharePriceListOptions()));
        $page->set('sharePriceListId', $this->lists['dist']->id)->call('generateShareLink')->assertHasErrors(['sharePriceListId']);
        $this->assertDatabaseCount('order_share_links', 0);
    }

    public function test_address_user_can_share_lists_of_their_assigned_addresses_but_not_another_partners_lists(): void
    {
        $this->user->update(['role' => 'address_user']);
        $this->user->addresses()->attach([$this->address->id,
            $this->partner->addresses()->where('price_list_id', $this->lists['dist']->id)->value('id'),
            $this->foreignPartner->addresses()->value('id')]);
        $page = $this->page();
        $this->assertEqualsCanonicalizing([$this->lists['whole']->id, $this->lists['dist']->id],
            array_keys($page->instance()->sharePriceListOptions()));
        $page->set('sharePriceListId', $this->lists['dist']->id)->call('generateShareLink')->assertHasNoErrors();
        $this->assertDatabaseHas('order_share_links', ['order_id' => $this->order->id, 'price_list_id' => $this->lists['dist']->id]);
    }

    public function test_address_user_default_does_not_use_an_order_list_from_an_unassigned_address(): void
    {
        $this->user->update(['role' => 'address_user']);
        $this->user->addresses()->attach($this->address);
        $this->order->update(['price_list_id' => $this->lists['dist']->id]);
        $this->page()->assertSet('sharePriceListId', $this->lists['whole']->id);
    }

    public function test_address_assignment_is_rechecked_at_share_creation(): void
    {
        $this->user->update(['role' => 'address_user']);
        $distAddress = $this->partner->addresses()->where('price_list_id', $this->lists['dist']->id)->firstOrFail();
        $this->user->addresses()->attach([$this->address->id, $distAddress->id]);
        $page = $this->page()->set('sharePriceListId', $this->lists['dist']->id);
        $this->user->addresses()->detach($distAddress);
        $page->call('generateShareLink')->assertHasErrors(['sharePriceListId']);
        $this->assertDatabaseCount('order_share_links', 0);
    }

    public function test_existing_address_user_link_cannot_expose_an_unassigned_addresses_price_list(): void
    {
        $this->user->update(['role' => 'address_user']);
        $this->user->addresses()->attach($this->address);
        $link = $this->link($this->lists['dist']->id);
        $page = $this->page();
        $this->assertSame(__('partner.share_price_list_unavailable'), $page->instance()->activeShareLinks()[0]['price_list']);
        auth('partner')->logout();
        Livewire::test(SharedCatalogGroupOrder::class, ['shareLink' => $link, 'catalogGroupName' => 'Bags'])->assertNotFound();
        $this->assertNull($link->fresh()->last_accessed_at);
    }

    public static function invalidAddressUserShares(): array
    {
        return [['detached'], ['inactive_address'], ['inactive_user'], ['lost_order_access']];
    }

    #[DataProvider('invalidAddressUserShares')]
    public function test_open_address_user_share_checks_the_creators_current_address_access(string $reason): void
    {
        $this->user->update(['role' => 'address_user']);
        $distAddress = $this->partner->addresses()->where('price_list_id', $this->lists['dist']->id)->firstOrFail();
        $this->user->addresses()->attach([$this->address->id, $distAddress->id]);
        $link = $this->link($this->lists['dist']->id);
        auth('partner')->logout();
        $page = Livewire::test(SharedCatalogGroupOrder::class, ['shareLink' => $link, 'catalogGroupName' => 'Bags'])->assertSuccessful();
        match ($reason) {
            'detached' => $this->user->addresses()->detach($distAddress),
            'inactive_address' => $distAddress->update(['active' => false]),
            'inactive_user' => $this->user->update(['active' => false]),
            'lost_order_access' => $this->user->addresses()->detach($this->address),
        };
        $page->call('savePieceQuantity', $this->sku->id, 5)->assertNotFound();
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_sales_rep_choices_follow_the_orders_partner_not_all_assigned_partners_or_the_users_own_partner(): void
    {
        $this->user->update(['role' => 'sales_rep']);
        $this->user->partners()->attach([$this->partner->id, $this->foreignPartner->id]);
        $this->assertArrayNotHasKey($this->lists['foreign']->id, $this->page()->instance()->sharePriceListOptions());
        $address = $this->foreignPartner->addresses()->firstOrFail();
        $this->order->update(['partner_id' => $this->foreignPartner->id, 'partner_address_id' => $address->id, 'price_list_id' => $this->lists['foreign']->id]);
        $this->assertSame([$this->lists['foreign']->id], array_keys($this->page()->instance()->sharePriceListOptions()));
    }

    public static function forbiddenLists(): array
    {
        return array_map(fn ($key) => [$key], ['retail', 'outlet', 'foreign', 'unassigned', 'inactive', 'inactive_address', 'missing']);
    }

    #[DataProvider('forbiddenLists')]
    public function test_forged_or_disallowed_list_is_rejected_on_the_server(string $key): void
    {
        $id = $key === 'missing' ? 999999 : $this->lists[$key]->id;
        $this->page()->set('sharePriceListId', $id)->call('generateShareLink')->assertHasErrors(['sharePriceListId']);
        $this->assertDatabaseCount('order_share_links', 0);
    }

    public function test_default_selection_never_falls_back_to_an_ineligible_order_price_list(): void
    {
        $this->order->update(['price_list_id' => $this->lists['outlet']->id]);
        $page = $this->page();
        $this->assertContains($page->get('sharePriceListId'), [$this->lists['whole']->id, $this->lists['dist']->id]);
        PartnerAddress::where('partner_id', $this->partner->id)->update(['active' => false]);
        $this->page()->assertSet('sharePriceListId', null)->call('generateShareLink')->assertHasErrors(['sharePriceListId']);
    }

    public function test_assignment_is_rechecked_when_generating_after_the_form_was_opened(): void
    {
        $page = $this->page();
        PartnerAddress::where('partner_id', $this->partner->id)->where('price_list_id', $this->lists['whole']->id)->update(['price_list_id' => null]);
        $page->call('generateShareLink')->assertHasErrors(['sharePriceListId']);
        $this->assertDatabaseCount('order_share_links', 0);
    }

    public function test_shared_prices_and_retail_list_come_from_the_chosen_list_but_saved_order_prices_do_not_change(): void
    {
        $otherSeason = Season::create(['code' => 'S28', 'name' => 'Next']);
        PriceListItem::create(['price_list_id' => $this->lists['retail_dist']->id, 'season_id' => $otherSeason->id,
            'product_id' => $this->product->id, 'net_price' => 9999]);
        $link = $this->link($this->lists['dist']->id);
        auth('partner')->logout();
        $page = Livewire::test(SharedCatalogGroupOrder::class, ['shareLink' => $link, 'catalogGroupName' => 'Bags'])
            ->assertSuccessful()->assertSet('displayPriceListId', $this->lists['dist']->id)
            ->assertSet('retailPriceListId', $this->lists['retail_dist']->id)->assertSet('displayCurrencySymbol', '€');
        $product = collect($page->viewData('matrixGroups'))->flatMap(fn ($group) => $group['products'])->first();
        $this->assertSame(20.0, $product['price']);
        $this->assertSame(95.5, $product['retail_price']);
        $this->assertSame([], $page->instance()->sharePriceListOptions());
        $page->call('savePieceQuantity', $this->sku->id, 2);
        $item = OrderItem::where('order_id', $this->order->id)->firstOrFail();
        $this->assertEquals(1000, $item->unit_price);
        $this->assertEquals(2000, $item->line_total);
    }

    public function test_legacy_null_price_list_can_use_the_order_list_only_when_still_allowed(): void
    {
        $link = $this->link();
        $this->assertTrue($link->isAvailable());
        PartnerAddress::where('partner_id', $this->partner->id)->where('price_list_id', $this->lists['whole']->id)->update(['price_list_id' => null]);
        $this->assertFalse($link->isAvailable());
    }

    public function test_shared_retail_price_follows_the_selected_lists_current_link_and_missing_link_keeps_zero_behavior(): void
    {
        $link = $this->link($this->lists['dist']->id);
        auth('partner')->logout();
        $page = Livewire::test(SharedCatalogGroupOrder::class, ['shareLink' => $link, 'catalogGroupName' => 'Bags']);
        $retail = PriceList::create(['code' => 'NEW-RETAIL', 'name' => 'New retail', 'type' => 'retail',
            'currency_id' => $this->lists['dist']->currency_id, 'active' => true]);
        PriceListItem::create(['price_list_id' => $retail->id, 'season_id' => $this->order->season_id,
            'product_id' => $this->product->id, 'net_price' => 123.95]);
        $this->lists['dist']->update(['retail_price_list_id' => $retail->id]);
        $page->set('showAllCatalogGroups', true)->assertSet('retailPriceListId', $retail->id);
        $product = collect($page->viewData('matrixGroups'))->flatMap(fn ($group) => $group['products'])->first();
        $this->assertSame(123.95, $product['retail_price']);
        $this->lists['dist']->update(['retail_price_list_id' => null]);
        $page->set('showAllCatalogGroups', false)->assertSet('retailPriceListId', null);
        $product = collect($page->viewData('matrixGroups'))->flatMap(fn ($group) => $group['products'])->first();
        $this->assertSame(0, $product['retail_price']);
    }

    public static function invalidCreators(): array
    {
        return [['disabled'], ['different_partner']];
    }

    #[DataProvider('invalidCreators')]
    public function test_disabling_the_creator_or_removing_order_access_blocks_share_creation(string $reason): void
    {
        $page = $this->page();
        $this->user->update($reason === 'disabled'
            ? ['active' => false]
            : ['partner_id' => $this->foreignPartner->id]);
        $page->call('generateShareLink')->assertForbidden();
        $this->assertDatabaseCount('order_share_links', 0);
    }

    public function test_invalid_legacy_share_does_not_reveal_prices_or_record_access_and_owner_can_still_revoke_it(): void
    {
        $link = $this->link($this->lists['foreign']->id);
        $page = $this->page();
        $links = $page->instance()->activeShareLinks();
        $this->assertSame(__('partner.share_price_list_unavailable'), $links[0]['price_list']);
        auth('partner')->logout();
        Livewire::test(SharedCatalogGroupOrder::class, ['shareLink' => $link, 'catalogGroupName' => 'Bags'])->assertNotFound();
        $this->assertNull($link->fresh()->last_accessed_at);
        $this->actingAs($this->user, 'partner');
        $this->page()->call('revokeShareLink', $link->id);
        $this->assertNotNull($link->fresh()->revoked_at);
    }

    public static function invalidatedShares(): array
    {
        return [['assignment'], ['inactive'], ['type'], ['revoked'], ['expired'], ['submitted']];
    }

    #[DataProvider('invalidatedShares')]
    public function test_existing_open_shared_page_rechecks_access_on_the_next_request(string $reason): void
    {
        $link = $this->link($this->lists['whole']->id);
        auth('partner')->logout();
        $page = Livewire::test(SharedCatalogGroupOrder::class, ['shareLink' => $link, 'catalogGroupName' => 'Bags'])->assertSuccessful();
        match ($reason) {
            'assignment' => PartnerAddress::where('partner_id', $this->partner->id)->where('price_list_id', $this->lists['whole']->id)->update(['price_list_id' => null]),
            'inactive' => $this->lists['whole']->update(['active' => false]),
            'type' => $this->lists['whole']->update(['type' => 'retail']),
            'revoked' => $link->update(['revoked_at' => now()]),
            'expired' => $link->update(['expires_at' => now()->subMinute()]),
            'submitted' => $this->order->update(['status' => 'submitted']),
        };
        $page->call('savePieceQuantity', $this->sku->id, 5)->assertNotFound();
        $this->assertDatabaseCount('order_items', 0);
    }

    public static function lockedPricingProperties(): array
    {
        return [['displayPriceListId'], ['retailPriceListId'], ['displayCurrencySymbol'], ['sharedMode']];
    }

    #[DataProvider('lockedPricingProperties')]
    public function test_shared_display_pricing_and_access_mode_cannot_be_changed_from_the_browser(string $property): void
    {
        $link = $this->link($this->lists['whole']->id);
        auth('partner')->logout();
        $page = Livewire::test(SharedCatalogGroupOrder::class, ['shareLink' => $link, 'catalogGroupName' => 'Bags']);
        $value = match ($property) {
            'sharedMode' => false, 'displayCurrencySymbol' => 'XXX', default => $this->lists['foreign']->id
        };
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set($property, $value);
    }
}

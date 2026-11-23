<?php

namespace Tests\Feature\Partner;

use App\Filament\Pages\PartnerPortalAvailability;
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
use App\Models\PartnerPortalSetting;
use App\Models\PartnerUser;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Season;
use App\Models\Size;
use App\Models\Sku;
use App\Models\User;
use App\Services\Partner\PartnerPortalAvailabilityService;
use App\Services\Partner\PartnerSeasonContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartnerPortalAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Language::create(['code' => 'HU', 'name' => 'Magyar', 'active' => true]);
        Language::create(['code' => 'EN', 'name' => 'English', 'active' => true]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user, 'web');

        return $user;
    }

    private function close(string $message = 'Karbantartás\nVárható nyitás: 14:00'): void
    {
        PartnerPortalSetting::current()->update(['is_enabled' => false, 'message' => $message]);
    }

    private function snapshot(TestResponse $response): string
    {
        $response->assertOk();
        $this->assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $match));

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function updateComponent(string $snapshot, string $method, array $params = [], array $updates = []): TestResponse
    {
        return $this->postJson('/livewire/update', ['_token' => csrf_token(), 'components' => [[
            'snapshot' => $snapshot, 'updates' => $updates, 'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
        ]]], ['X-Livewire' => 'true']);
    }

    private function orderFixture(): array
    {
        $season = Season::create(['code' => 'W27', 'name' => 'Winter', 'active' => true, 'visible_to_partners' => true]);
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'symbol' => 'Ft', 'active' => true]);
        $list = PriceList::create(['code' => 'WHOLE', 'name' => 'Wholesale', 'type' => 'wholesale', 'currency_id' => $currency->id, 'active' => true]);
        $partner = Partner::create(['erp_partner_code' => 'P1', 'name' => 'Partner', 'active' => true]);
        $address = PartnerAddress::create(['partner_id' => $partner->id, 'addrid' => 'A1', 'name' => 'Cím',
            'language_id' => Language::where('code', 'HU')->value('id'), 'price_list_id' => $list->id, 'currency_id' => $currency->id, 'active' => true]);
        $brand = Brand::create(['code' => 'HT', 'name' => 'Márka', 'active' => true]);
        $sheet = OrderSheetType::create(['code' => 'B', 'name' => 'Táskák', 'active' => true]);
        $type = OrderType::where('code', 'VRELO')->firstOrFail();
        $address->brands()->attach($brand);
        $address->orderSheetTypes()->attach($sheet);
        $address->orderTypes()->attach($type);
        $user = PartnerUser::create(['partner_id' => $partner->id, 'name' => 'Partner', 'email' => 'partner@example.test',
            'password' => 'password', 'role' => 'partner_admin', 'active' => true]);
        $user->partners()->attach($partner);
        $user->addresses()->attach($address);
        $group = ItemMainGroup::create(['code' => 'B01', 'name' => 'Bags', 'active' => true]);
        $product = Product::create(['visible_to_partners' => true, 'model_code' => 'MODEL', 'name_hu' => 'Product', 'season_id' => $season->id,
            'item_main_group_id' => $group->id, 'brand_id' => $brand->id, 'order_sheet_type_id' => $sheet->id, 'catalog_group_name_hu' => 'Bags', 'active' => true]);
        $color = Color::create(['product_id' => $product->id, 'code' => '01', 'name_hu' => 'BLACK', 'active' => true]);
        $size = Size::create(['code' => 'ONE', 'name_hu' => 'ONE', 'active' => true]);
        $sku = Sku::create(['product_id' => $product->id, 'color_id' => $color->id, 'size_id' => $size->id,
            'sku_code' => 'SKU01', 'sku_name' => 'Product BLACK', 'type' => 'piece', 'active' => true]);
        $order = Order::create(['season_id' => $season->id, 'partner_id' => $partner->id, 'partner_address_id' => $address->id,
            'brand_id' => $brand->id, 'order_sheet_type_id' => $sheet->id, 'reference_number' => 'REF01', 'order_type_id' => $type->id,
            'price_list_id' => $list->id, 'currency_id' => $currency->id, 'status' => 'draft']);
        OrderItem::create(['order_id' => $order->id, 'sku_id' => $sku->id, 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200]);
        PriceListItem::create(['price_list_id' => $list->id, 'season_id' => $season->id, 'product_id' => $product->id, 'net_price' => 100]);

        return compact('season', 'user', 'order', 'sku');
    }

    public function test_default_is_open_and_permissions_exist_without_granting_them_to_every_admin(): void
    {
        $settings = PartnerPortalSetting::current();
        $this->assertTrue($settings->is_enabled);
        $this->assertSame(0, $settings->revision);
        $this->get('/partner/login')->assertOk();
        $this->assertDatabaseHas('permissions', ['name' => 'View:PartnerPortalAvailability', 'guard_name' => 'web']);
        $this->assertDatabaseHas('permissions', ['name' => 'Update:PartnerPortalAvailability', 'guard_name' => 'web']);
        $this->assertDatabaseCount('partner_portal_settings', 1);
    }

    public function test_admin_can_close_edit_message_and_reopen_with_confirmation(): void
    {
        $admin = $this->admin();
        $page = Livewire::test(PartnerPortalAvailability::class)->fillForm(['is_enabled' => false, 'message' => "Karbantartás\nNyitás 14:00"]);
        $this->assertTrue(PartnerPortalSetting::current()->is_enabled);
        $page->mountAction('saveAvailability');
        $this->assertTrue($page->instance()->getMountedAction()->isConfirmationRequired());
        $page->callMountedAction()->assertHasNoFormErrors();
        $this->assertFalse(PartnerPortalSetting::current()->is_enabled);
        $this->assertSame($admin->id, PartnerPortalSetting::current()->updated_by);
        $this->get('/partner/login')->assertStatus(503)->assertSee('Nyitás 14:00');
        $page->fillForm(['is_enabled' => true])->mountAction('saveAvailability')->callMountedAction()->assertHasNoFormErrors();
        $this->assertTrue(PartnerPortalSetting::current()->is_enabled);
        $this->assertSame(2, PartnerPortalSetting::current()->revision);
        $this->get('/partner/login')->assertOk();
    }

    public function test_closed_public_and_authenticated_routes_show_escaped_plain_text_and_no_cache_headers(): void
    {
        $message = "Szerviz\n<script>alert('x')</script>";
        $this->close($message);
        foreach (['/partner/login', '/partner/forgot-password', '/partner/reset-password/token', '/partner/select-season',
            '/partner/orders/select', '/partner/orders/1/overview', '/partner/order-coverage'] as $url) {
            $this->get($url)->assertStatus(503)->assertSee($message)->assertDontSee('<script>alert', false)
                ->assertHeader('Retry-After', '60')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        $this->assertStringContainsString('no-store', $this->get('/partner/login')->headers->get('Cache-Control'));
        $this->get('/admin/login')->assertOk();
        $this->get('/up')->assertOk();
    }

    public function test_closed_page_uses_english_chrome_but_does_not_translate_the_custom_message(): void
    {
        $this->close('Saját üzenet');
        $this->withHeaders(['Accept-Language' => 'en'])->get('/partner/login')->assertStatus(503)
            ->assertSee('The partner portal is temporarily unavailable')->assertSee('Try again')->assertSee('Saját üzenet');
    }

    public function test_a_login_page_opened_before_closing_cannot_log_in_via_livewire(): void
    {
        $fixture = $this->orderFixture();
        $snapshot = $this->snapshot($this->get('/partner/login'));
        $this->close('Lezárva');
        $updates = ['email' => $fixture['user']->email, 'password' => 'password', 'seasonId' => $fixture['season']->id];
        $this->updateComponent($snapshot, 'login', updates: $updates)->assertStatus(503)->assertSee('Lezárva');
        $this->assertGuest('partner');
        PartnerPortalSetting::current()->update(['is_enabled' => true]);
        $this->updateComponent($snapshot, 'login', updates: $updates)->assertOk();
        $this->assertAuthenticatedAs($fixture['user'], 'partner');
    }

    public function test_open_order_livewire_writes_are_blocked_without_logging_out_and_resume_after_reopening(): void
    {
        $fixture = $this->orderFixture();
        $this->actingAs($fixture['user'], 'partner');
        session([PartnerSeasonContext::SESSION_KEY => $fixture['season']->id]);
        $url = route('partner.catalog-group-order', ['order' => $fixture['order'], 'catalogGroupName' => 'Bags']);
        $snapshot = $this->snapshot($this->get($url));
        $this->close('Rendelésfelvétel szünetel');
        $this->get($url)->assertStatus(503);
        $this->updateComponent($snapshot, 'savePieceQuantity', [$fixture['sku']->id, 9])->assertStatus(503);
        $this->assertDatabaseHas('order_items', ['order_id' => $fixture['order']->id, 'quantity' => 2]);
        $this->assertAuthenticatedAs($fixture['user'], 'partner');
        PartnerPortalSetting::current()->update(['is_enabled' => true]);
        $this->updateComponent($snapshot, 'savePieceQuantity', [$fixture['sku']->id, 9])->assertOk();
        $this->assertDatabaseHas('order_items', ['order_id' => $fixture['order']->id, 'quantity' => 9]);
    }

    public function test_shared_order_pages_and_their_existing_livewire_actions_are_blocked_too(): void
    {
        $fixture = $this->orderFixture();
        $share = OrderShareLink::create(['order_id' => $fixture['order']->id, 'token' => str_repeat('a', 64),
            'price_list_id' => $fixture['order']->price_list_id, 'expires_at' => now()->addDay()]);
        $url = route('partner.shared-catalog-group-order', ['shareLink' => $share->token, 'catalogGroupName' => 'Bags']);
        $snapshot = $this->snapshot($this->get($url));
        $this->close();
        $this->get($url)->assertStatus(503);
        $this->updateComponent($snapshot, 'savePieceQuantity', [$fixture['sku']->id, 7])->assertStatus(503);
        $this->assertDatabaseHas('order_items', ['order_id' => $fixture['order']->id, 'quantity' => 2]);
    }

    public function test_admin_livewire_is_not_blocked_and_partner_logout_remains_available(): void
    {
        $this->admin();
        $snapshot = $this->snapshot($this->get(PartnerPortalAvailability::getUrl()));
        $this->close();
        $this->updateComponent($snapshot, 'mountAction', ['saveAvailability'])->assertOk();
        $this->post('/partner/logout')->assertRedirect(route('partner.login'));
        $this->assertFalse(PartnerPortalSetting::current()->is_enabled);
    }

    public function test_read_only_user_cannot_change_the_setting_and_permission_revocation_applies_on_next_request(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('View:PartnerPortalAvailability', 'web'));
        $this->actingAs($user, 'web');
        $page = Livewire::test(PartnerPortalAvailability::class)->assertSuccessful()->assertActionHidden('saveAvailability');
        try {
            app(PartnerPortalAvailabilityService::class)->update(['is_enabled' => false, 'message' => 'Nem'], 0);
            $this->fail('Read-only user changed availability.');
        } catch (AuthorizationException) {
            $this->assertTrue(PartnerPortalSetting::current()->is_enabled);
        }
        $user->revokePermissionTo('View:PartnerPortalAvailability');
        $page->call('$refresh')->assertForbidden();
    }

    public function test_operator_needs_both_view_and_update_permissions_and_no_permission_hides_navigation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->assertFalse(PartnerPortalAvailability::canAccess());
        Livewire::test(PartnerPortalAvailability::class)->assertForbidden();
        foreach (['View:PartnerPortalAvailability', 'Update:PartnerPortalAvailability'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Livewire::test(PartnerPortalAvailability::class)->fillForm(['is_enabled' => false, 'message' => 'Zárva'])
            ->mountAction('saveAvailability')->callMountedAction()->assertHasNoFormErrors();
        $this->assertFalse(PartnerPortalSetting::current()->is_enabled);
    }

    public function test_empty_or_too_long_messages_are_rejected_on_closing_and_cancel_does_not_save(): void
    {
        $this->admin();
        foreach (['', '   ', str_repeat('a', 4001)] as $message) {
            try {
                app(PartnerPortalAvailabilityService::class)->update(['is_enabled' => false, 'message' => $message], 0);
                $this->fail('Invalid closure message accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('message', $exception->errors());
                $this->assertTrue(PartnerPortalSetting::current()->is_enabled);
            }
        }
        Livewire::test(PartnerPortalAvailability::class)->fillForm(['is_enabled' => false, 'message' => 'Mégse'])
            ->mountAction('saveAvailability')->call('unmountAction');
        $this->assertTrue(PartnerPortalSetting::current()->is_enabled);
    }

    public function test_concurrent_settings_are_not_silently_overwritten(): void
    {
        $this->admin();
        $service = app(PartnerPortalAvailabilityService::class);
        $service->update(['is_enabled' => false, 'message' => 'Első admin üzenete'], 0);
        try {
            $service->update(['is_enabled' => true, 'message' => 'Régi lap'], 0);
            $this->fail('Stale settings overwrote newer state.');
        } catch (ValidationException) {
            $this->assertFalse(PartnerPortalSetting::current()->is_enabled);
            $this->assertSame('Első admin üzenete', PartnerPortalSetting::current()->message);
        }
    }

    public function test_stale_admin_page_displays_a_validation_error_and_keeps_the_current_setting(): void
    {
        $this->admin();
        $page = Livewire::test(PartnerPortalAvailability::class)->fillForm(['is_enabled' => true, 'message' => 'Régi lap']);
        app(PartnerPortalAvailabilityService::class)->update(['is_enabled' => false, 'message' => 'Új lezárás'], 0);
        $page->mountAction('saveAvailability')->callMountedAction()->assertHasFormErrors(['message'], form: 'form');
        $this->assertFalse(PartnerPortalSetting::current()->is_enabled);
        $this->assertSame('Új lezárás', PartnerPortalSetting::current()->message);
    }

    public function test_admin_form_cannot_close_with_an_empty_message(): void
    {
        $this->admin();
        Livewire::test(PartnerPortalAvailability::class)->fillForm(['is_enabled' => false, 'message' => ''])
            ->mountAction('saveAvailability')->callMountedAction()->assertHasFormErrors(['message'], form: 'form')
            ->assertNotified('A beállítások nem menthetők');
        $this->assertTrue(PartnerPortalSetting::current()->is_enabled);
    }
}

<?php

namespace Tests\Feature\Partner;

use App\Exports\PartnerOrderMatrixExport;
use App\Exports\PartnerOrderSummaryMatrixExport;
use App\Filament\Resources\Seasons\Pages\CreateSeason;
use App\Filament\Resources\Seasons\Pages\EditSeason;
use App\Livewire\Partner\CatalogGroupOrder;
use App\Livewire\Partner\Dashboard;
use App\Livewire\Partner\OrderSelector;
use App\Livewire\Partner\OrderSummary;
use App\Livewire\Partner\PartnerOrderCoverage;
use App\Livewire\Partner\SalesRepOrderSummary;
use App\Livewire\Partner\SeasonShow;
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
use App\Models\Partner;
use App\Models\PartnerAddress;
use App\Models\PartnerUser;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\Season;
use App\Models\Size;
use App\Models\SizeRange;
use App\Models\Sku;
use App\Models\Translation;
use App\Models\User;
use App\Services\PartnerOrderCoverageService;
use App\Services\Pricing\CostCalculationAuthorization;
use App\Services\Pricing\PricingProjectProductService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PartnerSeasonVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Season $visible;

    private Season $hidden;

    private Season $historical;

    private PartnerUser $user;

    private PartnerAddress $address;

    private Brand $brand;

    private OrderSheetType $type;

    private PriceList $list;

    private array $products = [];

    private array $orders = [];

    private array $skus = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        $this->visible = Season::create(['code' => 'S27', 'name' => 'Visible S27', 'active' => true, 'visible_to_partners' => true]);
        $this->hidden = Season::create(['code' => 'W27', 'name' => 'Hidden W27', 'active' => true]);
        $this->historical = Season::create(['code' => 'W26', 'name' => 'Historical W26', 'active' => false, 'visible_to_partners' => true]);
        $language = Language::create(['code' => 'HU', 'name' => 'Magyar', 'active' => true]);
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'symbol' => 'Ft', 'active' => true]);
        $partner = Partner::create(['erp_partner_code' => 'P1', 'name' => 'Partner', 'language_id' => $language->id, 'active' => true]);
        $this->list = PriceList::create(['code' => 'WHOLE_HU', 'name' => 'Nagyker', 'type' => 'wholesale', 'currency_id' => $currency->id, 'active' => true]);
        $this->address = PartnerAddress::create(['partner_id' => $partner->id, 'addrid' => 'A1', 'name' => 'Cím', 'price_list_id' => $this->list->id,
            'language_id' => $language->id, 'currency_id' => $currency->id, 'active' => true]);
        $this->user = PartnerUser::create(['partner_id' => $partner->id, 'role' => 'partner_admin', 'name' => 'Partner',
            'email' => 'partner@example.test', 'password' => 'password', 'active' => true]);
        $this->actingAs($this->user, 'partner');
        $this->brand = Brand::create(['code' => 'HT', 'name' => 'HT', 'active' => true]);
        $this->type = OrderSheetType::create(['code' => 'B', 'name' => 'Táska', 'active' => true]);
        $this->address->brands()->attach($this->brand);
        $this->address->orderSheetTypes()->attach($this->type);
        $group = ItemMainGroup::create(['code' => 'BAG', 'name' => 'Táskák', 'active' => true]);
        $range = SizeRange::create(['code' => 'ONE', 'name_hu' => 'ONE', 'active' => true]);
        $size = Size::create(['code' => 'ONE', 'name_hu' => 'ONE', 'active' => true]);
        foreach (['visible', 'hidden', 'historical'] as $kind) {
            $season = $this->{$kind};
            $product = Product::create(['season_id' => $season->id, 'model_code' => 'B01'.$season->code, 'name_hu' => 'Model '.$kind,
                'item_main_group_id' => $group->id, 'size_range_id' => $range->id, 'brand_id' => $this->brand->id,
                'order_sheet_type_id' => $this->type->id, 'catalog_group_name_hu' => 'Táskák', 'active' => true]);
            $color = Color::create(['product_id' => $product->id, 'code' => 'BLACK', 'name_hu' => 'Fekete', 'active' => true]);
            $sku = Sku::create(['product_id' => $product->id, 'color_id' => $color->id, 'size_id' => $size->id,
                'sku_code' => $product->model_code.'-BLACK', 'sku_name' => $product->name_hu, 'type' => 'piece', 'active' => true]);
            $order = Order::create(['partner_id' => $partner->id, 'partner_address_id' => $this->address->id,
                'season_id' => $season->id, 'brand_id' => $this->brand->id, 'order_sheet_type_id' => $this->type->id,
                'reference_number' => 'REF-'.$kind, 'price_list_id' => $this->list->id, 'currency_id' => $currency->id, 'status' => 'draft']);
            PriceListItem::create(['season_id' => $season->id, 'price_list_id' => $this->list->id, 'product_id' => $product->id, 'net_price' => 100]);
            OrderItem::create(['order_id' => $order->id, 'sku_id' => $sku->id, 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200]);
            $this->products[$kind] = $product;
            $this->orders[$kind] = $order;
            $this->skus[$kind] = $sku;
        }
    }

    private function share(string $kind): OrderShareLink
    {
        return OrderShareLink::create(['order_id' => $this->orders[$kind]->id, 'token' => str_repeat($kind === 'hidden' ? 'a' : 'b', 64),
            'price_list_id' => $this->list->id, 'expires_at' => now()->addDay(), 'created_by_partner_user_id' => $this->user->id]);
    }

    #[DataProvider('commonNameLocales')]
    public function test_product_color_and_catalog_group_names_are_shared_across_interface_languages(string $locale): void
    {
        session(['partner.locale' => $locale]);
        $this->address->language->update(['code' => strtoupper($locale)]);
        Livewire::test(CatalogGroupOrder::class, ['order' => $this->orders['visible'], 'catalogGroupName' => 'Táskák'])
            ->assertSuccessful()->assertSee('Model visible')->assertSee('Fekete')
            ->set('modelSearch', 'Model visible')->call('jumpToFirstModelSearchResult')
            ->assertSet('modelSearchResults.0.model_code', $this->products['visible']->model_code);
        Livewire::test(OrderSummary::class, ['season' => $this->visible, 'brand' => $this->brand, 'orderSheetType' => $this->type])
            ->assertSuccessful()->assertSee('Model visible')->assertSee('Fekete')->assertSee('Táskák');
        Livewire::test(SharedCatalogGroupOrder::class, ['shareLink' => $this->share('visible'), 'catalogGroupName' => 'Táskák'])
            ->assertSuccessful()->assertSee('Model visible')->assertSee('Fekete');
        session(['partner_order_selector.season_id' => $this->visible->id, 'partner_order_selector.brand_id' => $this->brand->id,
            'partner_order_selector.partner_address_id' => $this->address->id, 'partner_order_selector.order_sheet_type_id' => $this->type->id,
            'partner_order_selector.order_id' => $this->orders['visible']->id]);
        $selector = Livewire::test(OrderSelector::class)->assertSuccessful();
        $this->assertSame(['Táskák'], $selector->instance()->catalogGroups->pluck('name')->all());
    }

    public static function commonNameLocales(): array
    {
        return ['Hungarian' => ['hu'], 'English' => ['en']];
    }

    public function test_price_list_names_are_translated_in_partner_sharing_and_both_order_exports(): void
    {
        $en = Language::create(['code' => 'EN', 'name' => 'English', 'active' => true]);
        $this->address->update(['language_id' => $en->id]);
        session(['partner.locale' => 'en', 'partner.language_id' => $en->id]);
        Translation::create(['entity' => 'price_list', 'entity_code' => $this->list->code,
            'field' => 'name', 'language_id' => $en->id, 'value' => 'English wholesale']);
        $order = $this->orders['visible']->fresh();
        $page = Livewire::test(CatalogGroupOrder::class, ['order' => $order, 'catalogGroupName' => 'Táskák'])->assertSuccessful();
        $this->assertStringContainsString('English wholesale', $page->instance()->sharePriceListOptions()[$this->list->id]);
        foreach ([new PartnerOrderMatrixExport($order), new PartnerOrderSummaryMatrixExport(new Collection([$order]))] as $export) {
            $cells = collect($export->array())->flatten()->all();
            $this->assertContains('English wholesale', $cells);
            $this->assertNotContains('Nagyker', $cells);
        }
    }

    public function test_new_seasons_start_hidden_even_when_active(): void
    {
        $this->assertFalse($this->hidden->visible_to_partners);
        $this->assertFalse($this->hidden->fresh()->visible_to_partners);
        $id = DB::table('seasons')->insertGetId(['code' => 'S28', 'name' => 'New', 'active' => true]);
        $this->assertFalse(Season::findOrFail($id)->visible_to_partners);
    }

    public function test_migration_preserves_existing_active_and_historical_seasons(): void
    {
        $migration = require database_path('migrations/2026_10_28_120000_add_partner_visibility_to_seasons_table.php');
        $migration->down();
        $migration->up();
        $this->assertTrue($this->visible->fresh()->visible_to_partners);
        $this->assertTrue($this->historical->fresh()->visible_to_partners);
        $new = Season::create(['code' => 'S28', 'name' => 'New', 'active' => true]);
        $this->assertFalse($new->fresh()->visible_to_partners);
    }

    public function test_partner_lists_hide_the_new_season_but_preserve_historical_access(): void
    {
        Livewire::test(Dashboard::class)->assertSee('Visible S27')->assertDontSee('Hidden W27');
        $page = Livewire::test(OrderSelector::class);
        $ids = $page->instance()->seasons->modelKeys();
        $this->assertContains($this->visible->id, $ids);
        $this->assertContains($this->historical->id, $ids);
        $this->assertNotContains($this->hidden->id, $ids);
        $this->assertEqualsCanonicalizing([$this->orders['visible']->id, $this->orders['historical']->id],
            $page->instance()->accessibleOrders->modelKeys());
        Livewire::test(SeasonShow::class, ['season' => $this->historical])->assertSuccessful();
    }

    #[DataProvider('roles')]
    public function test_visibility_applies_to_every_partner_role(string $role): void
    {
        $this->user->update(['role' => $role]);
        $this->user->addresses()->sync([$this->address->id]);
        $this->user->partners()->sync([$this->address->partner_id]);
        $this->assertFalse($this->user->canAccessOrder($this->orders['hidden']));
        $this->assertTrue($this->user->canAccessOrder($this->orders['visible']));
        $this->assertTrue($this->user->canAccessOrder($this->orders['historical']));
    }

    public static function roles(): array
    {
        return [['partner_admin'], ['address_user'], ['sales_rep']];
    }

    #[DataProvider('hiddenRoutes')]
    public function test_hidden_season_cannot_be_reached_through_direct_urls(string $page): void
    {
        $season = $this->hidden->id;
        $address = $this->address->id;
        $brand = $this->brand->id;
        $type = $this->type->id;
        $url = match ($page) {
            'season' => "/partner/seasons/$season",
            'address' => "/partner/seasons/$season/addresses/$address",
            'brand' => "/partner/seasons/$season/addresses/$address/brands/$brand",
            'products' => "/partner/seasons/$season/addresses/$address/brands/$brand/types/$type",
            'order' => '/partner/orders/'.$this->orders['hidden']->id.'/catalog-groups/Táskák',
            'summary' => "/partner/orders/summary/$season/$brand/$type",
        };
        $this->hidden->update(['visible_to_partners' => true]);
        $response = $this->get($url);
        $page === 'products' ? $response->assertRedirect(route('partner.orders.select')) : $response->assertOk();
        $this->hidden->update(['visible_to_partners' => false]);
        $this->get($url)->assertNotFound();
    }

    public static function hiddenRoutes(): array
    {
        return [['season'], ['address'], ['brand'], ['products'], ['order'], ['summary']];
    }

    public function test_forged_selector_season_cannot_create_an_order(): void
    {
        $count = Order::count();
        Livewire::test(OrderSelector::class)->set('seasonId', $this->hidden->id)->assertNotFound();
        $this->assertSame($count, Order::count());
    }

    public function test_stale_session_selection_of_a_hidden_season_is_cleared(): void
    {
        session(['partner_order_selector.season_id' => $this->hidden->id, 'partner_order_selector.partner_address_id' => $this->address->id,
            'partner_order_selector.order_id' => $this->orders['hidden']->id]);
        $page = Livewire::test(OrderSelector::class);
        $this->assertNotSame($this->hidden->id, $page->instance()->seasonId);
        $this->assertNull($page->instance()->selectedOrderId);
        $this->assertNotSame($this->hidden->id, session('partner_order_selector.season_id'));
    }

    public function test_hiding_an_open_order_blocks_followup_requests_without_changing_quantities(): void
    {
        $page = Livewire::test(CatalogGroupOrder::class, ['order' => $this->orders['visible'], 'catalogGroupName' => 'Táskák']);
        $this->visible->update(['visible_to_partners' => false]);
        $page->call('savePieceQuantity', $this->skus['visible']->id, 99)->assertNotFound();
        $this->assertSame(2, OrderItem::where('order_id', $this->orders['visible']->id)->value('quantity'));
    }

    public function test_hiding_an_open_summary_blocks_its_export(): void
    {
        $page = Livewire::test(OrderSummary::class, ['season' => $this->visible, 'brand' => $this->brand, 'orderSheetType' => $this->type]);
        $this->visible->update(['visible_to_partners' => false]);
        $page->call('exportExcel')->assertNotFound()->assertNoFileDownloaded();
    }

    public function test_current_order_cannot_be_used_to_request_hidden_product_images(): void
    {
        Livewire::test(CatalogGroupOrder::class, ['order' => $this->orders['visible'], 'catalogGroupName' => 'Táskák'])
            ->call('openProductImages', $this->products['hidden']->id)->assertSet('showImageModal', false);
    }

    public function test_shared_link_is_unavailable_for_hidden_season_and_does_not_record_access(): void
    {
        $link = $this->share('hidden');
        $this->assertFalse($link->isAvailable());
        auth('partner')->logout();
        $this->get('/partner/shared-orders/'.$link->token.'/catalog-groups/Táskák')->assertNotFound();
        $this->assertNull($link->fresh()->last_accessed_at);
    }

    public function test_hiding_an_open_shared_page_blocks_the_next_request(): void
    {
        $link = $this->share('visible');
        auth('partner')->logout();
        $page = Livewire::test(SharedCatalogGroupOrder::class, ['shareLink' => $link, 'catalogGroupName' => 'Táskák'])->assertSuccessful();
        $this->visible->update(['visible_to_partners' => false]);
        $page->call('savePieceQuantity', $this->skus['visible']->id, 99)->assertNotFound();
        $this->assertSame(2, OrderItem::where('order_id', $this->orders['visible']->id)->value('quantity'));
    }

    public function test_import_header_cannot_target_a_hidden_season_by_code_or_name(): void
    {
        $probe = new class extends CatalogGroupOrder
        {
            public function resolveHeader(array $header): ?Order
            {
                return $this->resolveImportOrder($header);
            }
        };
        $probe->order = $this->orders['visible'];
        foreach ([$this->hidden->code, $this->hidden->name] as $season) {
            $this->assertNull($probe->resolveHeader(['address_code' => 'A1', 'season' => $season, 'brand' => $this->brand->code,
                'order_sheet_type' => $this->type->code, 'reference_number' => $this->orders['hidden']->reference_number]));
        }
        $resolved = $probe->resolveHeader(['address_code' => 'A1', 'season' => $this->visible->code, 'brand' => $this->brand->code,
            'order_sheet_type' => $this->type->code, 'reference_number' => $this->orders['visible']->reference_number]);
        $this->assertSame($this->orders['visible']->id, $resolved?->id);
    }

    public function test_import_source_order_must_still_belong_to_a_visible_season(): void
    {
        $probe = new class extends CatalogGroupOrder
        {
            public function beginImport(): bool
            {
                return $this->canStartExcelImport();
            }
        };
        $probe->order = $this->orders['hidden'];
        $this->expectException(HttpException::class);
        $probe->beginImport();
    }

    public function test_coverage_and_its_export_cannot_use_a_hidden_season(): void
    {
        $this->user->update(['role' => 'sales_rep']);
        $this->user->partners()->attach($this->address->partner_id);
        $page = Livewire::test(PartnerOrderCoverage::class);
        $this->assertNotContains($this->hidden->id, $page->instance()->seasons->modelKeys());
        $page->set('seasonId', $this->hidden->id)->assertNotFound();
        $this->expectException(HttpException::class);
        app(PartnerOrderCoverageService::class)->build($this->hidden->id);
    }

    public function test_cached_sales_rep_summary_cannot_leak_a_later_hidden_season(): void
    {
        $this->user->update(['role' => 'sales_rep']);
        $this->user->partners()->attach($this->address->partner_id);
        Cache::put('order-selector:sales-rep-summary:'.$this->user->id, [
            ['season_id' => $this->visible->id, 'season_name' => 'Visible S27'],
            ['season_id' => $this->hidden->id, 'season_name' => 'Hidden W27'],
        ]);
        $page = Livewire::test(SalesRepOrderSummary::class);
        $this->assertSame([$this->visible->id], $page->instance()->salesRepOrderSummaries->pluck('season_id')->all());
    }

    public function test_admin_controls_default_hidden_and_can_explicitly_publish_visibility(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(CreateSeason::class)->assertFormSet(['visible_to_partners' => false])
            ->fillForm(['code' => 'S28', 'name' => 'Admin new', 'active' => true])->call('create')->assertHasNoFormErrors();
        $new = Season::where('code', 'S28')->firstOrFail();
        $this->assertFalse($new->visible_to_partners);
        Livewire::test(EditSeason::class, ['record' => $new->id])->fillForm(['visible_to_partners' => true])
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue($new->fresh()->visible_to_partners);
    }

    public function test_hidden_season_still_supports_admin_pricing_project_membership(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin, 'web');
        $project = PricingProject::create(['season_id' => $this->hidden->id, 'name' => 'Hidden pricing', 'status' => 'active']);
        $this->assertTrue(CostCalculationAuthorization::canRecalculate($project));
        $this->assertSame(1, app(PricingProjectProductService::class)->add($project, [$this->products['hidden']->id]));
        $this->assertFalse($this->hidden->fresh()->visible_to_partners);
    }
}

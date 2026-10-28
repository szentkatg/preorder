<?php

namespace Tests\Feature;

use App\Filament\Pages\PriceListImport;
use App\Filament\Resources\PriceLists\Pages\CreatePriceList;
use App\Filament\Resources\PriceLists\Pages\EditPriceList;
use App\Filament\Resources\PriceLists\Pages\ListPriceLists;
use App\Filament\Resources\PriceLists\Pages\ViewPriceList;
use App\Livewire\Partner\CatalogGroupOrder;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\Order;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PDO;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PriceListSeasonTest extends TestCase
{
    use RefreshDatabase;

    private Season $firstSeason;

    private Season $secondSeason;

    private Currency $currency;

    private Product $product;

    private PriceList $list;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The pdo_sqlite extension is required.');
        }

        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8))]);
        $this->firstSeason = Season::create(['code' => 'S27', 'name' => '2027', 'active' => true, 'visible_to_partners' => true]);
        $this->secondSeason = Season::create(['code' => 'S28', 'name' => '2028', 'active' => true, 'visible_to_partners' => true]);
        $this->currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'BAG', 'name' => 'Táska', 'active' => true]);
        $this->product = Product::create(['season_id' => $this->firstSeason->id,
            'item_main_group_id' => $group->id, 'model_code' => 'B10024W27', 'name_hu' => 'Teszt termék', 'active' => true]);
        $this->list = PriceList::create($this->formData('Retail_HU'));

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_create_form_has_no_header_season_and_can_create_a_list(): void
    {
        Livewire::test(CreatePriceList::class)
            ->assertFormFieldDoesNotExist('season_id')
            ->fillForm($this->formData('Retail_HU_NEW'))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('price_lists', ['code' => 'Retail_HU_NEW', 'currency_id' => $this->currency->id]);
        $this->assertFalse(Schema::hasColumn('price_lists', 'season_id'));
    }

    public function test_duplicate_code_is_a_form_error_and_does_not_overwrite_the_existing_list(): void
    {
        Livewire::test(CreatePriceList::class)
            ->fillForm($this->formData('Retail_HU'))
            ->call('create')
            ->assertHasFormErrors(['code' => 'unique'])
            ->assertSee('Ez az árlistakód már létezik.');

        $this->assertSame(1, PriceList::count());
        $this->assertSame('Magyar kisker', $this->list->fresh()->name);
    }

    public function test_edit_can_keep_its_code_but_cannot_use_another_lists_code(): void
    {
        Livewire::test(EditPriceList::class, ['record' => $this->list->getRouteKey()])
            ->assertFormFieldDoesNotExist('season_id')
            ->fillForm(['name' => 'Frissített név'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Frissített név', $this->list->fresh()->name);

        PriceList::create($this->formData('Retail_OTHER'));
        Livewire::test(EditPriceList::class, ['record' => $this->list->getRouteKey()])
            ->fillForm(['code' => 'Retail_OTHER'])
            ->call('save')
            ->assertHasFormErrors(['code' => 'unique']);
        $this->assertSame('Retail_HU', $this->list->fresh()->code);
    }

    public function test_list_and_read_only_page_render_without_a_header_season(): void
    {
        Livewire::test(ListPriceLists::class)->assertSuccessful()->assertSee('Retail_HU');
        Livewire::test(ViewPriceList::class, ['record' => $this->list->getRouteKey()])
            ->assertSuccessful()->assertFormFieldDoesNotExist('season_id');
    }

    public function test_same_product_can_have_different_prices_in_two_seasons_and_orders_use_their_season(): void
    {
        $this->addItem($this->firstSeason, 9990);
        $this->addItem($this->secondSeason, 12990);
        $page = $this->orderPage();
        $page->order = new Order(['season_id' => $this->firstSeason->id]);
        $this->assertSame(9990.0, $page->productPrice($this->product->id, $this->list->id));
        $page->order = new Order(['season_id' => $this->secondSeason->id]);
        $this->assertSame(12990.0, $page->productPrice($this->product->id, $this->list->id));
        $this->assertSame($this->firstSeason->id, $this->product->fresh()->season_id);
        $this->assertSame(2, $this->list->items()->count());
    }

    public function test_import_keeps_both_season_prices_and_only_updates_the_requested_season(): void
    {
        $page = new class extends PriceListImport
        {
            public function importRows(array $rows): void
            {
                $this->sheets = ['price_lists' => [], 'price_list_items' => $rows];
                $this->validateRows();
                $this->importPriceListItems($rows);
            }
        };
        $rows = [
            ['price_list_code' => 'Retail_HU', 'season_code' => 'S27', 'model_code' => 'B10024W27', 'net_price' => 9990],
            ['price_list_code' => 'Retail_HU', 'season_code' => 'S28', 'model_code' => 'B10024W27', 'net_price' => 12990],
        ];
        $page->importRows($rows);
        $this->assertSame([], $page->rowValidationErrors);
        $this->assertSame(2, PriceListItem::count());
        $rows[1]['net_price'] = 14990;
        $page->importRows([$rows[1]]);
        $this->assertSame(2, PriceListItem::count());
        $this->assertDatabaseHas('price_list_items', ['season_id' => $this->firstSeason->id, 'net_price' => 9990]);
        $this->assertDatabaseHas('price_list_items', ['season_id' => $this->secondSeason->id, 'net_price' => 14990]);
    }

    public function test_sharing_selects_active_lists_without_a_header_season(): void
    {
        $other = PriceList::create($this->formData('Wholesale_HU'));
        $other->update(['type' => 'wholesale']);
        $inactive = PriceList::create($this->formData('Inactive'));
        $inactive->update(['active' => false]);
        $page = $this->orderPage();
        $page->order = new Order(['season_id' => $this->secondSeason->id]);

        $this->assertEqualsCanonicalizing([$this->list->id, $other->id], $page->availableShareLists());
    }

    public function test_migration_removes_only_the_header_season_even_when_item_seasons_differ(): void
    {
        $this->addItem($this->firstSeason, 9990);
        $this->addItem($this->secondSeason, 12990);
        $migration = require database_path('migrations/2026_10_12_150000_remove_season_id_from_price_lists_table.php');
        $migration->down();
        DB::table('price_lists')->where('id', $this->list->id)->update(['season_id' => $this->firstSeason->id]);
        $itemsBefore = DB::table('price_list_items')->orderBy('id')->get()->toArray();
        $migration->up();

        $this->assertFalse(Schema::hasColumn('price_lists', 'season_id'));
        $this->assertEquals($itemsBefore, DB::table('price_list_items')->orderBy('id')->get()->toArray());
        $this->assertDatabaseHas('price_lists', ['id' => $this->list->id, 'code' => 'Retail_HU']);
        $migration->down();
        $this->assertTrue(Schema::hasColumn('price_lists', 'season_id'));
        $this->assertNull(DB::table('price_lists')->where('id', $this->list->id)->value('season_id'));
        $migration->up();
    }

    private function formData(string $code): array
    {
        return ['code' => $code, 'name' => 'Magyar kisker',
            'type' => 'retail', 'currency_id' => $this->currency->id, 'active' => true];
    }

    private function addItem(Season $season, float $price): PriceListItem
    {
        return PriceListItem::create(['price_list_id' => $this->list->id, 'season_id' => $season->id,
            'product_id' => $this->product->id, 'net_price' => $price]);
    }

    private function orderPage(): CatalogGroupOrder
    {
        return new class extends CatalogGroupOrder
        {
            public function productPrice(int $productId, int $priceListId): float
            {
                return $this->getProductPrice($productId, $priceListId);
            }

            public function availableShareLists(): array
            {
                return $this->sharePriceListQuery()->pluck('id')->all();
            }
        };
    }
}

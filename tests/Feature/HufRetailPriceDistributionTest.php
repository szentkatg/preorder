<?php

namespace Tests\Feature;

use App\Exports\AdminTableExport;
use App\Filament\Pages\PricingHufRetailPriceDistribution;
use App\Models\Color;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\HufRetailPriceDistribution;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HufRetailPriceDistributionTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private PricingProject $other;

    private PriceList $list;

    private PriceList $otherList;

    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $season = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true]);
        $huf = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'active' => true]);
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euró', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => '2027 AW táska', 'status' => 'active', 'active' => true]);
        $this->other = PricingProject::create(['season_id' => $season->id, 'name' => 'Másik projekt', 'status' => 'active', 'active' => true]);
        $groups = [];
        foreach (['B02' => 'Tornazsák', 'B03' => 'Oldaltáska'] as $code => $name) {
            $groups[] = ItemMainGroup::create(['code' => $code, 'name' => $name, 'active' => true]);
        }
        foreach (range(0, 2) as $index) {
            $product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $groups[$index === 2 ? 1 : 0]->id,
                'model_code' => 'MODEL'.$index, 'name_hu' => 'Modell '.$index, 'active' => true]);
            $this->products[] = $product;
            $this->project->products()->attach($product);
            if ($index < 2) {
                foreach (['BLUE' => true, 'RED' => true, 'OLD' => false] as $code => $active) {
                    Color::create(['product_id' => $product->id, 'code' => $code, 'name_hu' => $code, 'active' => $active]);
                }
            }
        }
        $this->list = PriceList::create(['code' => 'RETAIL_HU', 'name' => 'Kisker', 'type' => 'retail', 'currency_id' => $huf->id, 'active' => true]);
        $this->otherList = PriceList::create(['code' => 'RETAIL_HU_2', 'name' => 'Másik kisker', 'type' => 'retail', 'currency_id' => $huf->id, 'active' => true]);
        foreach ($this->products[0]->colors()->where('active', true)->get() as $color) {
            $this->row(0, 3990, ['manual_price' => 4990, 'color_id' => $color->id]);
        }
        $this->row(1, 4590, ['manual_price' => 4990]);
        $this->row(2, 7990);
        $this->row(0, 5990, ['price_list_id' => $this->otherList->id]);
        $this->row(2, 19990, ['pricing_project_id' => $this->other->id]);
        $this->row(1, 2990, ['price_type' => 'outlet']);
        $foreign = PriceList::create(['code' => 'RETAIL_EUR', 'name' => 'Euró kisker', 'type' => 'retail', 'currency_id' => $eur->id, 'active' => true]);
        $this->row(0, 19.95, ['price_list_id' => $foreign->id, 'currency_id' => $eur->id]);
    }

    private function row(int $product, float $price, array $attributes = []): PricingCalculationRow
    {
        return PricingCalculationRow::create($attributes + ['pricing_project_id' => $this->project->id,
            'product_id' => $this->products[$product]->id, 'price_list_id' => $this->list->id,
            'currency_id' => $this->list->currency_id, 'price_type' => 'retail', 'calculated_price' => $price]);
    }

    public function test_distribution_uses_final_prices_deduplicates_models_and_counts_only_active_colors(): void
    {
        $rows = app(HufRetailPriceDistribution::class)->query($this->project->id)->get();
        $this->assertCount(3, $rows);
        $bucket = $rows->first(fn ($row) => $row->price_list_id === $this->list->id && (float) $row->final_price === 4990.0);
        $this->assertSame(2, $bucket->model_count);
        $this->assertSame(4, $bucket->color_count);
        $this->assertSame('B02', $bucket->itemMainGroup->code);
        $this->assertSame('2027 AW táska', $bucket->pricingProject->name);
        $this->assertSame(0, $rows->firstWhere('final_price', '7990.0000')->color_count);
        $this->assertSame(2, $rows->firstWhere('price_list_id', $this->otherList->id)->color_count);
        $this->assertFalse($rows->contains(fn ($row) => (float) $row->final_price === 3990.0));
    }

    public function test_page_requires_project_and_supports_filters_sorting_count_search_and_filtered_excel(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $page = Livewire::test(PricingHufRetailPriceDistribution::class)->assertCountTableRecords(0)
            ->call('selectPricingProject', $this->project->id)->assertCountTableRecords(3)
            ->sortTable('model_count', 'desc');
        $this->assertSame(2, $page->instance()->getTableRecords()->first()->model_count);
        $page->searchTableColumns(['model_count' => '2'])->assertCountTableRecords(1)
            ->searchTableColumns([])->filterTable('price_list_id', [$this->otherList->id])->assertCountTableRecords(1)
            ->filterTable('item_main_group_id', [$this->products[0]->item_main_group_id])->assertCountTableRecords(1);
        Excel::fake();
        $page->callTableAction('exportExcel');
        Excel::assertDownloaded('pricing_calculation_rows-'.now()->format('Ymd-His').'.xlsx', function (AdminTableExport $export): bool {
            $this->assertSame(['Árprojekt', 'Árlista', 'Főcsoport kód', 'Főcsoport név', 'Végleges kisker HUF',
                'Modellek száma', 'Színállások száma'], $export->headings());
            $this->assertSame(1, $export->query()->count());
            $this->assertSame(['2027 AW táska', 'RETAIL_HU_2', 'B02', 'Tornazsák', 5990.0, 1, 2], $export->map($export->query()->first()));

            return true;
        });
    }

    public function test_closed_project_is_available_only_in_archive_and_unauthorized_user_cannot_open_page(): void
    {
        $this->project->update(['status' => 'closed']);
        Livewire::test(PricingHufRetailPriceDistribution::class)->call('setPricingArchive', true)
            ->assertSet('pricingProjectId', $this->project->id)->assertCountTableRecords(3);
        $this->actingAs(User::factory()->create());
        $this->assertFalse(PricingHufRetailPriceDistribution::canAccess());
        Livewire::test(PricingHufRetailPriceDistribution::class)->assertForbidden();
    }

    public function test_existing_huf_retail_read_permissions_are_sufficient_without_new_write_permissions(): void
    {
        $user = User::factory()->create();
        foreach (['ViewAny:PricingCalculationRow', 'View:PricingHufRetailCalculations'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);
        $this->assertTrue(PricingHufRetailPriceDistribution::canAccess());
        Livewire::test(PricingHufRetailPriceDistribution::class)->call('selectPricingProject', $this->project->id)
            ->assertCountTableRecords(3);
    }
}

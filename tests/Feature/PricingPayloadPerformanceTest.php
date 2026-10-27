<?php

namespace Tests\Feature;

use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Models\Color;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\PricingRoundingRule;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\HufRetailCalculationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingPayloadPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_hundred_row_table_uses_small_layout_responses_and_compressible_price_sort_responses(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $season = Season::create(['code' => 'PERF', 'name' => 'Teljesítmény teszt', 'active' => true]);
        $huf = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'name_hu' => 'Forint', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'PERF', 'name_hu' => 'Teszt csoport', 'active' => true]);
        $product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
            'model_code' => 'PERF-001', 'name_hu' => 'Teszt termék', 'active' => true]);
        $project = PricingProject::create(['season_id' => $season->id, 'name' => 'Teszt projekt', 'status' => 'active', 'active' => true]);
        $project->products()->attach($product);
        $list = PriceList::create(['code' => 'PERF-HUF', 'name_hu' => 'Teszt kisker', 'type' => 'retail', 'currency_id' => $huf->id, 'active' => true]);
        $cost = PricingCalculationRow::create(['pricing_project_id' => $project->id, 'product_id' => $product->id,
            'price_type' => 'cost', 'currency_id' => $huf->id, 'calculated_price' => 460]);
        for ($index = 1; $index < 100; $index++) {
            $copy = $cost->replicate();
            $copy->color_id = Color::create(['product_id' => $product->id, 'code' => 'C'.$index, 'name_hu' => 'Szín '.$index, 'active' => true])->id;
            $copy->save();
        }
        PricingParameter::create(['pricing_project_id' => $project->id, 'parameter_type' => 'retail_multiplier',
            'scope_type' => 'global', 'value' => 4.7, 'active' => true]);
        PricingRoundingRule::create(['pricing_project_id' => $project->id, 'name' => 'HUF 990', 'price_type' => 'retail',
            'round_to' => 1000, 'mode' => 'nearest', 'adjustment' => -10, 'active' => true]);
        app(HufRetailCalculationService::class)->recalculate($project, $list);
        $row = PricingCalculationRow::where('price_type', 'retail')->firstOrFail();
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $this->assertSame(100, $page->instance()->getTableRecords()->count());
        $page->call('savePricingColumnWidths', ['final-price' => 110]);
        $this->assertArrayNotHasKey('html', $page->effects);
        $this->assertLessThan(20000, strlen($this->payload($page)));
        $manualQueries = [];
        DB::listen(function ($query) use (&$manualQueries): void {
            $manualQueries[] = strtolower($query->sql);
        });
        $page->call('updateTableColumnState', 'manual_price', (string) $row->id, '1490');
        $this->assertEmpty(array_filter($manualQueries, fn ($sql) => str_contains($sql, 'pricing_calculation_rows') && str_contains($sql, 'limit 100')));
        $this->assertArrayNotHasKey('html', $page->effects);
        $this->assertNotEmpty($page->effects['partials']);
        $this->assertLessThan(100000, strlen($this->payload($page)));
        $this->assertSame('1490.0000', $row->fresh()->final_price);
        $this->assertSame($this->payload($page), gzdecode(gzencode($this->payload($page), 5)));
        $page->sortTable('final_price', 'asc')->assertHasNoErrors();
        $this->assertArrayHasKey('html', $page->effects);
        $this->assertSame($row->id, $page->instance()->getTableRecords()->first()->id);
        $this->assertCompressionRoundTrip($this->payload($page));
        $costPage = Livewire::test(PricingCostCalculations::class)->call('savePricingColumnWidths', ['final-price' => 110]);
        $this->assertArrayNotHasKey('html', $costPage->effects);
        $this->assertLessThan(20000, strlen($this->payload($costPage)));
    }

    private function payload($page): string
    {
        return json_encode(['snapshot' => json_encode($page->snapshot), 'effects' => $page->effects], JSON_THROW_ON_ERROR);
    }

    public function test_compression_config_and_deployment_runtime_warning_are_present(): void
    {
        $configuration = file_get_contents(base_path('infrastructure/docker/nginx/default.conf'));
        $this->assertMatchesRegularExpression('/\bgzip\s+on;/', $configuration);
        $this->assertMatchesRegularExpression('/\bgzip_types\s+[^;]*\bapplication\/json\b/', $configuration);
        $this->assertMatchesRegularExpression('/\bgzip_vary\s+on;/', $configuration);
        $script = file_get_contents(base_path('infrastructure/scripts/deploy-nas.ps1'));
        $this->assertStringContainsString('NGINX ACTION REQUIRED', $script);
        $this->assertSame(1, preg_match('/\$remotePhp = @\'\R(.*?)\R\'@/s', $script, $matches));
        // Parse the embedded PHP without executing any SSH/deployment operation.
        $this->assertNotEmpty(token_get_all('<?php '.$matches[1], TOKEN_PARSE));
    }

    public function test_header_wrapping_does_not_override_livewire_loading_indicator_visibility(): void
    {
        $view = file_get_contents(resource_path('views/filament/pricing/resizable-columns-styles.blade.php'));
        $this->assertStringContainsString('.fi-ta-header-cell-sort-btn > svg:not(.fi-loading-indicator)', $view);
        $this->assertStringNotContainsString('.fi-ta-header-cell-sort-btn > svg {', $view);
        $this->assertStringNotContainsString('.fi-loading-indicator {', $view);
    }

    private function assertCompressionRoundTrip(string $payload): void
    {
        // Compression estimate, not an assertion about the live Nginx transport.
        $compressed = gzencode($payload, 5);
        $this->assertSame($payload, gzdecode($compressed));
        $this->assertLessThan(strlen($payload) * 0.1, strlen($compressed));
    }
}

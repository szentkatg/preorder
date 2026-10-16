<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\Season;
use App\Services\Pricing\PricingMultiplierAverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PricingMultiplierAverageTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private Currency $currency;

    private ItemMainGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $season = Season::create(['code' => 'W27', 'name' => 'Teszt szezon', 'active' => true]);
        $this->currency = Currency::create(['code' => 'HUF', 'name_hu' => 'Forint', 'active' => true]);
        $this->group = ItemMainGroup::create(['code' => 'BAG', 'name_hu' => 'Táska', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Teszt árprojekt', 'status' => 'active']);
    }

    private function row(?float $base, ?float $price, array $overrides = []): PricingCalculationRow
    {
        $index = Product::count() + 1;
        $product = Product::create(['season_id' => $this->project->season_id, 'item_main_group_id' => $this->group->id,
            'model_code' => 'M'.$index, 'name_hu' => 'Modell '.$index, 'active' => true]);

        return PricingCalculationRow::create(array_replace(['pricing_project_id' => $this->project->id,
            'product_id' => $product->id, 'currency_id' => $this->currency->id, 'price_type' => 'retail',
            'base_price' => $base, 'calculated_price' => $price], $overrides));
    }

    public function test_ratio_uses_value_weights_and_exactly_the_same_valid_rows_on_both_sides(): void
    {
        $this->row(100, 200);
        $this->row(300, 1200);
        $this->row(0, 9999);
        $this->row(-50, 9999);
        $this->row(null, 9999);
        $this->row(500, null);
        $rows = PricingCalculationRow::all()->map->getAttributes()->all();
        $average = PricingMultiplierAverage::calculate(PricingCalculationRow::query(), 'calculated_price', 'base_price');
        $this->assertSame(3.5, $average);
        $this->assertSame($rows, PricingCalculationRow::all()->map->getAttributes()->all());
    }

    public function test_final_price_priority_is_manual_then_retained_then_calculated(): void
    {
        $this->row(100, 200, ['manual_price' => 300, 'retained_price' => 250]);
        $this->row(300, 1200, ['retained_price' => 900]);
        $this->assertSame(3.0, PricingMultiplierAverage::calculate(PricingCalculationRow::query(),
            'COALESCE(manual_price, retained_price, calculated_price)', 'base_price'));
    }

    public function test_empty_or_invalid_input_set_has_no_average_and_zero_dividend_remains_valid(): void
    {
        $this->assertNull(PricingMultiplierAverage::calculate(PricingCalculationRow::query(), 'calculated_price', 'base_price'));
        $this->row(0, 100);
        $this->row(100, null);
        $this->assertNull(PricingMultiplierAverage::calculate(PricingCalculationRow::query(), 'calculated_price', 'base_price'));
        $this->row(100, 0);
        $this->assertSame(0.0, PricingMultiplierAverage::calculate(PricingCalculationRow::query(), 'calculated_price', 'base_price'));
    }

    public function test_filtered_query_is_not_mutated_and_aggregation_is_one_sql_query(): void
    {
        $first = $this->row(100, 200);
        $second = $this->row(300, 1200);
        $this->row(1000, 10000);
        $query = PricingCalculationRow::query()->whereIn('id', [$first->id, $second->id])->select('*')->orderBy('id', 'desc');
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $average = PricingMultiplierAverage::calculate($query, 'calculated_price', 'base_price');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame(3.5, $average);
        $this->assertCount(1, $queries);
        $this->assertSame($sql, $query->toSql());
        $this->assertSame($bindings, $query->getBindings());
        $this->assertSame([$second->id, $first->id], $query->pluck('id')->all());
    }
}

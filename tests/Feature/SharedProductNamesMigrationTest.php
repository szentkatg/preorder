<?php

namespace Tests\Feature;

use App\Models\Color;
use App\Models\ItemMainGroup;
use App\Models\Product;
use App\Models\Season;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SharedProductNamesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_28_130000_drop_redundant_product_and_color_english_names.php');
    }

    private function records(): array
    {
        $season = Season::create(['code' => 'W27', 'name' => 'Winter']);
        $group = ItemMainGroup::create(['code' => 'BAG', 'name' => 'Bags',]);
        $product = Product::create(['model_code' => 'B01W27', 'name_hu' => 'EMIRO',
            'catalog_group_name_hu' => 'SIDE BAGS', 'season_id' => $season->id, 'item_main_group_id' => $group->id]);
        $color = Color::create(['product_id' => $product->id, 'code' => '01', 'name_hu' => 'BLACK', 'active' => false]);

        return [$product, $color];
    }

    public function test_fresh_schema_keeps_shared_names_and_reference_canonical_names(): void
    {
        $this->assertTrue(Schema::hasColumns('products', ['name_hu', 'catalog_group_name_hu']));
        $this->assertTrue(Schema::hasColumn('colors', 'name_hu'));
        foreach (['name_en', 'catalog_group_name_en'] as $column) {
            $this->assertFalse(Schema::hasColumn('products', $column));
        }
        $this->assertFalse(Schema::hasColumn('colors', 'name_en'));
        foreach (['currencies', 'languages', 'item_main_groups', 'order_sheet_types', 'price_lists', 'size_ranges'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'name_en'));
            $this->assertTrue(Schema::hasColumn($table, $table === 'size_ranges' ? 'name_hu' : 'name'));
        }
    }

    public function test_matching_names_roundtrip_without_changing_rows_and_rollback_uses_current_names(): void
    {
        [$product, $color] = $this->records();
        $before = [$product->fresh()->getAttributes(), $color->fresh()->getAttributes()];
        $migration = $this->migration();
        $migration->down();
        $this->assertSame('EMIRO', DB::table('products')->value('name_en'));
        $this->assertSame('SIDE BAGS', DB::table('products')->value('catalog_group_name_en'));
        $this->assertSame('BLACK', DB::table('colors')->value('name_en'));
        $migration->up();
        $this->assertSame($before, [$product->fresh()->getAttributes(), $color->fresh()->getAttributes()]);
        $product->update(['name_hu' => 'NEW NAME']);
        $color->update(['name_hu' => 'NEW COLOR']);
        $migration->down();
        $this->assertSame('NEW NAME', DB::table('products')->value('name_en'));
        $this->assertSame('NEW COLOR', DB::table('colors')->value('name_en'));
    }

    #[DataProvider('conflicts')]
    public function test_any_conflict_including_inactive_colors_blocks_all_column_drops(string $table, string $column, string $different): void
    {
        $this->records();
        $migration = $this->migration();
        $migration->down();
        DB::table($table)->update([$column => $different]);
        try {
            $migration->up();
            $this->fail('Expected a conflicting legacy value to prevent deletion.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Néveltérés: '.$table, $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumns('products', ['name_en', 'catalog_group_name_en']));
        $this->assertTrue(Schema::hasColumn('colors', 'name_en'));
        $this->assertSame($different, DB::table($table)->value($column));
    }

    public static function conflicts(): array
    {
        return [
            'case difference' => ['products', 'name_en', 'emiro'],
            'trailing whitespace' => ['products', 'catalog_group_name_en', 'SIDE BAGS '],
            'inactive color' => ['colors', 'name_en', 'WHITE'],
        ];
    }

    public function test_missing_english_values_do_not_block_cleanup(): void
    {
        $this->records();
        $migration = $this->migration();
        $migration->down();
        DB::table('products')->update(['name_en' => null, 'catalog_group_name_en' => '']);
        DB::table('colors')->update(['name_en' => null]);
        $migration->up();
        $this->assertSame('EMIRO', DB::table('products')->value('name_hu'));
        $this->assertSame('BLACK', DB::table('colors')->value('name_hu'));
    }
}

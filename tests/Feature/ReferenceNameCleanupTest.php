<?php

namespace Tests\Feature;

use App\Filament\Pages\PriceListImport;
use App\Filament\Resources\PriceLists\Pages\ListPriceLists;
use App\Filament\Resources\PriceLists\Schemas\PriceListForm;
use App\Filament\Resources\SizeRanges\Schemas\SizeRangeForm;
use App\Models\Currency;
use App\Models\Language;
use App\Models\PriceList;
use App\Models\SizeRange;
use App\Models\Translation;
use App\Models\User;
use App\Services\Translation\TranslationRegistry;
use Filament\Facades\Filament;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReferenceNameCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_28_140000_finish_reference_name_translations.php');
    }

    public function test_schema_and_registry_have_only_canonical_reference_names(): void
    {
        foreach (['price_lists', 'order_sheet_types', 'currencies', 'languages', 'item_main_groups'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'name'));
            $this->assertFalse(Schema::hasColumn($table, 'name_hu'));
            $this->assertFalse(Schema::hasColumn($table, 'name_en'));
        }
        $this->assertFalse(Schema::hasColumn('size_ranges', 'name_en'));
        $this->assertSame(PriceList::class, TranslationRegistry::model('price_list'));
        $this->assertFalse(TranslationRegistry::isValidEntity('size_range'));
    }

    public function test_price_list_and_size_range_forms_no_longer_offer_english_columns(): void
    {
        $priceForm = PriceListForm::configure(\Filament\Schemas\Schema::make());
        $rangeForm = SizeRangeForm::configure(\Filament\Schemas\Schema::make());
        $priceFields = array_map(fn ($field) => $field->getName(), $priceForm->getComponents(withHidden: true));
        $rangeFields = array_map(fn ($field) => $field->getName(), $rangeForm->getComponents(withHidden: true));
        $this->assertContains('name', $priceFields);
        $this->assertNotContains('name_hu', $priceFields);
        $this->assertNotContains('name_en', $priceFields);
        $this->assertContains('name_hu', $rangeFields);
        $this->assertNotContains('name_en', $rangeFields);
    }

    public function test_migration_preserves_authoritative_translations_seeds_missing_ones_and_archives_size_names(): void
    {
        $hu = Language::create(['code' => 'HU', 'name' => 'Magyar', 'active' => true]);
        $en = Language::create(['code' => 'EN', 'name' => 'English', 'active' => true]);
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Canonical Forint', 'active' => true]);
        $range = SizeRange::create(['code' => 'S-XL', 'name_hu' => 'Magyar méretsor', 'active' => false]);
        $existing = Translation::create(['entity' => 'currency', 'entity_code' => 'HUF', 'field' => 'name', 'language_id' => $en->id, 'value' => 'Hungarian Forint']);
        Translation::create(['entity' => 'price_list', 'entity_code' => 'WHOLE', 'field' => 'name', 'language_id' => $en->id, 'value' => 'Current wholesale']);
        $before = $existing->fresh()->getAttributes();
        $migration = $this->migration();
        $migration->down();
        DB::table('currencies')->where('id', $currency->id)->update(['name_hu' => 'Forint', 'name_en' => 'HUF']);
        DB::table('size_ranges')->where('id', $range->id)->update(['name_en' => 'English sizes']);
        $id = DB::table('price_lists')->insertGetId(['code' => 'WHOLE', 'name_hu' => 'Nagyker', 'name_en' => 'Wholesale', 'type' => 'wholesale', 'currency_id' => $currency->id]);
        $migration->up();
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertSame('Canonical Forint', $currency->fresh()->name);
        $this->assertSame('Nagyker', PriceList::findOrFail($id)->name);
        $this->assertSame('Current wholesale', PriceList::findOrFail($id)->translate('name', $en->id));
        $this->assertSame('Nagyker', PriceList::findOrFail($id)->translate('name', $hu->id));
        $this->assertDatabaseHas('legacy_name_archives', ['source_table' => 'size_ranges', 'source_id' => $range->id]);
        $migration->down();
        $this->assertSame('English sizes', DB::table('size_ranges')->value('name_en'));
        $this->assertSame('HUF', DB::table('currencies')->value('name_en'));
        $this->assertSame('Wholesale', DB::table('price_lists')->value('name_en'));
        $this->assertSame($before, $existing->fresh()->getAttributes());
    }

    public function test_missing_language_blocks_cleanup_before_any_drop(): void
    {
        Language::create(['code' => 'HU', 'name' => 'Magyar', 'active' => true]);
        $migration = $this->migration();
        $migration->down();
        DB::table('languages')->update(['name_en' => 'Hungarian']);
        try {
            $migration->up();
            $this->fail('Expected missing EN language to block cleanup.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('EN', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('languages', 'name_en'));
        $this->assertTrue(Schema::hasColumn('price_lists', 'name_hu'));
        $this->assertTrue(Schema::hasColumn('size_ranges', 'name_en'));
    }

    public function test_price_list_translation_uses_default_name_when_missing_and_translation_original_value_is_canonical(): void
    {
        $hu = Language::create(['code' => 'HU', 'name' => 'Magyar', 'active' => true]);
        $list = PriceList::create(['code' => 'WHOLE', 'name' => 'Default wholesale', 'type' => 'wholesale']);
        $this->assertSame('Default wholesale', $list->translate('name', $hu->id));
        $translation = Translation::create(['entity' => 'price_list', 'entity_code' => 'WHOLE', 'field' => 'name', 'language_id' => $hu->id, 'value' => 'Nagyker']);
        $this->assertSame('Default wholesale', $translation->originalValue());
    }

    public function test_admin_price_list_column_exposes_the_translated_value_to_the_table_export(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $en = Language::create(['code' => 'EN', 'name' => 'English', 'active' => true]);
        session(['partner.language_id' => $en->id]);
        $list = PriceList::create(['code' => 'WHOLE', 'name' => 'Default name', 'type' => 'wholesale']);
        Translation::create(['entity' => 'price_list', 'entity_code' => 'WHOLE', 'field' => 'name', 'language_id' => $en->id, 'value' => 'English name']);
        Livewire::test(ListPriceLists::class)
            ->assertSuccessful()->assertTableColumnStateSet('name', 'English name', $list);
    }

    public function test_legacy_price_list_import_seeds_translations_and_does_not_overwrite_them(): void
    {
        $hu = Language::create(['code' => 'HU', 'name' => 'Magyar', 'active' => true]);
        $en = Language::create(['code' => 'EN', 'name' => 'English', 'active' => true]);
        Currency::create(['code' => 'HUF', 'name' => 'Forint', 'active' => true]);
        $page = new class extends PriceListImport
        {
            public function headers(array $rows): void
            {
                $this->sheets = ['price_lists' => $rows, 'price_list_items' => []];
                $this->validateRows();
                $this->importPriceLists($rows);
            }
        };
        $row = ['code' => 'WHOLE', 'name_hu' => 'Nagyker', 'name_en' => 'Wholesale', 'type' => 'wholesale', 'currency_code' => 'HUF', 'active' => 1];
        $page->headers([$row]);
        $this->assertSame([], $page->rowValidationErrors);
        $this->assertSame('Wholesale', PriceList::first()->translate('name', $en->id));
        $this->assertDatabaseHas('translations', ['entity' => 'price_list', 'language_id' => $hu->id, 'value' => 'Nagyker']);
        $page->headers([array_replace($row, ['name_en' => 'Overwrite attempt'])]);
        $this->assertNotEmpty($page->rowValidationErrors);
        $this->assertDatabaseMissing('translations', ['value' => 'Overwrite attempt']);
        $this->assertSame(1, PriceList::count());
    }

    public function test_modern_and_legacy_excel_headers_are_both_accepted_in_any_column_order(): void
    {
        Language::create(['code' => 'HU', 'name' => 'Magyar', 'active' => true]);
        $en = Language::create(['code' => 'EN', 'name' => 'English', 'active' => true]);
        Currency::create(['code' => 'HUF', 'name' => 'Forint', 'active' => true]);
        foreach (['name', 'name_hu'] as $header) {
            $page = new class extends PriceListImport
            {
                public function headerSheet(Worksheet $sheet): void
                {
                    $this->validateRequiredColumns($sheet, ['code', 'name', 'type', 'currency_code', 'active'], 'price_lists');
                    $rows = $this->sheetToRows($sheet);
                    $this->sheets = ['price_lists' => $rows, 'price_list_items' => []];
                    $this->validateRows();
                    $this->importPriceLists($rows);
                }
            };
            $sheet = (new Spreadsheet)->getActiveSheet();
            $code = strtoupper($header);
            $sheet->fromArray([['active', $header, 'currency_code', 'code', 'type', 'name_en'],
                [1, 'Alapnév', 'HUF', $code, 'wholesale', 'English list']]);
            $page->headerSheet($sheet);
            $this->assertSame([], $page->fatalValidationErrors);
            $this->assertSame([], $page->rowValidationErrors);
            $list = PriceList::where('code', $code)->firstOrFail();
            $this->assertSame('Alapnév', $list->name);
            $this->assertSame('English list', $list->translate('name', $en->id));
        }
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Exports\SkuReplacementImportTemplate;
use App\Filament\Resources\SkuReplacements\Pages\ImportSkuReplacements;
use App\Filament\Resources\SkuReplacements\Pages\ListSkuReplacements;
use App\Models\Color;
use App\Models\ItemMainGroup;
use App\Models\Product;
use App\Models\Season;
use App\Models\Sku;
use App\Models\SkuReplacement;
use App\Models\User;
use App\Services\Imports\SkuReplacementImportService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SkuReplacementImportTest extends TestCase
{
    use RefreshDatabase;

    private Season $season;

    private SkuReplacementImportService $service;

    private ?string $uploadRoot = null;

    protected function tearDown(): void
    {
        if ($this->uploadRoot !== null) {
            Storage::disk('tmp-for-tests')->deleteDirectory('livewire-tmp');
            rmdir($this->uploadRoot);
        }
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cell::setValueBinder(new DefaultValueBinder);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $this->service = app(SkuReplacementImportService::class);
        $this->season = Season::create(['code' => 'W27', 'name' => 'Tél']);
        $other = Season::create(['code' => 'S27', 'name' => 'Nyár']);
        $group = ItemMainGroup::create(['code' => 'B01', 'name' => 'Táska']);
        foreach ([$this->season, $other] as $season) {
            $product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
                'model_code' => 'M'.$season->code, 'name_hu' => 'Modell']);
            $color = Color::create(['product_id' => $product->id, 'code' => '01', 'name_hu' => 'BLACK']);
            foreach (['A', 'B', 'C', 'D', 'PACK'] as $code) {
                Sku::create(['product_id' => $product->id, 'color_id' => $color->id, 'sku_code' => $season->code.'-'.$code,
                    'sku_name' => 'Név '.$code, 'type' => $code === 'PACK' ? 'assortment' : 'piece']);
            }
        }
    }

    private function upload(array $rows, array $headers = ['season_code', 'old_sku_code', 'new_sku_code'], string $format = 'Xlsx'): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        foreach ([$headers, ...$rows] as $row => $values) {
            foreach ($values as $column => $value) {
                $cell = $sheet->getCell([$column + 1, $row + 1]);
                if (is_string($value)) {
                    $cell->setValueExplicit($value, DataType::TYPE_STRING);
                } else {
                    $cell->setValue($value);
                }
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'sku-replacement-import-');
        try {
            IOFactory::createWriter($book, $format)->save($path);

            return UploadedFile::fake()->createWithContent('c.'.($format === 'Xls' ? 'xls' : 'xlsx'), file_get_contents($path));
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    private function mapping(string $old = 'W27-A', string $new = 'W27-B'): SkuReplacement
    {
        return SkuReplacement::create(['season_id' => $this->season->id, 'old_sku_code' => $old, 'new_sku_code' => $new]);
    }

    public function test_preview_is_read_only_and_import_accepts_shuffled_hungarian_headers_and_multiple_seasons(): void
    {
        $file = $this->upload([
            ['W27-B', 'W27-A', 'w27'], [' törölt ', 'W27-C', 'W27'], ['S27-B', 'S27-A', 'S27'],
        ], ['Új cikkszám / TÖRÖLT', 'Régi cikkszám', 'Szezon']);
        $preview = $this->service->preview($file);
        $this->assertSame([], $preview['errors']);
        $this->assertSame(3, $preview['counts']['create']);
        $this->assertSame('Név A', $preview['rows'][0]['old_sku_name']);
        $this->assertDatabaseCount('sku_replacements', 0);
        $counts = $this->service->import($file, 'skip', $preview['token']);
        $this->assertSame(3, $counts['create']);
        $this->assertDatabaseHas('sku_replacements', ['old_sku_code' => 'W27-C', 'new_sku_code' => 'TÖRÖLT']);
        $this->assertDatabaseHas('sku_replacements', ['old_sku_code' => 'S27-A', 'new_sku_code' => 'S27-B']);
    }

    public function test_existing_records_are_skipped_by_default_and_overwritten_only_when_explicitly_selected(): void
    {
        $record = $this->mapping();
        $file = $this->upload([['W27', 'W27-A', 'W27-C'], ['W27', 'W27-D', 'TÖRÖLT']]);
        $skip = $this->service->preview($file);
        $this->assertSame(['create' => 1, 'overwrite' => 0, 'skip' => 1, 'unchanged' => 0], $skip['counts']);
        $this->service->import($file, 'skip', $skip['token']);
        $this->assertSame('W27-B', $record->fresh()->new_sku_code);
        $overwrite = $this->service->preview($file, 'overwrite');
        $this->assertSame(1, $overwrite['counts']['overwrite']);
        $this->service->import($file, 'overwrite', $overwrite['token']);
        $this->assertSame('W27-C', $record->fresh()->new_sku_code);
        $this->assertSame($record->id, SkuReplacement::where('old_sku_code', 'W27-A')->firstOrFail()->id);
        $this->assertSame($record->created_at->toISOString(), $record->fresh()->created_at->toISOString());
    }

    public function test_identical_rows_are_unchanged_and_multiple_old_skus_can_point_to_the_same_target(): void
    {
        $record = $this->mapping('W27-A', 'W27-C');
        $before = $record->fresh()->getAttributes();
        $file = $this->upload([['W27', 'W27-A', 'W27-C'], ['W27', 'W27-B', 'W27-C']]);
        $preview = $this->service->preview($file);
        $this->assertSame(1, $preview['counts']['unchanged']);
        $this->service->import($file, 'skip', $preview['token']);
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertSame(2, SkuReplacement::where('new_sku_code', 'W27-C')->count());
    }

    public function test_invalid_rows_and_duplicate_source_codes_prevent_the_entire_batch(): void
    {
        $file = $this->upload([['W27', 'W27-A', 'W27-B'], ['W27', 'w27-a', 'W27-C'], ['W27', 'W27-D', 'S27-A'], ['UNKNOWN', 'W27-C', 'TÖRÖLT']]);
        $preview = $this->service->preview($file);
        $this->assertNull($preview['token']);
        $this->assertGreaterThanOrEqual(3, count($preview['errors']));
        try {
            $this->service->import($file, 'skip', 'not-a-valid-preview');
            $this->fail('An invalid batch was imported.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('sku_replacements', 0);
        }
    }

    public function test_final_graph_is_validated_as_a_batch_instead_of_rejecting_a_valid_temporary_cycle(): void
    {
        $this->mapping('W27-A', 'W27-B');
        $file = $this->upload([['W27', 'W27-B', 'W27-A'], ['W27', 'W27-A', 'W27-C']]);
        $preview = $this->service->preview($file, 'overwrite');
        $this->assertSame([], $preview['errors']);
        $this->service->import($file, 'overwrite', $preview['token']);
        $this->assertDatabaseHas('sku_replacements', ['old_sku_code' => 'W27-B', 'new_sku_code' => 'W27-A']);
        $this->assertDatabaseHas('sku_replacements', ['old_sku_code' => 'W27-A', 'new_sku_code' => 'W27-C']);
    }

    public function test_circular_imports_and_cycles_using_existing_records_are_rejected(): void
    {
        $this->mapping('W27-C', 'W27-A');
        foreach ([
            [['W27', 'W27-A', 'W27-A']],
            [['W27', 'W27-A', 'W27-B'], ['W27', 'W27-B', 'W27-A']],
            [['W27', 'W27-A', 'W27-C']],
        ] as $rows) {
            $preview = $this->service->preview($this->upload($rows));
            $this->assertNull($preview['token']);
            $this->assertStringContainsString('körkörös', implode(' ', $preview['errors']));
        }
        $this->assertDatabaseCount('sku_replacements', 1);
    }

    public function test_preview_tokens_reject_changed_files_modes_and_database_records(): void
    {
        $file = $this->upload([['W27', 'W27-A', 'W27-B']]);
        $preview = $this->service->preview($file);
        foreach ([
            [$this->upload([['W27', 'W27-A', 'W27-C']]), 'skip'],
            [$file, 'overwrite'],
        ] as [$changed, $mode]) {
            try {
                $this->service->import($changed, $mode, $preview['token']);
                $this->fail('A stale preview was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('sku_replacements', 0);
            }
        }
        $this->mapping('W27-D', 'TÖRÖLT');
        $this->expectException(ValidationException::class);
        $this->service->import($file, 'skip', $preview['token']);
    }

    public function test_sku_codes_with_leading_zeroes_are_preserved_and_numeric_cells_are_rejected(): void
    {
        $template = Sku::where('sku_code', 'W27-A')->firstOrFail();
        foreach (['00123', '00456'] as $code) {
            $template->replicate()->fill(['sku_code' => $code])->save();
        }
        $file = $this->upload([['W27', '00123', '00456']]);
        $preview = $this->service->preview($file);
        $this->assertSame([], $preview['errors']);
        $this->service->import($file, 'skip', $preview['token']);
        $this->assertDatabaseHas('sku_replacements', ['old_sku_code' => '00123', 'new_sku_code' => '00456']);
        $invalid = $this->service->preview($this->upload([['W27', 123, '00456']]));
        $this->assertNull($invalid['token']);
        $this->assertStringContainsString('szövegként', implode(' ', $invalid['errors']));
    }

    public function test_assortment_codes_are_imported_without_expanding_components(): void
    {
        $file = $this->upload([['W27', 'W27-PACK', 'TÖRÖLT']]);
        $preview = $this->service->preview($file);
        $this->service->import($file, 'skip', $preview['token']);
        $this->assertDatabaseHas('sku_replacements', ['old_sku_code' => 'W27-PACK', 'new_sku_code' => 'TÖRÖLT']);
    }

    public function test_legacy_xls_is_supported_but_invalid_files_paths_missing_headers_and_empty_sheets_are_not(): void
    {
        $file = $this->upload([['W27', 'W27-A', 'W27-B']], format: 'Xls');
        $this->assertSame([], $this->service->preview($file)['errors']);
        foreach (['/etc/passwd', UploadedFile::fake()->createWithContent('bad.xlsx', 'not an excel file'),
            $this->upload([], ['wrong']), $this->upload([])] as $invalid) {
            try {
                $this->service->preview($invalid);
                $this->fail('An invalid file was accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('data.file', $error->errors());
            }
        }
    }

    public function test_formula_cells_are_rejected_without_evaluating_them(): void
    {
        $file = $this->upload([['W27', 'W27-A', '="W27-B"']]);
        $book = IOFactory::load($file->getRealPath());
        $book->getActiveSheet()->getCell('C2')->setValueExplicit('="W27-B"', DataType::TYPE_FORMULA);
        IOFactory::createWriter($book, 'Xlsx')->save($file->getRealPath());
        $book->disconnectWorksheets();
        $preview = $this->service->preview($file);
        $this->assertNull($preview['token']);
        $this->assertStringContainsString('képlet', implode(' ', $preview['errors']));
    }

    public function test_import_page_checks_previews_requires_confirmation_and_cannot_be_used_by_view_only_roles(): void
    {
        // ZipArchive on Windows cannot open the long filenames produced by
        // Livewire's test uploader beneath this deeply nested worktree.
        $this->uploadRoot = sys_get_temp_dir().'/sku-'.bin2hex(random_bytes(4));
        Storage::set('tmp-for-tests', Storage::build(['driver' => 'local', 'root' => $this->uploadRoot]));
        Excel::fake();
        $file = $this->upload([['W27', 'W27-A', 'W27-B']]);
        Livewire::test(ListSkuReplacements::class)->assertActionVisible('importExcel');
        $page = Livewire::test(ImportSkuReplacements::class)->assertSuccessful()->assertActionDisabled('import')
            ->callAction('downloadTemplate');
        Excel::assertDownloaded('cikkcsere-import-minta.xlsx', fn (SkuReplacementImportTemplate $template) => $template->headings() === ['season_code', 'old_sku_code', 'new_sku_code']);
        $page->set('data.file', [$file]);
        $uploaded = array_values($page->get('data.file'))[0];
        $this->assertSame(hash_file('sha256', $file->getRealPath()), hash_file('sha256', $uploaded->getRealPath()));
        $page->callAction('validateImport');
        $this->assertSame([], $page->instance()->getErrorBag()->toArray());
        $page->assertActionEnabled('import');
        $this->assertDatabaseCount('sku_replacements', 0);
        $page->mountAction('import')->assertActionMounted('import');
        $this->assertDatabaseCount('sku_replacements', 0);
        $page->callMountedAction()->assertHasNoErrors();
        $this->assertDatabaseHas('sku_replacements', ['old_sku_code' => 'W27-A', 'new_sku_code' => 'W27-B']);
        $page->assertActionDisabled('import');
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('ViewAny:SkuReplacement');
        $this->actingAs($viewer);
        Livewire::test(ListSkuReplacements::class)->assertActionHidden('importExcel');
        Livewire::test(ImportSkuReplacements::class)->assertForbidden();
    }

    public function test_create_only_users_can_import_new_records_but_cannot_overwrite(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:SkuReplacement', 'Create:SkuReplacement']);
        $this->actingAs($user);
        $file = $this->upload([['W27', 'W27-A', 'W27-B']]);
        $preview = $this->service->preview($file);
        $this->service->import($file, 'skip', $preview['token']);
        $this->assertDatabaseCount('sku_replacements', 1);
        $this->expectException(AuthorizationException::class);
        $this->service->preview($file, 'overwrite');
    }

    public function test_downloaded_template_formats_future_input_rows_as_text_and_can_be_imported(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sku-template-');
        try {
            file_put_contents($path, Excel::raw(new SkuReplacementImportTemplate, \Maatwebsite\Excel\Excel::XLSX));
            Cell::setValueBinder(new DefaultValueBinder);
            $book = IOFactory::load($path);
            $sheet = $book->getSheet(0);
            $this->assertSame('Cikkcserék', $sheet->getTitle());
            $this->assertSame(['season_code', 'old_sku_code', 'new_sku_code'], $sheet->rangeToArray('A1:C1')[0]);
            foreach (['A', 'B', 'C'] as $column) {
                $xf = $sheet->getColumnDimension($column)->getXfIndex();
                $this->assertSame('@', $book->getCellXfByIndex($xf)->getNumberFormat()->getFormatCode());
            }
            $sheet->fromArray([['W27', 'W27-A', 'TÖRÖLT']], null, 'A2');
            IOFactory::createWriter($book, 'Xlsx')->save($path);
            $book->disconnectWorksheets();
            $file = UploadedFile::fake()->createWithContent('c.xlsx', file_get_contents($path));
            $preview = $this->service->preview($file);
            $this->assertSame([], $preview['errors']);
            $this->assertSame(1, $preview['counts']['create']);
        } finally {
            unlink($path);
        }
    }
}

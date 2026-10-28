<?php

namespace Tests\Feature;

use App\Exports\ErpPricingExport;
use App\Filament\Resources\PricingProjects\Pages\ListPricingProjects;
use App\Filament\Resources\PricingProjects\Pages\ViewPricingProject;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PricingProject;
use App\Models\PricingProjectPublication;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\ErpExportPreferences;
use App\Services\Pricing\ErpPricingExportService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ErpPricingFormulaExportTest extends TestCase
{
    use RefreshDatabase;

    protected PricingProject $project;

    protected PricingProjectPublication $publication;

    protected array $currencies;

    protected array $lists;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $season = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true]);
        foreach (['HUF', 'EUR', 'CZK'] as $code) {
            $this->currencies[$code] = Currency::create(['code' => $code, 'name_hu' => $code, 'active' => true]);
        }
        foreach (['HUF' => 'retail', 'EUR' => 'wholesale'] as $code => $type) {
            $this->lists[$code] = PriceList::create(['code' => $type.'_'.$code, 'name_hu' => $code,
                'type' => $type, 'currency_id' => $this->currencies[$code]->id, 'active' => true]);
        }
        $group = ItemMainGroup::create(['code' => 'B', 'name_hu' => 'Táska', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Képletteszt', 'status' => 'closed', 'active' => true]);
        $products = [];
        $prices = [];
        foreach (['A', 'B'] as $index => $code) {
            $product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
                'model_code' => $code.'W27', 'name_hu' => $code, 'active' => true]);
            $products[$product->id] = ['id' => $product->id, 'model_code' => $product->model_code,
                'name_hu' => $code, 'name_en' => $code, 'cost' => '460.125'];
            foreach ($this->lists as $currency => $list) {
                $prices[] = ['product_id' => $product->id, 'price_list_id' => $list->id,
                    'price' => $currency === 'HUF' ? '9990.00' : ($index === 0 ? '19.99' : '39.99')];
            }
        }
        $this->publication = $this->project->publications()->create(['published_by' => $user->id, 'published_at' => now(),
            'snapshot' => ['season_id' => $season->id, 'season_code' => 'W27', 'products' => $products,
                'lists' => collect($this->lists)->mapWithKeys(fn ($list, $currency) => [$list->id => [
                    'id' => $list->id, 'code' => $list->code, 'type' => $list->type,
                    'currency_id' => $list->currency_id, 'currency' => $currency,
                ]])->all(), 'prices' => $prices]]);
        foreach (['EUR' => 400, 'CZK' => 16] as $currency => $rate) {
            ExchangeRate::create(['season_id' => $season->id, 'currency_id' => $this->currencies[$currency]->id,
                'rate_to_huf' => $rate, 'active' => true]);
        }
    }

    protected function token(string $type, mixed $value = null): array
    {
        return $value === null ? ['type' => $type] : ['type' => $type, 'value' => (string) $value];
    }

    protected function export(array $formula, string $slot = 'czk_distributor'): array
    {
        return app(ErpPricingExportService::class)->rows($this->project,
            [$slot => ErpPricingExportService::FORMULA], '2026-10-27', [$slot => $formula]);
    }

    public function test_foreign_conversion_uses_published_prices_and_publication_season_rates_without_writes(): void
    {
        $before = $this->publication->fresh()->getAttributes();
        // Neither a renamed/current list nor the project's later season changes the publication sources.
        $this->lists['EUR']->update(['code' => 'CHANGED', 'active' => false]);
        $otherSeason = Season::create(['code' => 'W28', 'name' => 'Másik', 'active' => true]);
        $this->project->update(['season_id' => $otherSeason->id]);
        ExchangeRate::create(['season_id' => $otherSeason->id, 'currency_id' => $this->currencies['EUR']->id,
            'rate_to_huf' => 999, 'active' => true]);
        $configuration = app(ErpPricingExportService::class)->configuration($this->project);
        $this->assertSame('wholesale_EUR (EUR)', $configuration['lists'][$this->lists['EUR']->id]);
        $this->assertStringContainsString('400 HUF', $configuration['currencies'][$this->currencies['EUR']->id]);
        $rows = $this->export([
            $this->token('price_list', $this->lists['EUR']->id), $this->token('*'),
            $this->token('exchange_rate', $this->currencies['EUR']->id), $this->token('/'),
            $this->token('exchange_rate', $this->currencies['CZK']->id),
        ]);
        $this->assertCount(5, $rows);
        $this->assertCount(30, $rows[3]);
        $this->assertSame(499.75, $rows[3][19]);
        $this->assertSame(999.75, $rows[4][19]);
        $this->assertSame($rows[3][19], $rows[3][20]);
        $this->assertSame(9990.0, $rows[3][10]);
        $this->assertSame(460.13, $rows[3][5]);
        $this->assertSame($before, $this->publication->fresh()->getAttributes());
        $this->assertDatabaseCount('price_list_items', 0);
        $this->assertDatabaseCount('pricing_calculation_rows', 0);
    }

    public function test_fixed_number_formula_rounds_half_up_and_huf_rate_is_one_without_rate_record(): void
    {
        ExchangeRate::query()->delete();
        $rows = $this->export([$this->token('number', '1,005'), $this->token('*'),
            $this->token('exchange_rate', $this->currencies['HUF']->id)]);
        $this->assertSame(1.01, $rows[3][19]);
        $this->assertSame(1.01, $rows[4][20]);
    }

    public function test_arithmetic_formula_is_exported_as_numeric_value_not_excel_formula(): void
    {
        $rows = $this->export([$this->token('('), $this->token('price_list', $this->lists['EUR']->id),
            $this->token('+'), $this->token('number', '5.01'), $this->token(')'),
            $this->token('/'), $this->token('number', '25')]);
        $this->assertSame(1.0, $rows[3][19]);
        $path = tempnam(sys_get_temp_dir(), 'erp-formula-');
        $binder = Cell::getValueBinder();
        try {
            file_put_contents($path, Excel::raw(new ErpPricingExport($rows), \Maatwebsite\Excel\Excel::XLSX));
            Cell::setValueBinder(new DefaultValueBinder);
            $sheet = IOFactory::load($path)->getSheet(0);
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('T4')->getDataType());
            $this->assertEquals(1, $sheet->getCell('T4')->getValue());
            $this->assertSame($sheet->getCell('T4')->getValue(), $sheet->getCell('U4')->getValue());
            $this->assertSame('AD', $sheet->getHighestDataColumn());
        } finally {
            Cell::setValueBinder($binder);
            @unlink($path);
        }
    }

    #[DataProvider('invalidResults')]
    public function test_invalid_results_abort_export_with_field_and_product_error(array $tokens): void
    {
        try {
            $this->export($tokens);
            $this->fail('Invalid formula must abort the entire export.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('formulas.czk_distributor', $exception->errors());
            $this->assertStringContainsString('AW27', $exception->errors()['formulas.czk_distributor'][0]);
        }
    }

    public static function invalidResults(): array
    {
        return [
            'zero divisor' => [[['type' => 'number', 'value' => '1'], ['type' => '/'], ['type' => 'number', 'value' => '0']]],
            'zero' => [[['type' => 'number', 'value' => '0']]],
            'negative' => [[['type' => 'number', 'value' => '-1']]],
            'rounds to zero' => [[['type' => 'number', 'value' => '0.004']]],
            'overflows target' => [[['type' => 'number', 'value' => '999999999999']]],
        ];
    }

    public function test_missing_price_does_not_silently_become_zero_or_empty(): void
    {
        $snapshot = $this->publication->snapshot;
        array_pop($snapshot['prices']);
        $this->publication->update(['snapshot' => $snapshot]);
        try {
            $this->export([$this->token('price_list', $this->lists['EUR']->id)]);
            $this->fail('Missing price must abort export.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('BW27', $exception->getMessage());
            $this->assertStringContainsString('wholesale_EUR', $exception->getMessage());
        }
    }

    #[DataProvider('invalidRates')]
    public function test_missing_inactive_or_nonpositive_season_rate_is_rejected(string $mode): void
    {
        $rate = ExchangeRate::where('currency_id', $this->currencies['CZK']->id)->firstOrFail();
        match ($mode) {
            'missing' => $rate->delete(),
            'inactive' => $rate->update(['active' => false]),
            'zero' => $rate->update(['rate_to_huf' => 0]),
        };
        try {
            $this->export([$this->token('exchange_rate', $this->currencies['CZK']->id)]);
            $this->fail('Invalid rate must abort export.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('formulas.czk_distributor', $exception->errors());
            $this->assertStringContainsString('CZK', $exception->getMessage());
        }
    }

    public static function invalidRates(): array
    {
        return [['missing'], ['inactive'], ['zero']];
    }

    public function test_unpublished_list_and_inactive_currency_cannot_be_injected(): void
    {
        foreach ([[$this->token('price_list', 999999)], [$this->token('exchange_rate', 999999)]] as $tokens) {
            try {
                $this->export($tokens);
                $this->fail('Unlisted reference must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('formulas.czk_distributor', $exception->errors());
            }
        }
        $this->currencies['CZK']->update(['active' => false]);
        $this->expectException(ValidationException::class);
        $this->export([$this->token('exchange_rate', $this->currencies['CZK']->id)]);
    }

    public function test_direct_mapping_still_cannot_use_wrong_currency_or_type(): void
    {
        $this->expectException(ValidationException::class);
        app(ErpPricingExportService::class)->rows($this->project, ['huf_retail' => $this->lists['EUR']->id], '2026-10-27');
    }

    public function test_formula_export_requires_existing_erp_export_permission(): void
    {
        $this->actingAs(User::factory()->create());
        $this->expectException(AuthorizationException::class);
        $this->export([$this->token('number', '25')]);
    }

    public function test_action_renders_builder_and_downloads_formula_result(): void
    {
        $tokens = [$this->token('price_list', $this->lists['EUR']->id), $this->token('/'), $this->token('number', '25')];
        $page = Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])
            ->mountAction('exportErpPrices')
            ->set('mountedActions.0.data.mapping.czk_distributor', ErpPricingExportService::FORMULA);
        $html = $page->getMountedActionModalHtml();
        $this->assertStringContainsString('data-erp-formula-builder', $html);
        $this->assertStringContainsString('Építőelem', $html);
        $page
            ->set('mountedActions.0.data.formulas.czk_distributor', $tokens)
            ->set('mountedActions.0.data.start_date', '2026-10-27')
            ->callMountedAction()->assertHasNoActionErrors()->assertFileDownloaded();
    }

    public function test_action_shows_formula_error_and_stays_open_without_download(): void
    {
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])
            ->mountAction('exportErpPrices')
            ->set('mountedActions.0.data.mapping.czk_distributor', ErpPricingExportService::FORMULA)
            ->set('mountedActions.0.data.formulas.czk_distributor', [$this->token('number', '5'), $this->token('/')])
            ->set('mountedActions.0.data.start_date', '2026-10-27')
            ->callMountedAction()->assertHasActionErrors(['formulas.czk_distributor'])
            ->assertActionMounted()->assertNoFileDownloaded();
    }

    #[DataProvider('roundingExamples')]
    public function test_formula_result_has_per_column_rounding(string $value, array $rounding, float $expected): void
    {
        $rows = app(ErpPricingExportService::class)->rows($this->project,
            ['czk_distributor' => ErpPricingExportService::FORMULA], '2026-10-28',
            ['czk_distributor' => [$this->token('number', $value)]], ['czk_distributor' => $rounding]);
        $this->assertSame($expected, $rows[3][19]);
        $this->assertSame($expected, $rows[3][20]);
        $this->assertSame(9990.0, $rows[3][10]);
    }

    public static function roundingExamples(): array
    {
        return [
            ['12.50', ['step' => '1', 'mode' => 'nearest'], 13.0],
            ['12.01', ['step' => '1', 'mode' => 'up'], 13.0],
            ['12.99', ['step' => '1', 'mode' => 'down'], 12.0],
            ['2162', ['step' => '1000', 'mode' => 'nearest', 'adjustment' => '-10'], 1990.0],
            ['29.91', ['step' => '1', 'mode' => 'up', 'adjustment' => '-0,05'], 29.95],
            ['12.03', ['step' => '0,05', 'mode' => 'nearest'], 12.05],
            ['12.03', ['step' => '0.05', 'mode' => 'down', 'adjustment' => '0.01'], 12.01],
            ['1.005', [], 1.01],
            ['2.49999999', ['step' => '1', 'mode' => 'nearest'], 2.0],
        ];
    }

    #[DataProvider('invalidRounding')]
    public function test_invalid_rounding_is_a_field_error(mixed $rounding, string $field): void
    {
        try {
            app(ErpPricingExportService::class)->rows($this->project,
                ['czk_distributor' => ErpPricingExportService::FORMULA], '2026-10-28',
                ['czk_distributor' => [$this->token('number', '25')]], ['czk_distributor' => $rounding]);
            $this->fail('Invalid rounding must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rounding.czk_distributor.'.$field, $exception->errors());
        }
    }

    public static function invalidRounding(): array
    {
        return [
            [['step' => 0], 'step'], [['step' => '-1'], 'step'], [['step' => '0.001'], 'step'],
            [['step' => '100000000000'], 'step'], [['step' => '1e2'], 'step'], [['step' => []], 'step'],
            [['mode' => 'execute'], 'mode'], [['mode' => []], 'mode'],
            [['adjustment' => '0.005'], 'adjustment'], [['adjustment' => 'Infinity'], 'adjustment'],
            ['not an object', 'step'],
        ];
    }

    public function test_rounding_does_not_bypass_final_positive_price_check(): void
    {
        $this->expectException(ValidationException::class);
        app(ErpPricingExportService::class)->rows($this->project,
            ['czk_distributor' => ErpPricingExportService::FORMULA], '2026-10-28',
            ['czk_distributor' => [$this->token('number', '0.2')]],
            ['czk_distributor' => ['step' => '1', 'mode' => 'down']]);
    }

    public function test_direct_price_is_not_rounded_by_formula_settings(): void
    {
        $rows = app(ErpPricingExportService::class)->rows($this->project, [], '2026-10-28', [],
            ['huf_retail' => ['step' => 'not used']]);
        $this->assertSame(9990.0, $rows[3][10]);
    }

    public function test_formula_mapping_and_rounding_survive_cancel_and_new_page_instance(): void
    {
        $tokens = [$this->token('price_list', $this->lists['EUR']->id), $this->token('/'), $this->token('number', '25')];
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])
            ->mountAction('exportErpPrices')
            ->set('mountedActions.0.data.mapping.czk_distributor', ErpPricingExportService::FORMULA)
            ->set('mountedActions.0.data.formulas.czk_distributor', $tokens)
            ->set('mountedActions.0.data.rounding.czk_distributor.step', '1')
            ->set('mountedActions.0.data.rounding.czk_distributor.mode', 'up')
            ->set('mountedActions.0.data.rounding.czk_distributor.adjustment', '-0,05')
            ->unmountAction()->assertNoFileDownloaded();
        $this->assertDatabaseCount('admin_ui_preferences', 1);
        $this->assertDatabaseCount('price_list_items', 0);
        $page = Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])->mountAction('exportErpPrices');
        $page->assertSet('mountedActions.0.data.mapping.czk_distributor', ErpPricingExportService::FORMULA)
            ->assertSet('mountedActions.0.data.formulas.czk_distributor', $tokens)
            ->assertSet('mountedActions.0.data.rounding.czk_distributor.step', '1')
            ->assertSet('mountedActions.0.data.rounding.czk_distributor.mode', 'up')
            ->assertSet('mountedActions.0.data.rounding.czk_distributor.adjustment', '-0,05')
            ->callMountedAction()->assertHasNoActionErrors()->assertFileDownloaded();
        Livewire::test(ListPricingProjects::class)->mountTableAction('exportErpPrices', $this->project)
            ->assertSet('mountedActions.0.data.formulas.czk_distributor', $tokens);
    }

    public function test_switching_to_direct_price_does_not_forget_hidden_formula_or_rounding(): void
    {
        $tokens = [$this->token('number', '25')];
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])->mountAction('exportErpPrices')
            ->set('mountedActions.0.data.mapping.huf_retail', ErpPricingExportService::FORMULA)
            ->set('mountedActions.0.data.formulas.huf_retail', $tokens)
            ->set('mountedActions.0.data.rounding.huf_retail.step', '100')
            ->set('mountedActions.0.data.mapping.huf_retail', $this->lists['HUF']->id)->unmountAction();
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])->mountAction('exportErpPrices')
            ->assertSet('mountedActions.0.data.mapping.huf_retail', $this->lists['HUF']->id)
            ->set('mountedActions.0.data.mapping.huf_retail', ErpPricingExportService::FORMULA)
            ->assertSet('mountedActions.0.data.formulas.huf_retail', $tokens)
            ->assertSet('mountedActions.0.data.rounding.huf_retail.step', '100');
    }

    public function test_remembered_draft_remains_subject_to_validation(): void
    {
        $tokens = [$this->token('number', '25'), $this->token('/')];
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])->mountAction('exportErpPrices')
            ->set('mountedActions.0.data.mapping.czk_distributor', ErpPricingExportService::FORMULA)
            ->set('mountedActions.0.data.formulas.czk_distributor', $tokens)->unmountAction();
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])->mountAction('exportErpPrices')
            ->assertSet('mountedActions.0.data.formulas.czk_distributor', $tokens)
            ->callMountedAction()->assertHasActionErrors(['formulas.czk_distributor'])
            ->assertActionMounted()->assertNoFileDownloaded();
    }

    public function test_preferences_are_separate_per_user_and_project(): void
    {
        $preferences = app(ErpExportPreferences::class);
        $preferences->remember($this->project, 'huf_retail', 'formulas', [$this->token('number', '25')]);
        $another = $this->project->replicate();
        $another->save();
        $this->assertSame([], $preferences->load($another));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $this->assertSame([], $preferences->load($this->project));
        $preferences->remember($this->project, 'huf_retail', 'formulas', [$this->token('number', '50')]);
        $this->assertDatabaseCount('admin_ui_preferences', 2);
    }

    public function test_preferences_cannot_be_saved_without_export_permission(): void
    {
        $this->actingAs(User::factory()->create());
        $this->expectException(AuthorizationException::class);
        app(ErpExportPreferences::class)->remember($this->project, 'huf_retail', 'formulas', []);
    }

    public function test_oversized_or_untyped_preferences_are_not_persisted(): void
    {
        $preferences = app(ErpExportPreferences::class);
        foreach ([array_fill(0, 129, $this->token('number', '1')), [['type' => 'code', 'value' => 'bad']],
            [['type' => 'number', 'value' => str_repeat('1', 33)]], [['type' => 'number', 'value' => []]]] as $tokens) {
            $preferences->remember($this->project, 'huf_retail', 'formulas', $tokens);
        }
        $preferences->remember($this->project, 'huf_retail', 'step', ['malformed']);
        $this->assertDatabaseCount('admin_ui_preferences', 0);
    }

    public function test_action_shows_invalid_rounding_beneath_the_correct_control(): void
    {
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])->mountAction('exportErpPrices')
            ->set('mountedActions.0.data.mapping.czk_distributor', ErpPricingExportService::FORMULA)
            ->set('mountedActions.0.data.formulas.czk_distributor', [$this->token('number', '25')])
            ->set('mountedActions.0.data.rounding.czk_distributor.step', '0')
            ->callMountedAction()->assertHasActionErrors(['rounding.czk_distributor.step'])
            ->assertActionMounted()->assertNoFileDownloaded();
    }

    public function test_stale_direct_mapping_is_not_reused_after_a_new_publication(): void
    {
        app(ErpExportPreferences::class)->remember($this->project, 'huf_retail', 'mapping', 999999);
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])->mountAction('exportErpPrices')
            ->assertSet('mountedActions.0.data.mapping.huf_retail', $this->lists['HUF']->id);
    }

    public function test_nested_token_edit_is_remembered_without_replacing_the_entire_field(): void
    {
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])->mountAction('exportErpPrices')
            ->set('mountedActions.0.data.mapping.czk_distributor', ErpPricingExportService::FORMULA)
            ->set('mountedActions.0.data.formulas.czk_distributor', [$this->token('number', '25')])
            ->set('mountedActions.0.data.formulas.czk_distributor.0.value', '50')->unmountAction();
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])->mountAction('exportErpPrices')
            ->assertSet('mountedActions.0.data.formulas.czk_distributor', [$this->token('number', '50')]);
    }

    public function test_separate_formula_slots_have_independent_rounding_and_preferences(): void
    {
        $preferences = app(ErpExportPreferences::class);
        $preferences->remember($this->project, 'huf_retail', 'step', '100');
        $preferences->remember($this->project, 'czk_retail', 'step', '1');
        $settings = $preferences->load($this->project);
        $this->assertSame('100', $settings['rounding']['huf_retail']['step']);
        $this->assertSame('1', $settings['rounding']['czk_retail']['step']);
        $rows = app(ErpPricingExportService::class)->rows($this->project,
            ['huf_retail' => ErpPricingExportService::FORMULA, 'czk_retail' => ErpPricingExportService::FORMULA], '2026-10-28',
            ['huf_retail' => [$this->token('number', '1234.25')], 'czk_retail' => [$this->token('number', '1234.25')]], $settings['rounding']);
        $this->assertSame(1200.0, $rows[3][10]);
        $this->assertSame(1234.0, $rows[3][21]);
    }
}

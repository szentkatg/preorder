<?php

namespace Tests\Feature;

use App\Exceptions\PricingParameterConflictException;
use App\Filament\Resources\PricingParameters\Pages\CreatePricingParameter;
use App\Filament\Resources\PricingParameters\Pages\ListPricingParameters;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\ItemMainGroupCodePattern;
use App\Services\Pricing\PricingConfigurationCopyService;
use App\Services\Pricing\PricingParameterResolver;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingGroupCodePatternTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private array $products = [];

    private Currency $currency;

    private PriceList $list;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $season = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Teszt', 'status' => 'active', 'active' => true]);
        $this->currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'active' => true]);
        $this->list = PriceList::create(['code' => 'RETAIL_HU', 'name' => 'Kisker', 'currency_id' => $this->currency->id, 'type' => 'retail', 'active' => true]);
        foreach (['S05', 'S06', 'S15', 'T05'] as $code) {
            $group = ItemMainGroup::create(['code' => $code, 'name' => $code === 'S05' ? 'cipő' : $code, 'active' => true]);
            $this->products[$code] = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
                'model_code' => 'MODEL'.$code, 'name_hu' => $code, 'material_composition' => 'PU', 'active' => true]);
        }
    }

    private function parameter(array $attributes = []): PricingParameter
    {
        return PricingParameter::create(array_replace(['pricing_project_id' => $this->project->id,
            'parameter_type' => 'customs_percent', 'scope_type' => 'item_main_group_pattern',
            'item_main_group_pattern' => 'S*', 'value' => 12, 'active' => true], $attributes));
    }

    private function resolve(string $code, bool $unique = true): ?PricingParameter
    {
        return app(PricingParameterResolver::class)->resolve($this->project->id, 'customs_percent', $this->products[$code],
            'CN', $this->list->id, $this->currency->id, $unique);
    }

    public function test_wildcard_matching_is_literal_case_insensitive_and_normalized(): void
    {
        $this->assertSame('S*', ItemMainGroupCodePattern::normalize(' s** '));
        foreach (['S05', 'S06', 'S15', 'S'] as $code) {
            $this->assertTrue(ItemMainGroupCodePattern::matches('s*', $code));
        }
        $this->assertFalse(ItemMainGroupCodePattern::matches('S*', 'T05'));
        $this->assertFalse(ItemMainGroupCodePattern::matches('S*', null));
        $this->assertTrue(ItemMainGroupCodePattern::matches('S0?', 'S05'));
        $this->assertFalse(ItemMainGroupCodePattern::matches('S0?', 'S015'));
        $this->assertTrue(ItemMainGroupCodePattern::matches('S_*', 'S_05'));
        $this->assertFalse(ItemMainGroupCodePattern::matches('S_*', 'SA05'));
        $this->assertFalse(ItemMainGroupCodePattern::matches('S.*', 'SA05'));
        $this->assertEqualsCanonicalizing(array_map(fn ($code) => $this->products[$code]->item_main_group_id, ['S05', 'S06', 'S15']), ItemMainGroupCodePattern::groupIds('S*'));
    }

    public function test_schema_upgrade_and_rollback_preserve_existing_parameters(): void
    {
        $parameter = $this->parameter(['scope_type' => 'global']);
        $before = Arr::except($parameter->fresh()->getAttributes(), ['item_main_group_pattern']);
        $migration = require database_path('migrations/2026_11_23_160000_add_group_code_pattern_to_pricing_parameters.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('pricing_parameters', 'item_main_group_pattern'));
        $this->assertSame($before, $parameter->fresh()->getAttributes());
        $migration->up();
        $this->assertNull($parameter->fresh()->item_main_group_pattern);
        $this->assertSame($before, Arr::except($parameter->fresh()->getAttributes(), ['item_main_group_pattern']));
    }

    public static function invalidPatterns(): array
    {
        return [[''], ['S05'], ['S *'], ['S%'], ['S[05]*'], [str_repeat('S', 100).'*']];
    }

    #[DataProvider('invalidPatterns')]
    public function test_invalid_patterns_are_rejected(string $pattern): void
    {
        $this->expectException(DomainException::class);
        $this->parameter(['item_main_group_pattern' => $pattern]);
    }

    public function test_exact_group_wins_and_existing_scope_hierarchy_is_preserved(): void
    {
        $global = $this->parameter(['scope_type' => 'global', 'value' => 1]);
        $country = $this->parameter(['scope_type' => 'supplier_country', 'supplier_country_code' => 'CN', 'value' => 2]);
        $pattern = $this->parameter(['price_list_id' => $this->list->id, 'currency_id' => $this->currency->id]);
        $this->assertTrue($this->resolve('S05')->is($pattern));
        $this->assertTrue($this->resolve('T05')->is($country));
        $exact = $this->parameter(['scope_type' => 'item_main_group', 'item_main_group_id' => $this->products['S05']->item_main_group_id, 'value' => 5]);
        $this->assertTrue($this->resolve('S05')->is($exact));
        $this->assertTrue($this->resolve('S06')->is($pattern));
        $material = $this->parameter(['scope_type' => 'material_composition', 'material_composition' => 'PU', 'value' => 7]);
        $this->assertTrue($this->resolve('S05')->is($material));
        $model = $this->parameter(['scope_type' => 'product', 'product_id' => $this->products['S05']->id, 'value' => 9]);
        $this->assertTrue($this->resolve('S05')->is($model));
        $this->products['T05']->material_composition = null;
        $country->update(['active' => false]);
        $this->assertTrue($this->resolve('T05')->is($global));
    }

    public function test_more_specific_pattern_wins_but_equal_overlapping_patterns_are_rejected(): void
    {
        $broad = $this->parameter();
        $narrow = $this->parameter(['item_main_group_pattern' => 'S0*', 'value' => 9]);
        $this->assertTrue($this->resolve('S05')->is($narrow));
        $this->assertTrue($this->resolve('S15')->is($broad));
        $this->expectException(PricingParameterConflictException::class);
        $this->expectExceptionMessage('átfedő');
        $this->parameter(['item_main_group_pattern' => '*05']);
    }

    public function test_legacy_ambiguity_is_not_silently_selected_even_in_cost_resolver(): void
    {
        $this->parameter(['item_main_group_pattern' => 'S0*']);
        $legacy = $this->parameter(['item_main_group_pattern' => '*05', 'active' => false]);
        $legacy->newQuery()->whereKey($legacy->id)->update(['active' => true]);
        $this->expectException(DomainException::class);
        $this->resolve('S05', unique: false);
    }

    public function test_pattern_identity_is_normalized_and_cleared_when_changing_scope(): void
    {
        $pattern = $this->parameter(['item_main_group_pattern' => 's**', 'product_id' => $this->products['S05']->id,
            'item_main_group_id' => $this->products['S05']->item_main_group_id]);
        $this->assertSame('S*', $pattern->fresh()->item_main_group_pattern);
        $this->assertNull($pattern->fresh()->product_id);
        $this->assertNull($pattern->fresh()->item_main_group_id);
        try {
            $this->parameter(['item_main_group_pattern' => ' s* ']);
            $this->fail('Normalized duplicate must be rejected.');
        } catch (PricingParameterConflictException) {
            $this->assertDatabaseCount('pricing_parameters', 1);
        }
        $pattern->update(['scope_type' => 'global']);
        $this->assertNull($pattern->fresh()->item_main_group_pattern);
    }

    public function test_form_and_list_show_code_and_name_and_support_code_search(): void
    {
        $exact = $this->parameter(['scope_type' => 'item_main_group', 'item_main_group_id' => $this->products['S05']->item_main_group_id]);
        Livewire::test(CreatePricingParameter::class)->fillForm(['scope_type' => 'item_main_group'])
            ->assertFormFieldExists('item_main_group_id', fn ($field) => $field->getOptions()[$this->products['S05']->item_main_group_id] === 'S05 - cipő'
                && $field->getSearchColumns() === ['code', 'name']);
        Livewire::test(ListPricingParameters::class)->assertSee('S05 - cipő')
            ->searchTableColumns(['itemMainGroup.name' => 'S05'])->assertCanSeeTableRecords([$exact]);
        Livewire::test(CreatePricingParameter::class)->fillForm(['pricing_project_id' => $this->project->id,
            'parameter_type' => 'customs_percent', 'scope_type' => 'item_main_group_pattern',
            'item_main_group_pattern' => 's*', 'value' => 12, 'active' => true])->call('create')->assertHasNoFormErrors();
        $this->assertTrue($this->resolve('S06')->item_main_group_pattern === 'S*');
        Livewire::test(CreatePricingParameter::class)->fillForm(['pricing_project_id' => $this->project->id,
            'parameter_type' => 'customs_percent', 'scope_type' => 'item_main_group_pattern',
            'item_main_group_pattern' => 'S05', 'value' => 12])->call('create')->assertHasFormErrors(['item_main_group_pattern']);
    }

    public function test_copy_preserves_patterns_and_conflict_modes(): void
    {
        $source = $this->parameter();
        $target = $this->project->replicate();
        $target->save();
        $service = app(PricingConfigurationCopyService::class);
        $preview = $service->preview(PricingParameter::class, [$source->id], $target->id);
        $result = $service->copy(PricingParameter::class, [$source->id], $target->id, $preview['token']);
        $this->assertSame(1, $result['created']);
        $copied = $target->parameters()->sole();
        $this->assertSame('S*', $copied->item_main_group_pattern);
        $copied->update(['value' => 20]);
        $preview = $service->preview(PricingParameter::class, [$source->id], $target->id);
        $this->assertSame(1, $preview['conflicts']);
        $this->assertSame(1, $service->copy(PricingParameter::class, [$source->id], $target->id, $preview['token'], 'skip')['skipped']);
        $this->assertEquals(20, $copied->fresh()->value);
        $this->assertSame(1, $service->copy(PricingParameter::class, [$source->id], $target->id, $preview['token'], 'overwrite')['overwritten']);
        $this->assertEquals(12, $copied->fresh()->value);
    }

    public function test_copy_rejects_equal_overlaps_and_rolls_back_other_copies(): void
    {
        $global = $this->parameter(['scope_type' => 'global']);
        $source = $this->parameter(['item_main_group_pattern' => 'S0*']);
        $target = $this->project->replicate();
        $target->save();
        $this->parameter(['pricing_project_id' => $target->id, 'item_main_group_pattern' => '*05']);
        $service = app(PricingConfigurationCopyService::class);
        $preview = $service->preview(PricingParameter::class, [$global->id, $source->id], $target->id);
        try {
            $service->copy(PricingParameter::class, [$global->id, $source->id], $target->id, $preview['token']);
            $this->fail('Ambiguous copy must not be saved.');
        } catch (PricingParameterConflictException) {
            $this->assertSame(1, $target->parameters()->count());
        }
    }

    public function test_outlet_invalidation_only_touches_matching_groups_in_both_old_and_new_scope(): void
    {
        foreach ($this->products as $product) {
            PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $product->id,
                'price_type' => 'outlet', 'price_list_id' => $this->list->id, 'currency_id' => $this->currency->id,
                'calculated_price' => 100, 'requires_review' => false]);
        }
        $pattern = $this->parameter(['parameter_type' => 'outlet_discount', 'item_main_group_pattern' => 'S0*']);
        $reviewed = fn () => $this->project->calculationRows()->where('requires_review', true)->pluck('product_id')->all();
        $this->assertEqualsCanonicalizing([$this->products['S05']->id, $this->products['S06']->id], $reviewed());
        $this->project->calculationRows()->update(['requires_review' => false]);
        $pattern->update(['item_main_group_pattern' => 'T*']);
        $this->assertEqualsCanonicalizing([$this->products['S05']->id, $this->products['S06']->id, $this->products['T05']->id], $reviewed());
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\PricingRoundingRules\Pages\ListPricingRoundingRules;
use App\Filament\Resources\PricingRoundingRules\Schemas\PricingRoundingRuleForm;
use App\Models\Currency;
use App\Models\PriceList;
use App\Models\PricingProject;
use App\Models\PricingRoundingRule;
use App\Models\Season;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingRoundingRuleFiltersTest extends TestCase
{
    use RefreshDatabase;

    private array $projects;

    private array $lists;

    private array $rules;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);

        $season = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true]);
        $currency = Currency::create(['code' => 'HUF', 'name_hu' => 'Forint', 'active' => true]);
        foreach (['Első', 'Második', 'Harmadik'] as $name) {
            $this->projects[] = PricingProject::create(['season_id' => $season->id, 'name' => $name,
                'status' => 'active', 'active' => true]);
        }
        foreach (['retail', 'wholesale', 'outlet'] as $type) {
            $this->lists[] = PriceList::create(['code' => strtoupper($type).'_HU', 'name_hu' => $type,
                'type' => $type, 'currency_id' => $currency->id, 'active' => true]);
        }
        $this->rules = [
            $this->rule(0, 'retail', 0),
            $this->rule(0, 'wholesale', 1),
            $this->rule(0, 'outlet', 2),
            $this->rule(1, 'retail', 0),
            $this->rule(1, 'outlet', 2),
            $this->rule(0, 'cost', null),
            $this->rule(2, 'retail', 0),
        ];
    }

    private function rule(int $project, string $type, ?int $list): PricingRoundingRule
    {
        return PricingRoundingRule::create(['pricing_project_id' => $this->projects[$project]->id,
            'name' => $this->projects[$project]->name.' '.$type, 'price_type' => $type,
            'price_list_id' => $list === null ? null : $this->lists[$list]->id,
            'round_to' => 100, 'mode' => 'nearest', 'adjustment' => 0, 'active' => true]);
    }

    public function test_filters_are_searchable_multiple_selects_with_hungarian_labels_and_all_price_types(): void
    {
        $page = Livewire::test(ListPricingRoundingRules::class)->assertCountTableRecords(7);
        foreach (['pricing_project_id' => 'Árprojekt', 'price_type' => 'Ártípus', 'price_list_id' => 'Árlista'] as $name => $label) {
            $filter = $page->instance()->getTable()->getFilter($name);
            $this->assertTrue($filter->isMultiple());
            $this->assertTrue($filter->getSearchable());
            $this->assertSame($label, $filter->getLabel());
        }
        $this->assertSame(['cost' => 'Bekerülési érték', 'retail' => 'Kisker', 'wholesale' => 'Nagyker',
            'distributor' => 'Disztribútor', 'outlet' => 'Outlet'], PricingRoundingRuleForm::priceTypeOptions());
        $this->assertSame(PricingRoundingRuleForm::priceTypeOptions(),
            $page->instance()->getTable()->getFilter('price_type')->getOptions());
    }

    public function test_multiple_values_match_with_or_and_different_filters_combine_with_and(): void
    {
        $page = Livewire::test(ListPricingRoundingRules::class)
            ->filterTable('pricing_project_id', [$this->projects[0]->id, $this->projects[1]->id])
            ->assertCountTableRecords(6)->assertCanNotSeeTableRecords([$this->rules[6]])
            ->filterTable('price_type', ['retail', 'wholesale'])->assertCountTableRecords(3)
            ->filterTable('price_list_id', [$this->lists[0]->id, $this->lists[1]->id])
            ->assertCountTableRecords(3)->assertCanSeeTableRecords([$this->rules[0], $this->rules[1], $this->rules[3]])
            ->filterTable('price_list_id', [$this->lists[1]->id])->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([$this->rules[1]]);
        $page->filterTable('pricing_project_id', [$this->projects[1]->id])->assertCountTableRecords(0);
        $page->filterTable('price_type', ['outlet'])->filterTable('price_list_id', [$this->lists[2]->id])
            ->assertCountTableRecords(1)->assertCanSeeTableRecords([$this->rules[4]]);
    }

    public function test_filters_persist_after_reentry_and_reset_restores_rules_without_a_price_list(): void
    {
        $page = Livewire::test(ListPricingRoundingRules::class)
            ->filterTable('pricing_project_id', [(string) $this->projects[0]->id])
            ->filterTable('price_type', ['retail', 'outlet'])
            ->filterTable('price_list_id', [(string) $this->lists[0]->id, (string) $this->lists[2]->id])
            ->assertCountTableRecords(2);
        $filters = $page->instance()->tableFilters;
        $restored = Livewire::test(ListPricingRoundingRules::class)->assertCountTableRecords(2)
            ->assertCanSeeTableRecords([$this->rules[0], $this->rules[2]]);
        $this->assertSame($filters, $restored->instance()->tableFilters);
        $restored->call('removeTableFilters')->assertCountTableRecords(7)->assertCanSeeTableRecords([$this->rules[5]]);
        Livewire::test(ListPricingRoundingRules::class)->assertCountTableRecords(7);
    }

    public function test_panel_applies_deferred_selection_and_retains_the_close_after_apply_handler(): void
    {
        $page = Livewire::test(ListPricingRoundingRules::class)->assertCountTableRecords(7);
        $this->assertSame('await $wire.applyTableFilters(); close()',
            $page->instance()->getTable()->getFiltersApplyAction()->getAlpineClickHandler());
        $page->set('tableDeferredFilters.price_type.values', ['outlet', 'wholesale'])->assertCountTableRecords(7);
        $page->call('applyTableFilters')->assertCountTableRecords(3)
            ->assertCanSeeTableRecords([$this->rules[1], $this->rules[2], $this->rules[4]]);
    }
}

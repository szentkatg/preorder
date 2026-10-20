<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\ExchangeRates\Pages\ListExchangeRates;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Language;
use App\Models\Season;
use App\Models\Translation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExchangeRateListTest extends TestCase
{
    use RefreshDatabase;

    public function test_currency_code_and_name_are_displayed_and_season_filter_is_multiple(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $currency = Currency::create(['code' => 'USD', 'name_hu' => 'Régi dollárnév', 'active' => true]);
        $language = Language::firstOrCreate(['code' => 'HU'], ['name' => 'Magyar', 'name_hu' => 'Magyar', 'active' => true]);
        Translation::create(['entity' => 'currency', 'entity_code' => 'USD', 'field' => 'name', 'language_id' => $language->id, 'value' => 'Amerikai dollár']);
        $first = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true]);
        $second = Season::create(['code' => 'S27', 'name' => 'Nyár', 'active' => true]);
        $one = ExchangeRate::create(['currency_id' => $currency->id, 'season_id' => $first->id, 'rate_to_huf' => 370, 'active' => true]);
        $two = ExchangeRate::create(['currency_id' => $currency->id, 'season_id' => $second->id, 'rate_to_huf' => 380, 'active' => true]);
        $page = Livewire::test(ListExchangeRates::class)->assertSee('USD')->assertSee('Amerikai dollár')->assertDontSee('Régi dollárnév')
            ->assertTableColumnExists('currency.code')->assertTableColumnExists('currency.name')
            ->assertCanSeeTableRecords([$one, $two])->filterTable('season_id', [$first->id])
            ->assertCanSeeTableRecords([$one])->assertCanNotSeeTableRecords([$two]);
        $page->filterTable('season_id', [$first->id, $second->id])->assertCanSeeTableRecords([$one, $two]);
        $this->assertNull($page->instance()->getTable()->getColumn('currency.id'));
    }
}

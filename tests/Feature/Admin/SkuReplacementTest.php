<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\SkuReplacements\Pages\CreateSkuReplacement;
use App\Filament\Resources\SkuReplacements\Pages\EditSkuReplacement;
use App\Filament\Resources\SkuReplacements\Pages\ListSkuReplacements;
use App\Filament\Resources\SkuReplacements\Pages\ViewSkuReplacement;
use App\Models\Color;
use App\Models\ItemMainGroup;
use App\Models\Product;
use App\Models\Season;
use App\Models\Sku;
use App\Models\SkuReplacement;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SkuReplacementTest extends TestCase
{
    use RefreshDatabase;

    private Season $season;

    private Season $otherSeason;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $this->season = Season::create(['code' => 'W27', 'name' => 'Tél']);
        $this->otherSeason = Season::create(['code' => 'S27', 'name' => 'Nyár']);
        $group = ItemMainGroup::create(['code' => 'B01', 'name' => 'Táska']);
        foreach ([$this->season, $this->otherSeason] as $season) {
            $product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
                'model_code' => 'M'.$season->code, 'name_hu' => 'Modell']);
            $color = Color::create(['product_id' => $product->id, 'code' => '01', 'name_hu' => 'BLACK']);
            foreach (['A', 'B', 'C', 'PACK'] as $code) {
                Sku::create(['product_id' => $product->id, 'color_id' => $color->id, 'sku_code' => $season->code.'-'.$code,
                    'sku_name' => $code, 'type' => $code === 'PACK' ? 'assortment' : 'piece']);
            }
        }
    }

    private function mapping(string $old = 'W27-A', string $new = 'W27-B'): SkuReplacement
    {
        return SkuReplacement::create(['season_id' => $this->season->id, 'old_sku_code' => $old, 'new_sku_code' => $new]);
    }

    private function field($page, string $name): Select
    {
        $field = $page->instance()->form->getComponentByStatePath($name);
        $this->assertInstanceOf(Select::class, $field);

        return $field;
    }

    public function test_sku_selectors_search_substrings_in_both_codes_and_names_and_only_old_skus_exclude_existing_rules(): void
    {
        Sku::where('sku_code', 'W27-A')->update(['sku_name' => 'EMIRO BLACK M']);
        Sku::where('sku_code', 'W27-B')->update(['sku_name' => 'EMIRO NAVY M']);
        Sku::where('sku_code', 'W27-C')->update(['sku_name' => 'ENNIO BLACK L']);
        Sku::where('sku_code', 'S27-A')->update(['sku_name' => 'EMIRO BLACK M']);
        $this->mapping();
        $page = Livewire::test(CreateSkuReplacement::class)->fillForm(['season_id' => $this->season->id]);
        $old = $this->field($page, 'old_sku_code');
        $new = $this->field($page, 'new_sku_code');
        $this->assertSame(['W27-B' => 'W27-B – EMIRO NAVY M'], $old->getSearchResults('miro'));
        $this->assertSame(['W27-C' => 'W27-C – ENNIO BLACK L'], $old->getSearchResults('nNi'));
        $this->assertSame(['W27-C' => 'W27-C – ENNIO BLACK L'], $old->getSearchResults('w27-c'));
        $this->assertSame(['W27-A' => 'W27-A – EMIRO BLACK M', 'W27-B' => 'W27-B – EMIRO NAVY M'], $new->getSearchResults('EMIRO'));
        $this->assertSame([], $old->getSearchResults('S27'));
        $this->assertSame(['TÖRÖLT' => 'TÖRÖLT'], $new->getSearchResults('tör'));
        $this->assertSame([], $old->getSearchResults('tör'));
        $page->fillForm(['old_sku_code' => 'W27-A', 'new_sku_code' => 'W27-C'])->call('create')
            ->assertHasFormErrors(['old_sku_code']);
        $this->assertDatabaseCount('sku_replacements', 1);
    }

    public function test_editing_preserves_current_sku_labels_but_excludes_other_mapped_old_skus(): void
    {
        $record = $this->mapping();
        $this->mapping('W27-B', 'W27-C');
        $page = Livewire::test(EditSkuReplacement::class, ['record' => $record->id]);
        $old = $this->field($page, 'old_sku_code');
        $new = $this->field($page, 'new_sku_code');
        $this->assertSame('W27-A – A', $old->getOptionLabel());
        $this->assertSame('W27-B – B', $new->getOptionLabel());
        $this->assertArrayHasKey('W27-A', $old->getSearchResults('W27'));
        $this->assertArrayNotHasKey('W27-B', $old->getSearchResults('W27'));
        $this->assertArrayHasKey('W27-B', $new->getSearchResults('W27'));
        $page->call('save')->assertHasNoFormErrors();
        $this->assertSame('W27-A', $record->fresh()->old_sku_code);
    }

    public function test_multiple_old_skus_can_use_the_same_new_sku(): void
    {
        $this->mapping('W27-A', 'W27-C');
        $page = Livewire::test(CreateSkuReplacement::class)->fillForm(['season_id' => $this->season->id]);
        $this->assertArrayHasKey('W27-C', $this->field($page, 'new_sku_code')->getSearchResults('W27-C'));
        // A code used as a target is still a valid source, unless it already has its own rule.
        $this->assertArrayHasKey('W27-C', $this->field($page, 'old_sku_code')->getSearchResults('W27-C'));
        $page->fillForm(['old_sku_code' => 'W27-B', 'new_sku_code' => 'W27-C'])->call('create')->assertHasNoFormErrors();
        $this->assertSame(2, SkuReplacement::where('new_sku_code', 'W27-C')->count());
    }

    public function test_selectors_wait_for_a_season_and_clear_both_selections_when_it_changes(): void
    {
        $page = Livewire::test(CreateSkuReplacement::class);
        $this->assertTrue($this->field($page, 'old_sku_code')->isDisabled());
        $this->assertSame([], $this->field($page, 'new_sku_code')->getSearchResults(''));
        $page->fillForm(['season_id' => $this->season->id, 'old_sku_code' => 'W27-A', 'new_sku_code' => 'W27-B'])
            ->set('data.season_id', $this->otherSeason->id)->assertSet('data.old_sku_code', null)->assertSet('data.new_sku_code', null);
        $this->assertSame([], $this->field($page, 'old_sku_code')->getSearchResults('W27'));
        $this->assertArrayHasKey('S27-A', $this->field($page, 'old_sku_code')->getSearchResults('S27-A'));
    }

    public function test_search_results_are_bounded_but_codes_outside_the_first_page_can_be_selected_and_saved(): void
    {
        $template = Sku::where('sku_code', 'W27-A')->firstOrFail();
        for ($index = 0; $index < 60; $index++) {
            $template->replicate()->fill(['sku_code' => sprintf('ZZ-%03d', $index), 'sku_name' => 'Tömeges cikk '.$index])->save();
        }
        $page = Livewire::test(CreateSkuReplacement::class)->fillForm(['season_id' => $this->season->id]);
        $this->assertCount(50, $this->field($page, 'old_sku_code')->getOptions());
        $this->assertArrayNotHasKey('ZZ-059', $this->field($page, 'new_sku_code')->getOptions());
        $this->assertSame(['ZZ-059' => 'ZZ-059 – Tömeges cikk 59'], $this->field($page, 'new_sku_code')->getSearchResults('cikk 59'));
        $page->fillForm(['old_sku_code' => 'ZZ-058', 'new_sku_code' => 'ZZ-059']);
        $this->assertSame('ZZ-059 – Tömeges cikk 59', $this->field($page, 'new_sku_code')->getOptionLabel());
        $page->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('sku_replacements', ['old_sku_code' => 'ZZ-058', 'new_sku_code' => 'ZZ-059']);
    }

    public function test_search_treats_percent_and_underscore_as_literal_characters(): void
    {
        Sku::where('sku_code', 'W27-A')->update(['sku_name' => '95% COTTON']);
        Sku::where('sku_code', 'W27-B')->update(['sku_name' => '95X COTTON']);
        Sku::where('sku_code', 'W27-C')->update(['sku_name' => 'A_B']);
        $page = Livewire::test(CreateSkuReplacement::class)->fillForm(['season_id' => $this->season->id]);
        $this->assertSame(['W27-A' => 'W27-A – 95% COTTON'], $this->field($page, 'old_sku_code')->getSearchResults('%'));
        $this->assertSame(['W27-C' => 'W27-C – A_B'], $this->field($page, 'old_sku_code')->getSearchResults('_'));
    }

    public function test_list_displays_both_sku_names_and_supports_name_search_and_sorting(): void
    {
        Sku::where('sku_code', 'W27-A')->update(['sku_name' => 'EMIRO BLACK M']);
        Sku::where('sku_code', 'W27-B')->update(['sku_name' => 'EMIRO NAVY M']);
        $record = $this->mapping();
        $deleted = $this->mapping('W27-C', 'TÖRÖLT');
        $page = Livewire::test(ListSkuReplacements::class)
            ->assertTableColumnStateSet('oldSku.sku_name', 'EMIRO BLACK M', $record)
            ->assertTableColumnStateSet('newSku.sku_name', 'EMIRO NAVY M', $record)
            ->assertTableColumnStateSet('newSku.sku_name', null, $deleted);
        $page->searchTableColumns(['oldSku.sku_name' => 'BLACK'])->sortTable('newSku.sku_name')
            ->assertCanSeeTableRecords([$record])->assertCanNotSeeTableRecords([$deleted]);
    }

    public function test_admin_can_create_edit_filter_and_delete_replacements(): void
    {
        Livewire::test(CreateSkuReplacement::class)->fillForm(['season_id' => $this->season->id,
            'old_sku_code' => 'W27-A', 'new_sku_code' => 'W27-B'])->call('create')->assertHasNoFormErrors();
        $record = SkuReplacement::firstOrFail();
        Livewire::test(EditSkuReplacement::class, ['record' => $record->id])->fillForm(['new_sku_code' => ' törölt '])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('TÖRÖLT', $record->fresh()->new_sku_code);
        $other = SkuReplacement::create(['season_id' => $this->otherSeason->id, 'old_sku_code' => 'S27-A', 'new_sku_code' => 'S27-B']);
        Livewire::test(ListSkuReplacements::class)->filterTable('season_id', [(string) $this->season->id])
            ->assertCanSeeTableRecords([$record])->assertCanNotSeeTableRecords([$other])
            ->searchTableColumns(['new_sku_code' => 'TÖRÖLT'])->sortTable('old_sku_code')->assertCountTableRecords(1)
            ->callTableBulkAction('delete', [$record])->assertHasNoTableBulkActionErrors();
        $this->assertDatabaseMissing('sku_replacements', ['id' => $record->id]);
        $this->assertDatabaseHas('sku_replacements', ['id' => $other->id]);
    }

    public function test_saving_rejects_self_replacements_and_circular_chains(): void
    {
        $this->mapping('W27-A', 'W27-B');
        $this->mapping('W27-B', 'W27-C');
        foreach ([['W27-C', 'W27-A'], ['W27-C', 'W27-C']] as [$old, $new]) {
            try {
                $this->mapping($old, $new);
                $this->fail('A circular mapping was saved.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('new_sku_code', $exception->errors());
            }
        }
        $this->assertDatabaseCount('sku_replacements', 2);
    }

    public function test_invalid_mapping_errors_are_visible_in_the_admin_form(): void
    {
        Livewire::test(CreateSkuReplacement::class)->fillForm(['season_id' => $this->season->id,
            'old_sku_code' => 'W27-A', 'new_sku_code' => 'MISSING'])->call('create')
            ->assertHasFormErrors(['new_sku_code']);
        $this->assertDatabaseCount('sku_replacements', 0);
        $record = $this->mapping();
        Livewire::test(EditSkuReplacement::class, ['record' => $record->id])->fillForm(['new_sku_code' => 'W27-A'])
            ->call('save')->assertHasFormErrors(['new_sku_code']);
        $this->assertSame('W27-B', $record->fresh()->new_sku_code);
    }

    public function test_existing_mapping_can_be_updated_but_not_duplicated(): void
    {
        $record = $this->mapping();
        $record->update(['new_sku_code' => 'W27-C']);
        $this->assertSame('W27-C', $record->fresh()->new_sku_code);
        $this->expectException(ValidationException::class);
        $this->mapping('W27-A', 'TÖRÖLT');
    }

    public function test_invalid_and_cross_season_codes_are_rejected(): void
    {
        foreach ([['MISSING', 'W27-B', 'old_sku_code'], ['W27-A', 'S27-B', 'new_sku_code'], ['W27-A', 'MISSING', 'new_sku_code']] as [$old, $new, $field]) {
            try {
                $this->mapping($old, $new);
                $this->fail('An invalid/cross-season mapping was saved.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
        $this->assertDatabaseCount('sku_replacements', 0);
    }

    public function test_assortment_codes_are_valid_replacement_sources_and_targets(): void
    {
        $record = $this->mapping('W27-A', 'W27-PACK');
        $this->assertSame('W27-PACK', $record->new_sku_code);
        $this->mapping('W27-PACK', 'TÖRÖLT');
        $this->assertDatabaseCount('sku_replacements', 2);
    }

    public function test_view_only_roles_cannot_mutate_the_new_admin_resource(): void
    {
        $record = $this->mapping();
        $user = User::factory()->create();
        $user->givePermissionTo([Permission::findByName('ViewAny:SkuReplacement', 'web'), Permission::findByName('View:SkuReplacement', 'web')]);
        $this->actingAs($user);
        Livewire::test(ListSkuReplacements::class)->assertSuccessful()->assertCanSeeTableRecords([$record])
            ->assertActionHidden('create')->assertTableActionHidden('edit', $record)->assertTableBulkActionHidden('delete');
        Livewire::test(ViewSkuReplacement::class, ['record' => $record->id])->assertSuccessful();
        $this->assertFalse(Gate::allows('create', SkuReplacement::class));
        $this->assertFalse(Gate::allows('update', $record));
        $this->assertFalse(Gate::allows('delete', $record));
        Livewire::test(CreateSkuReplacement::class)->assertForbidden();
        Livewire::test(EditSkuReplacement::class, ['record' => $record->id])->assertForbidden();
    }

    public function test_migration_does_not_grant_new_permissions_to_existing_roles(): void
    {
        $this->assertSame(6, Permission::where('name', 'like', '%:SkuReplacement')->count());
        $role = Role::findOrCreate('pricing_admin', 'web');
        $this->assertSame(0, $role->permissions()->where('name', 'like', '%:SkuReplacement')->count());
    }
}

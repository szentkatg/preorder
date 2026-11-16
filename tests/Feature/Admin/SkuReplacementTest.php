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

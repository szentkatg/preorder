<?php

namespace Tests\Feature;

use App\Filament\Resources\PricingParameters\Pages\ListPricingParameters;
use App\Filament\Resources\PricingRoundingRules\Pages\ListPricingRoundingRules;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\PricingRoundingRule;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\PricingConfigurationCopyService;
use DomainException;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingConfigurationCopyTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $source;

    private PricingProject $target;

    private Product $product;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $season = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'COPY', 'name_hu' => 'Másolás', 'active' => true]);
        $this->currency = Currency::create(['code' => 'HUF', 'name_hu' => 'Forint', 'active' => true]);
        $this->product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
            'model_code' => 'COPY-1', 'name_hu' => 'Másolás', 'active' => true]);
        $this->source = PricingProject::create(['season_id' => $season->id, 'name' => 'Forrás', 'status' => 'active', 'active' => true]);
        $this->target = PricingProject::create(['season_id' => $season->id, 'name' => 'Cél', 'status' => 'draft', 'active' => true]);
    }

    public static function configurations(): array
    {
        return ['parameters' => [PricingParameter::class, ListPricingParameters::class],
            'rounding' => [PricingRoundingRule::class, ListPricingRoundingRules::class]];
    }

    private function row(string $model, array $extra = []): Model
    {
        $defaults = $model === PricingParameter::class
            ? ['parameter_type' => 'retail_multiplier', 'scope_type' => 'global', 'value' => '4.712345']
            : ['name' => 'Kisker 990', 'price_type' => 'retail', 'round_to' => '1000', 'decimal_places' => 2, 'mode' => 'nearest', 'adjustment' => '-10'];

        return $model::create(array_replace(['pricing_project_id' => $this->source->id,
            'currency_id' => $this->currency->id, 'active' => true] + $defaults, $extra));
    }

    private function execute(Model $source, ?string $mode = null): array
    {
        $service = app(PricingConfigurationCopyService::class);
        $preview = $service->preview($source::class, [$source->id], $this->target->id);

        return $service->copy($source::class, [$source->id], $this->target->id, $preview['token'], $mode);
    }

    /** Simulate pre-validation database rows; normal saves must no longer create these. */
    private function legacyRow(string $model, array $extra = []): Model
    {
        $row = $this->row($model, array_replace($extra, ['active' => false]));
        $row->newQuery()->whereKey($row->id)->update(['active' => $extra['active'] ?? true]);

        return $row->fresh();
    }

    #[DataProvider('configurations')]
    public function test_bulk_modal_previews_then_copies_only_selected_rows_and_preserves_source(string $model, string $pageClass): void
    {
        $row = $this->row($model);
        $other = $this->row($model, ['currency_id' => null]);
        $before = $row->fresh()->getAttributes();
        $page = Livewire::test($pageClass)->mountTableBulkAction('copyToPricingProject', [$row])
            ->set('mountedActions.0.data.target_project_id', (string) $this->target->id);
        $this->assertSame(0, $page->instance()->mountedActions[0]['data']['conflict_count']);
        $this->assertNotEmpty($page->instance()->mountedActions[0]['data']['preview_token']);
        $this->assertSame(0, $model::where('pricing_project_id', $this->target->id)->count());
        $page->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();
        $this->assertSame(1, $model::where('pricing_project_id', $this->target->id)->count());
        $page->assertDispatched('deselectAllTableRecords')->assertSet('mountedActions', []);
        $copy = $model::where('pricing_project_id', $this->target->id)->sole();
        $this->assertSame($row->only(array_diff($row->getFillable(), ['pricing_project_id'])),
            $copy->only(array_diff($copy->getFillable(), ['pricing_project_id'])));
        $this->assertNotEquals($row->id, $copy->id);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertModelExists($other);
    }

    #[DataProvider('configurations')]
    public function test_cancel_and_missing_collision_choice_do_not_write_anything(string $model, string $pageClass): void
    {
        $row = $this->row($model);
        $existing = $this->row($model, ['pricing_project_id' => $this->target->id]);
        $before = $existing->fresh()->getAttributes();
        $page = Livewire::test($pageClass)->mountTableBulkAction('copyToPricingProject', [$row])
            ->set('mountedActions.0.data.target_project_id', (string) $this->target->id);
        $this->assertSame(1, $page->instance()->mountedActions[0]['data']['conflict_count']);
        $this->assertNull($page->instance()->mountedActions[0]['data']['conflict_mode']);
        $page->callMountedTableBulkAction()->assertHasTableBulkActionErrors(['conflict_mode' => 'required']);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $page->call('unmountAction');
        $this->assertSame($before, $existing->fresh()->getAttributes());
    }

    #[DataProvider('configurations')]
    public function test_skip_copies_only_new_keys_and_overwrite_preserves_existing_id(string $model, string $pageClass): void
    {
        $row = $this->row($model);
        $other = $this->row($model, ['currency_id' => null]);
        $changed = $model === PricingParameter::class ? ['value' => 2] : ['name' => 'Régi név', 'round_to' => 100, 'adjustment' => 0];
        $existing = $this->row($model, ['pricing_project_id' => $this->target->id] + $changed);
        $before = $existing->fresh()->getAttributes();
        $service = app(PricingConfigurationCopyService::class);
        $preview = $service->preview($model, [$row->id, $other->id], $this->target->id);
        $this->assertSame(1, $preview['conflicts']);
        $result = $service->copy($model, [$row->id, $other->id], $this->target->id, $preview['token'], 'skip');
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $result = $this->execute($row, 'overwrite');
        $this->assertSame(1, $result['overwritten']);
        $this->assertSame(2, $model::where('pricing_project_id', $this->target->id)->count());
        $this->assertSame($row->only(array_diff($row->getFillable(), ['pricing_project_id'])),
            $existing->fresh()->only(array_diff($existing->getFillable(), ['pricing_project_id'])));
    }

    public function test_parameter_keys_use_scope_specific_dimensions_and_keep_price_and_currency_distinct(): void
    {
        $global = $this->row(PricingParameter::class);
        $country = $this->row(PricingParameter::class, ['scope_type' => 'supplier_country', 'supplier_country_code' => 'cn']);
        $group = $this->row(PricingParameter::class, ['scope_type' => 'item_main_group', 'item_main_group_id' => $this->product->item_main_group_id]);
        $product = $this->row(PricingParameter::class, ['scope_type' => 'product', 'product_id' => $this->product->id]);
        $specific = $this->row(PricingParameter::class, ['currency_id' => null]);
        $existing = $this->row(PricingParameter::class, ['pricing_project_id' => $this->target->id,
            'product_id' => $this->product->id, 'item_main_group_id' => $this->product->item_main_group_id, 'value' => 2]);
        $countryTarget = $this->row(PricingParameter::class, ['pricing_project_id' => $this->target->id,
            'scope_type' => 'supplier_country', 'supplier_country_code' => 'CN', 'value' => 1]);
        $service = app(PricingConfigurationCopyService::class);
        $ids = [$global->id, $country->id, $group->id, $product->id, $specific->id];
        $preview = $service->preview(PricingParameter::class, $ids, $this->target->id);
        $this->assertSame(2, $preview['conflicts']);
        $result = $service->copy(PricingParameter::class, $ids, $this->target->id, $preview['token'], 'overwrite');
        $this->assertSame(3, $result['created']);
        $this->assertSame(2, $result['overwritten']);
        $this->assertNull($existing->fresh()->product_id);
        $this->assertNull($existing->fresh()->item_main_group_id);
        $this->assertSame('CN', $countryTarget->fresh()->supplier_country_code);
    }

    #[DataProvider('configurations')]
    public function test_source_duplicates_are_rejected_and_target_duplicates_can_only_be_skipped(string $model): void
    {
        $row = $this->row($model);
        $duplicate = $this->legacyRow($model);
        $service = app(PricingConfigurationCopyService::class);
        try {
            $service->preview($model, [$row->id, $duplicate->id], $this->target->id);
            $this->fail('Multiple source values for a key must not be silently resolved by record order.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('forrássor', $exception->getMessage());
        }
        $this->row($model, ['pricing_project_id' => $this->target->id]);
        $this->legacyRow($model, ['pricing_project_id' => $this->target->id]);
        $preview = $service->preview($model, [$row->id], $this->target->id);
        $this->assertSame(1, $preview['ambiguous']);
        $this->assertFalse($preview['can_overwrite']);
        try {
            $service->copy($model, [$row->id], $this->target->id, $preview['token'], 'overwrite');
            $this->fail('Ambiguous target rows must not be overwritten arbitrarily.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('duplikáció', $exception->getMessage());
        }
        $result = $service->copy($model, [$row->id], $this->target->id, $preview['token'], 'skip');
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(2, $model::where('pricing_project_id', $this->target->id)->count());
    }

    #[DataProvider('configurations')]
    public function test_closed_sources_are_readable_but_same_or_closed_targets_are_not_writable(string $model): void
    {
        $row = $this->row($model);
        $service = app(PricingConfigurationCopyService::class);
        $this->source->update(['status' => 'closed']);
        $options = $service->targetOptions($model, collect([$row]));
        $this->assertArrayHasKey($this->target->id, $options);
        $this->assertArrayNotHasKey($this->source->id, $options);
        $this->assertSame(1, $this->execute($row)['created']);
        foreach ([$this->source->id, $this->target->id] as $id) {
            if ($id === $this->target->id) {
                $this->target->update(['status' => 'closed']);
            }
            try {
                $service->preview($model, [$row->id], $id);
                $this->fail('Closed or same-project targets must be rejected.');
            } catch (DomainException) {
                $this->assertSame(1, $model::where('pricing_project_id', $this->target->id)->count());
            }
        }
    }

    #[DataProvider('configurations')]
    public function test_new_collision_or_changed_source_invalidates_confirmation_token(string $model): void
    {
        $row = $this->row($model);
        $service = app(PricingConfigurationCopyService::class);
        $preview = $service->preview($model, [$row->id], $this->target->id);
        $this->row($model, ['pricing_project_id' => $this->target->id]);
        try {
            $service->copy($model, [$row->id], $this->target->id, $preview['token']);
            $this->fail('A new conflict must require another confirmation.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('megváltoztak', $exception->getMessage());
        }
        $preview = $service->preview($model, [$row->id], $this->target->id);
        $row->update($model === PricingParameter::class ? ['value' => 9] : ['adjustment' => -1]);
        try {
            $service->copy($model, [$row->id], $this->target->id, $preview['token'], 'overwrite');
            $this->fail('Changed source data must not be copied using an old confirmation.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('megváltoztak', $exception->getMessage());
        }
        $this->assertSame(1, $model::where('pricing_project_id', $this->target->id)->count());
    }

    #[DataProvider('configurations')]
    public function test_public_prices_and_source_stay_unchanged_and_target_prices_are_only_flagged(string $model): void
    {
        $row = $this->row($model);
        $list = PriceList::create(['code' => 'COPY-RETAIL', 'name_hu' => 'Kisker', 'currency_id' => $this->currency->id, 'type' => 'retail', 'active' => true]);
        $item = PriceListItem::create(['price_list_id' => $list->id, 'season_id' => $this->target->season_id,
            'product_id' => $this->product->id, 'net_price' => 1000]);
        $price = PricingCalculationRow::create(['pricing_project_id' => $this->target->id, 'product_id' => $this->product->id,
            'price_type' => 'retail', 'price_list_id' => $list->id, 'currency_id' => $this->currency->id,
            'status' => 'modified_approved', 'manual_price' => 1200, 'calculated_price' => 1000]);
        $before = $price->getAttributes();
        $publication = $this->target->publications()->create(['published_at' => now(), 'published_by' => auth()->id(), 'snapshot' => ['prices' => [['price' => '1000']]]]);
        $snapshot = $publication->fresh()->getAttributes();
        $result = $this->execute($row);
        $this->assertSame(1, $result['marked_for_review']);
        $this->assertTrue($price->fresh()->requires_review);
        $this->assertEquals($before['final_price'], $price->fresh()->final_price);
        $this->assertSame('modified_approved', $price->fresh()->status);
        $this->assertEquals(1000, $item->fresh()->net_price);
        $this->assertSame($snapshot, $publication->fresh()->getAttributes());
    }

    #[DataProvider('configurations')]
    public function test_read_only_role_cannot_copy_and_create_only_role_cannot_overwrite(string $model, string $pageClass): void
    {
        $row = $this->row($model);
        $existing = $this->row($model, ['pricing_project_id' => $this->target->id]);
        $name = class_basename($model);
        $user = User::factory()->create();
        foreach (['ViewAny:'.$name, 'View:'.$name, 'ViewAny:PricingProject', 'View:PricingProject', 'Update:PricingProject'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);
        Livewire::test($pageClass)->assertTableBulkActionHidden('copyToPricingProject');
        $service = app(PricingConfigurationCopyService::class);
        try {
            $service->preview($model, [$row->id], $this->target->id);
            $this->fail('Read-only users must not copy through direct calls.');
        } catch (AuthorizationException) {
            $this->assertSame(1, $model::where('pricing_project_id', $this->target->id)->count());
        }
        $user->givePermissionTo(Permission::findOrCreate('Create:'.$name, 'web'));
        $preview = $service->preview($model, [$row->id], $this->target->id);
        $this->assertFalse($preview['can_overwrite']);
        $this->assertSame(1, $service->copy($model, [$row->id], $this->target->id, $preview['token'], 'skip')['skipped']);
        try {
            $service->copy($model, [$row->id], $this->target->id, $preview['token'], 'overwrite');
            $this->fail('Overwrite must require permission to update existing target records.');
        } catch (AuthorizationException) {
            $this->assertModelExists($existing);
        }
    }

    #[DataProvider('configurations')]
    public function test_failed_save_rolls_back_the_entire_copy(string $model): void
    {
        $outlet = $model === PricingParameter::class ? ['parameter_type' => 'outlet_discount'] : ['price_type' => 'outlet'];
        $first = $this->row($model, $outlet);
        $second = $this->row($model, ['currency_id' => null] + $outlet);
        $price = PricingCalculationRow::create(['pricing_project_id' => $this->target->id, 'product_id' => $this->product->id,
            'price_type' => 'outlet', 'currency_id' => $this->currency->id, 'status' => 'approved', 'calculated_price' => 800]);
        $service = app(PricingConfigurationCopyService::class);
        $preview = $service->preview($model, [$first->id, $second->id], $this->target->id);
        Event::listen('eloquent.creating: '.$model, fn ($row) => $row->pricing_project_id == $this->target->id && $row->currency_id === null ? false : null);
        try {
            $service->copy($model, [$first->id, $second->id], $this->target->id, $preview['token']);
            $this->fail('Failed writes must abort every previously copied record.');
        } catch (DomainException) {
            $this->assertSame(0, $model::where('pricing_project_id', $this->target->id)->count());
            $this->assertSame(2, $model::where('pricing_project_id', $this->source->id)->count());
            $this->assertFalse($price->fresh()->requires_review);
        }
    }

    #[DataProvider('configurations')]
    public function test_ui_overwrite_and_preview_refresh_retain_selection_after_stale_confirmation(string $model, string $pageClass): void
    {
        $row = $this->row($model);
        $page = Livewire::test($pageClass)->mountTableBulkAction('copyToPricingProject', [$row])
            ->set('mountedActions.0.data.target_project_id', (string) $this->target->id);
        $existing = $this->row($model, ['pricing_project_id' => $this->target->id]
            + ($model === PricingParameter::class ? ['value' => 2] : ['name' => 'Korábbi', 'round_to' => 100]));
        $before = $existing->fresh()->getAttributes();
        $page->callMountedTableBulkAction()->assertNotDispatched('deselectAllTableRecords');
        $this->assertNotEmpty($page->instance()->mountedActions);
        $this->assertSame([(string) $row->id], $page->instance()->selectedTableRecords);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $page->callAction(TestAction::make('refreshCopyPreview')->schemaComponent('target_project_id', 'mountedActionSchema0'));
        $this->assertSame(1, $page->instance()->mountedActions[0]['data']['conflict_count']);
        $this->assertNull($page->instance()->mountedActions[0]['data']['conflict_mode']);
        $page->set('mountedActions.0.data.conflict_mode', 'overwrite')
            ->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors()->assertDispatched('deselectAllTableRecords');
        $this->assertSame($row->only(array_diff($row->getFillable(), ['pricing_project_id'])),
            $existing->fresh()->only(array_diff($existing->getFillable(), ['pricing_project_id'])));
    }

    #[DataProvider('configurations')]
    public function test_closing_target_after_preview_blocks_copy_and_same_open_project_is_rejected(string $model): void
    {
        $row = $this->row($model);
        $service = app(PricingConfigurationCopyService::class);
        try {
            $service->preview($model, [$row->id], $this->source->id);
            $this->fail('The source cannot be the target.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Másik célárprojektet', $exception->getMessage());
        }
        $preview = $service->preview($model, [$row->id], $this->target->id);
        $this->target->update(['status' => 'closed']);
        try {
            $service->copy($model, [$row->id], $this->target->id, $preview['token']);
            $this->fail('Closed targets must not be writable even with a previously issued token.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Lezárt', $exception->getMessage());
        }
        $this->assertSame(0, $model::where('pricing_project_id', $this->target->id)->count());
    }

    #[DataProvider('configurations')]
    public function test_unchanged_overwrite_and_skip_do_not_mark_prices_for_review(string $model): void
    {
        $row = $this->row($model);
        $this->execute($row);
        $existing = $model::where('pricing_project_id', $this->target->id)->sole();
        $before = $existing->getAttributes();
        $result = $this->execute($row, 'overwrite');
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame(0, $result['marked_for_review']);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertSame(1, $this->execute($row, 'skip')['skipped']);
    }

    public function test_tampered_conflict_metadata_cannot_bypass_explicit_choice(): void
    {
        $row = $this->row(PricingParameter::class);
        $existing = $this->row(PricingParameter::class, ['pricing_project_id' => $this->target->id, 'value' => 2]);
        $page = Livewire::test(ListPricingParameters::class)->mountTableBulkAction('copyToPricingProject', [$row])
            ->set('mountedActions.0.data.target_project_id', (string) $this->target->id)
            ->set('mountedActions.0.data.conflict_count', 0)->callMountedTableBulkAction();
        $this->assertEquals(2, $existing->fresh()->value);
        $this->assertSame(1, PricingParameter::where('pricing_project_id', $this->target->id)->count());
        $this->assertNotEmpty($page->instance()->mountedActions);
        $page->assertNotDispatched('deselectAllTableRecords');
    }

    public function test_model_scopes_keep_explicit_product_reference_across_seasons_without_guessing(): void
    {
        $row = $this->row(PricingParameter::class, ['scope_type' => 'product', 'product_id' => $this->product->id]);
        $otherSeason = Season::create(['code' => 'W28', 'name' => 'Másik szezon', 'active' => true]);
        $this->target->update(['season_id' => $otherSeason->id]);
        $this->execute($row);
        $this->assertSame($this->product->id, PricingParameter::where('pricing_project_id', $this->target->id)->sole()->product_id);
    }

    #[DataProvider('configurations')]
    public function test_destination_write_permission_is_rechecked_after_preview(string $model): void
    {
        $row = $this->row($model);
        $user = User::factory()->create();
        foreach (['ViewAny:'.class_basename($model), 'View:'.class_basename($model), 'Create:'.class_basename($model),
            'ViewAny:PricingProject', 'View:PricingProject', 'Update:PricingProject'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);
        $service = app(PricingConfigurationCopyService::class);
        $preview = $service->preview($model, [$row->id], $this->target->id);
        $user->revokePermissionTo('Update:PricingProject');
        $this->assertSame([], $service->targetOptions($model, collect([$row])));
        $this->expectException(AuthorizationException::class);
        try {
            $service->copy($model, [$row->id], $this->target->id, $preview['token']);
        } finally {
            $this->assertSame(0, $model::where('pricing_project_id', $this->target->id)->count());
        }
    }
}

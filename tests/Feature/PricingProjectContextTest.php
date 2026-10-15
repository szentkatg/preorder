<?php

namespace Tests\Feature;

use App\Exports\AdminTableExport;
use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Models\AdminUiPreference;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\PricingRoundingRule;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\CostCalculationService;
use App\Services\Pricing\HufRetailCalculationService;
use App\Services\Pricing\PricingParameterAdjustmentService;
use App\Services\Pricing\RetailPriceReviewService;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PricingProjectContextTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $first;

    private PricingProject $second;

    private PricingProject $closed;

    private PriceList $priceList;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $this->currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'name_hu' => 'Forint', 'active' => true]);
        ItemMainGroup::create(['code' => 'CTX', 'name_hu' => 'Teszt', 'active' => true]);
        $this->priceList = PriceList::create(['code' => 'CTX-HUF', 'name_hu' => 'Kisker', 'type' => 'retail',
            'currency_id' => $this->currency->id, 'active' => true]);
        $this->first = $this->makeProject('FIRST', PricingProject::STATUS_ACTIVE, false, false);
        $this->second = $this->makeProject('SECOND', PricingProject::STATUS_DRAFT, true, true);
        $this->closed = $this->makeProject('CLOSED', PricingProject::STATUS_CLOSED, true, true);
    }

    public function test_multiple_open_projects_require_selection_and_do_not_load_all_seasons(): void
    {
        foreach ([PricingCostCalculations::class, PricingHufRetailCalculations::class] as $class) {
            $page = Livewire::test($class)->assertSet('pricingProjectId', null);
            $this->assertSame(0, $page->instance()->getTableRecords()->count());
            $this->assertSame([$this->first->id, $this->second->id], $page->instance()->pricingProjectOptions->pluck('id')->all());
            $page->assertSee('Archív megtekintés');
            if ($class === PricingHufRetailCalculations::class) {
                $page->assertActionHidden('calculateHufRetail')->assertTableBulkActionHidden('approveRetailBulk');
            }
            $page->call('selectPricingProject', $this->first->id);
            $this->assertSame([$this->first->id], $page->instance()->getTableRecords()->pluck('pricing_project_id')->unique()->all());
            $page->assertSee('CTX-FIRST')->assertDontSee('CTX-SECOND')->assertDontSee('CTX-CLOSED');
            $page->call('selectPricingProject', '');
            $this->assertSame(0, $page->instance()->getTableRecords()->count());
        }
    }

    public function test_only_open_project_is_automatically_selected_even_with_inactive_season_and_flag(): void
    {
        $this->second->update(['status' => PricingProject::STATUS_CLOSED]);
        $page = Livewire::test(PricingHufRetailCalculations::class)->assertSet('pricingProjectId', $this->first->id)
            ->assertActionVisible('calculateHufRetail')->assertCanSeeTableRecords([$this->row($this->first, 'retail')]);
        $this->assertTrue($page->instance()->pricingWorkspaceIsEditable());
        $this->assertFalse($this->first->active);
        $this->assertFalse((bool) $this->first->season->active);
        $this->assertSame($this->first->id, AdminUiPreference::where('preference_key', 'pricing_workspace_project_context')->firstOrFail()->value['project_id']);
    }

    public function test_selection_is_shared_between_workspaces_and_persists_per_user(): void
    {
        Livewire::test(PricingCostCalculations::class)->call('selectPricingProject', $this->second->id);
        session()->flush();
        Livewire::test(PricingHufRetailCalculations::class)->assertSet('pricingProjectId', $this->second->id)
            ->assertCanSeeTableRecords([$this->row($this->second, 'retail')]);
        $firstUser = auth()->user();
        $other = User::factory()->create();
        $other->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($other);
        Livewire::test(PricingCostCalculations::class)->assertSet('pricingProjectId', null)
            ->call('selectPricingProject', $this->first->id);
        $this->actingAs($firstUser);
        Livewire::test(PricingCostCalculations::class)->assertSet('pricingProjectId', $this->second->id);
    }

    public function test_archive_only_lists_closed_projects_and_exports_only_the_selected_project(): void
    {
        $row = $this->row($this->closed, 'retail');
        $before = $row->getAttributes();
        $page = Livewire::test(PricingHufRetailCalculations::class)->call('setPricingArchive', true)
            ->assertSet('pricingProjectId', $this->closed->id)->assertCanSeeTableRecords([$row])
            ->assertActionHidden('calculateHufRetail')->assertTableBulkActionHidden('approveRetailBulk')
            ->assertTableActionDisabled('approveRetail', $row);
        $this->assertSame([$this->closed->id], $page->instance()->pricingProjectOptions->pluck('id')->all());
        $this->assertFalse($page->instance()->getTable()->isSelectionEnabled());
        $this->assertTrue($page->instance()->getTable()->getColumn('manual_price')->record($row)->isDisabled());
        $page->callAction('decimalPlaces', ['final_price' => 2])->assertHasNoErrors();
        Excel::fake();
        $this->travelTo(now()->setTime(12, 0, 0));
        $page->callTableAction('exportExcel');
        Excel::assertDownloaded('pricing_calculation_rows-'.now()->format('Ymd-His').'.xlsx', function (AdminTableExport $export): bool {
            return $export->query()->count() === 1 && $export->query()->first()->pricing_project_id === $this->closed->id;
        });
        $cost = Livewire::test(PricingCostCalculations::class)->assertSet('pricingArchive', true)
            ->assertSet('pricingProjectId', $this->closed->id);
        $costRow = $this->row($this->closed, 'cost');
        $cost->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('updateShippingCostPercent')
            ->assertTableBulkActionHidden('delete');
        $this->assertSame('false', $cost->instance()->getTable()->getColumn('shipping_cost_percent')->record($costRow)->getExtraCellAttributes()['data-cost-editable']);
        $cost->call('openCostDetails', $costRow->id)->assertActionMounted('costDetails');
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->travelBack();
    }

    public function test_switching_projects_clears_pending_edits_selection_and_filters_but_not_display_preferences(): void
    {
        $page = Livewire::test(PricingCostCalculations::class)->call('selectPricingProject', $this->first->id)
            ->call('savePricingColumnWidths', ['final-price' => 110])
            ->callAction('decimalPlaces', ['final_price' => 2])
            ->call('startInlineCostPercentParameterAdjustment', $this->row($this->first, 'cost')->id, PricingParameter::TYPE_SHIPPING_COST_PERCENT, 9);
        $this->assertNotNull($page->instance()->pendingParameterAdjustment);
        $page->selectTableRecords([$this->row($this->first, 'cost')])->searchTable('FIRST')
            ->call('selectPricingProject', $this->second->id)->assertSet('pendingParameterAdjustment', null)
            ->assertSet('selectedTableRecords', [])->assertSet('tableSearch', '');
        $this->assertSame(['final-price' => 110], $page->instance()->pricingColumnWidths);
        $this->assertSame(['final_price' => 2], $page->instance()->pricingDecimalPlaces);
        $page->assertCanSeeTableRecords([$this->row($this->second, 'cost')]);
    }

    public function test_other_project_rows_cannot_be_modified_through_a_forged_record_id(): void
    {
        $otherRow = $this->row($this->second, 'retail');
        Livewire::test(PricingHufRetailCalculations::class)->call('selectPricingProject', $this->first->id)
            ->call('updateTableColumnState', 'manual_price', (string) $otherRow->id, '1490');
        $this->assertNull($otherRow->fresh()->manual_price);
        $costPage = Livewire::test(PricingCostCalculations::class)->call('selectPricingProject', $this->first->id)
            ->call('startInlineCostPercentParameterAdjustment', $this->row($this->second, 'cost')->id, PricingParameter::TYPE_CUSTOMS_PERCENT, 9)
            ->assertSet('pendingParameterAdjustment', null);
        $costPage->set('pendingParameterAdjustment', ['record_id' => $this->row($this->second, 'cost')->id,
            'parameter_type' => PricingParameter::TYPE_CUSTOMS_PERCENT, 'value' => 9,
            'scope_options' => ['global' => 'Globális']])->set('pendingParameterScope', 'global')
            ->call('confirmPendingParameterAdjustment')->assertSet('pendingParameterAdjustment', null);
        $this->assertSame(0, PricingParameter::where('parameter_type', PricingParameter::TYPE_CUSTOMS_PERCENT)->count());
    }

    public function test_closed_status_blocks_cost_recalculation_parameter_changes_and_retail_review(): void
    {
        $cost = $this->row($this->first, 'cost');
        $retail = $this->row($this->first, 'retail');
        $staleProject = $this->first->fresh();
        $cost->load('pricingProject'); // Deliberately stale, open relation.
        $this->first->update(['status' => PricingProject::STATUS_CLOSED]);
        foreach (['cost', 'parameter', 'retail', 'retail_calculate'] as $operation) {
            try {
                match ($operation) {
                    'cost' => app(CostCalculationService::class)->recalculate($staleProject),
                    'parameter' => app(PricingParameterAdjustmentService::class)->updateCostPercentParameter($cost, PricingParameter::TYPE_CUSTOMS_PERCENT, 'global', 9),
                    'retail' => app(RetailPriceReviewService::class)->setManualPrice($retail, 1490),
                    'retail_calculate' => app(HufRetailCalculationService::class)->recalculate($staleProject, $this->priceList),
                };
                $this->fail('Closed pricing projects must not be processed: '.$operation);
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            } catch (DomainException $exception) {
                $this->assertStringContainsString('lezárt', $exception->getMessage());
            }
        }
        $this->assertNull($retail->fresh()->manual_price);
        $this->assertSame(0, PricingParameter::where('parameter_type', PricingParameter::TYPE_CUSTOMS_PERCENT)->count());
    }

    public function test_open_project_cannot_be_selected_in_archive_mode_and_locked_id_cannot_be_spoofed(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class)->call('setPricingArchive', true);
        $page->call('selectPricingProject', $this->first->id)->assertStatus(422);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(PricingHufRetailCalculations::class)->set('pricingProjectId', $this->first->id);
    }

    public function test_closed_project_blocks_pending_parameter_save_and_manual_edit_from_a_previously_open_page(): void
    {
        $cost = $this->row($this->first, 'cost');
        $retail = $this->row($this->first, 'retail');
        $page = Livewire::test(PricingCostCalculations::class)->call('selectPricingProject', $this->first->id)
            ->call('startInlineCostPercentParameterAdjustment', $cost->id, PricingParameter::TYPE_CUSTOMS_PERCENT, 9);
        $retailPage = Livewire::test(PricingHufRetailCalculations::class);
        $this->first->update(['status' => PricingProject::STATUS_CLOSED]);
        $page->call('confirmPendingParameterAdjustment')->assertForbidden();
        $retailPage->call('updateTableColumnState', 'manual_price', (string) $retail->id, '1490')->assertForbidden();
        $this->assertNull($retail->fresh()->manual_price);
        $this->assertSame(0, PricingParameter::where('parameter_type', PricingParameter::TYPE_CUSTOMS_PERCENT)->count());
    }

    public function test_switching_projects_closes_the_existing_approval_modal(): void
    {
        $row = $this->row($this->first, 'retail');
        $page = Livewire::test(PricingHufRetailCalculations::class)->call('selectPricingProject', $this->first->id)
            ->mountTableAction('approveRetail', $row)
            ->assertSet('mountedActions.0.name', 'approveRetail')
            ->assertSet('mountedActions.0.context.recordKey', (string) $row->id);
        $page->call('selectPricingProject', $this->second->id)->assertSet('mountedActions', [])
            ->assertCanSeeTableRecords([$this->row($this->second, 'retail')]);
        $this->assertNull($this->row($this->first, 'retail')->approved_by);
    }

    public function test_switching_projects_clears_already_resolved_bulk_selection_in_the_same_request(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class)->call('selectPricingProject', $this->first->id)
            ->selectTableRecords([$this->row($this->first, 'retail')]);
        $instance = $page->instance();
        $this->assertSame([$this->row($this->first, 'retail')->id], $instance->getSelectedTableRecords()->modelKeys());
        // Direct calls deliberately reuse the instance and its protected selection cache.
        $instance->selectPricingProject($this->second->id);
        $this->assertCount(0, $instance->getSelectedTableRecords());
        $this->assertSame([$this->second->id], $instance->getTableRecords()->pluck('pricing_project_id')->unique()->all());
    }

    public function test_recalculation_action_uses_the_selected_project_not_a_forged_form_project_id(): void
    {
        $before = $this->row($this->second, 'retail')->getAttributes();
        $first = $this->row($this->first, 'retail');
        $this->row($this->first, 'cost')->update(['calculated_price' => 600]);
        Livewire::test(PricingHufRetailCalculations::class)->call('selectPricingProject', $this->first->id)
            ->callAction('calculateHufRetail', ['pricing_project_id' => $this->second->id, 'price_list_id' => $this->priceList->id])
            ->assertHasNoErrors();
        $this->assertSame('2990.0000', $first->fresh()->final_price);
        $this->assertSame($before, $this->row($this->second, 'retail')->getAttributes());
    }

    public function test_no_open_projects_means_an_empty_workspace_not_automatic_archive_or_all_rows(): void
    {
        $this->first->update(['status' => PricingProject::STATUS_CLOSED]);
        $this->second->update(['status' => PricingProject::STATUS_CLOSED]);
        $page = Livewire::test(PricingCostCalculations::class)->assertSet('pricingProjectId', null)
            ->assertSet('pricingArchive', false);
        $this->assertSame(0, $page->instance()->getTableRecords()->count());
        $this->assertCount(0, $page->instance()->pricingProjectOptions);
        $page->call('setPricingArchive', true)->assertSet('pricingProjectId', null);
        $this->assertCount(3, $page->instance()->pricingProjectOptions);
    }

    public function test_read_only_role_can_choose_projects_without_receiving_write_or_project_management_permissions(): void
    {
        $reader = User::factory()->create();
        $reader->givePermissionTo(array_map(fn ($name) => Permission::findOrCreate($name, 'web'), [
            'View:PricingHufRetailCalculations', 'ViewAny:PricingCalculationRow', 'View:PricingCalculationRow',
        ]));
        $this->actingAs($reader);
        $page = Livewire::test(PricingHufRetailCalculations::class)->call('selectPricingProject', $this->first->id)
            ->assertCanSeeTableRecords([$this->row($this->first, 'retail')])->assertActionHidden('calculateHufRetail')
            ->assertTableBulkActionHidden('approveRetailBulk');
        $this->assertFalse($page->instance()->getTable()->isSelectionEnabled());
        $this->assertTrue($page->instance()->getTable()->getColumn('manual_price')->record($this->row($this->first, 'retail'))->isDisabled());
        $this->assertFalse($reader->can('ViewAny:PricingProject'));
        $this->assertFalse($reader->can('Update:PricingCalculationRow'));
    }

    private function row(PricingProject $project, string $type): PricingCalculationRow
    {
        return PricingCalculationRow::where('pricing_project_id', $project->id)->where('price_type', $type)->firstOrFail();
    }

    private function makeProject(string $code, string $status, bool $seasonActive, bool $active): PricingProject
    {
        $season = Season::create(['code' => $code, 'name' => 'Szezon '.$code, 'active' => $seasonActive]);
        $project = PricingProject::create(['season_id' => $season->id, 'name' => 'Árprojekt '.$code, 'status' => PricingProject::STATUS_ACTIVE, 'active' => $active]);
        $product = Product::create(['season_id' => $season->id, 'item_main_group_id' => ItemMainGroup::firstOrFail()->id,
            'model_code' => 'CTX-'.$code, 'name_hu' => 'Teszt '.$code, 'active' => true]);
        PricingCalculationRow::create(['pricing_project_id' => $project->id, 'product_id' => $product->id,
            'price_type' => 'cost', 'currency_id' => $this->currency->id, 'calculated_price' => 460, 'shipping_cost_percent' => 8, 'customs_percent' => 12]);
        PricingParameter::create(['pricing_project_id' => $project->id, 'parameter_type' => 'retail_multiplier', 'scope_type' => 'global', 'value' => 4.7, 'active' => true]);
        PricingRoundingRule::create(['pricing_project_id' => $project->id, 'name' => 'HUF 990', 'price_type' => 'retail', 'round_to' => 1000, 'mode' => 'nearest', 'adjustment' => -10, 'active' => true]);
        app(HufRetailCalculationService::class)->recalculate($project, $this->priceList);
        $project->update(['status' => $status]);

        return $project;
    }
}

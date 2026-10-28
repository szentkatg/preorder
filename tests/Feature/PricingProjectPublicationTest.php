<?php

namespace Tests\Feature;

use App\Exports\ErpPricingExport;
use App\Filament\Resources\PricingCalculationRows\Pages\EditPricingCalculationRow;
use App\Filament\Resources\PricingParameters\Pages\CreatePricingParameter;
use App\Filament\Resources\PricingParameters\Pages\EditPricingParameter;
use App\Filament\Resources\PricingParameters\Pages\ListPricingParameters;
use App\Filament\Resources\PricingProjects\Pages\EditPricingProject;
use App\Filament\Resources\PricingProjects\Pages\ViewPricingProject;
use App\Filament\Resources\PricingProjects\RelationManagers\CalculationRowsRelationManager;
use App\Models\Color;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\PricingRoundingRule;
use App\Models\Product;
use App\Models\ProductPurchasePrice;
use App\Models\Season;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Pricing\CostCalculationService;
use App\Services\Pricing\DistributorCalculationService;
use App\Services\Pricing\DistributorPriceReviewService;
use App\Services\Pricing\ErpPricingExportService;
use App\Services\Pricing\ForeignRetailCalculationService;
use App\Services\Pricing\HufRetailCalculationService;
use App\Services\Pricing\OutletCalculationService;
use App\Services\Pricing\OutletPriceReviewService;
use App\Services\Pricing\PricingProjectItemDeletionService;
use App\Services\Pricing\PricingProjectPublicationService;
use App\Services\Pricing\RetailPriceReviewService;
use App\Services\Pricing\WholesaleCalculationService;
use App\Services\Pricing\WholesalePriceReviewService;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PricingProjectPublicationTest extends TestCase
{
    use RefreshDatabase;

    protected PricingProject $project;

    protected PriceList $retail;

    protected PricingCalculationRow $row;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $season = Season::create(['code' => 'W27', 'name' => '2027 tél', 'active' => true]);
        $huf = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'BAG', 'name' => 'Táska', 'active' => true]);
        $this->product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id, 'model_code' => 'B10024W27', 'name_hu' => 'ERVIN', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Teszt', 'status' => 'active', 'active' => true]);
        $this->project->products()->attach($this->product);
        $this->retail = PriceList::create(['code' => 'RETAIL_HU', 'name' => 'Kisker', 'type' => 'retail', 'currency_id' => $huf->id, 'active' => true]);
        PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $this->product->id, 'currency_id' => $huf->id, 'price_type' => 'cost', 'calculated_price' => 460.125]);
        PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => 'retail_multiplier', 'scope_type' => 'global', 'value' => 4.7, 'active' => true]);
        PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => 'Retail', 'price_type' => 'retail', 'currency_id' => $huf->id, 'round_to' => 1000, 'mode' => 'nearest', 'adjustment' => -10, 'active' => true]);
        app(HufRetailCalculationService::class)->recalculate($this->project, $this->retail);
        $this->row = PricingCalculationRow::where('price_type', 'retail')->firstOrFail();
    }

    protected function publish()
    {
        app(RetailPriceReviewService::class)->approveCurrentPrice($this->row);

        return app(PricingProjectPublicationService::class)->publish($this->project);
    }

    public function test_publishing_prices_does_not_make_a_hidden_season_visible_to_partners(): void
    {
        $this->assertFalse($this->project->season->visible_to_partners);
        $this->publish();
        $this->assertGreaterThan(0, PriceListItem::where('season_id', $this->project->season_id)->count());
        $this->assertFalse($this->project->season->fresh()->visible_to_partners);
    }

    public function test_project_item_delete_waits_for_confirmation_and_preserves_published_prices_and_snapshot(): void
    {
        $publication = $this->publish();
        $snapshot = $publication->getAttributes();
        $items = PriceListItem::all()->map->getAttributes()->all();
        app(PricingProjectPublicationService::class)->reopen($this->project->fresh());
        $cost = $this->project->calculationRows()->where('price_type', 'cost')->firstOrFail();
        $page = Livewire::test(CalculationRowsRelationManager::class, [
            'ownerRecord' => $this->project->fresh(), 'pageClass' => EditPricingProject::class,
        ])->mountTableAction('delete', $cost)->assertActionMounted();
        $this->assertSame(PricingProjectItemDeletionService::WARNING, $page->instance()->getMountedAction()->getModalDescription());
        $this->assertModelExists($cost);
        $this->assertModelExists($this->row);
        $page->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame(0, $this->project->calculationRows()->count());
        $this->assertSame($items, PriceListItem::all()->map->getAttributes()->all());
        // Reopen audit changes are allowed; the publication's immutable price snapshot is not.
        $this->assertSame($snapshot['snapshot'], $publication->fresh()->getAttributes()['snapshot']);
        $this->assertModelExists($this->product);
    }

    public function test_project_bulk_delete_expands_all_price_types_but_not_other_colors_or_projects(): void
    {
        $cost = $this->project->calculationRows()->where('price_type', 'cost')->firstOrFail();
        foreach (['outlet', 'wholesale', 'distributor'] as $type) {
            $list = PriceList::create(['code' => 'DELETE-'.$type, 'name' => $type, 'type' => $type,
                'currency_id' => $cost->currency_id, 'active' => true]);
            PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $this->product->id,
                'currency_id' => $cost->currency_id, 'price_type' => $type, 'price_list_id' => $list->id,
                'calculated_price' => 100, 'source_retail_row_id' => $this->row->id]);
        }
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euró', 'active' => true]);
        $eurList = PriceList::create(['code' => 'DELETE-EUR', 'name' => 'EUR', 'type' => 'retail', 'currency_id' => $eur->id, 'active' => true]);
        PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $this->product->id,
            'currency_id' => $eur->id, 'price_type' => 'retail', 'price_list_id' => $eurList->id,
            'calculated_price' => 10, 'source_retail_row_id' => $this->row->id]);
        $secondProduct = $this->product->replicate();
        $secondProduct->model_code = 'DELETE-SECOND';
        $secondProduct->save();
        $secondCost = $cost->replicate();
        $secondCost->product_id = $secondProduct->id;
        $secondCost->save();
        $secondRetail = $this->row->replicate();
        $secondRetail->product_id = $secondProduct->id;
        $secondRetail->save();
        $color = Color::create(['product_id' => $this->product->id, 'code' => 'KEEP', 'name_hu' => 'Marad']);
        $otherColor = $cost->replicate();
        $otherColor->color_id = $color->id;
        $otherColor->save();
        $otherProject = $this->project->replicate();
        $otherProject->save();
        $otherRow = $cost->replicate();
        $otherRow->pricing_project_id = $otherProject->id;
        $otherRow->save();

        $page = Livewire::test(CalculationRowsRelationManager::class, [
            'ownerRecord' => $this->project, 'pageClass' => EditPricingProject::class,
        ])->mountTableBulkAction('delete', [$cost, $secondCost])->assertActionMounted();
        $this->assertModelExists($this->row);
        $this->assertSame(PricingProjectItemDeletionService::WARNING, $page->instance()->getMountedAction()->getModalDescription());
        $page->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();
        $this->assertSame([$otherColor->id], $this->project->calculationRows()->pluck('id')->all());
        $this->assertModelExists($otherRow);
    }

    public function test_project_single_delete_does_not_require_bulk_delete_permission(): void
    {
        $user = User::factory()->create();
        foreach (['ViewAny:PricingCalculationRow', 'View:PricingCalculationRow', 'Delete:PricingCalculationRow',
            'View:PricingProject', 'Update:PricingProject'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);
        $cost = $this->project->calculationRows()->where('price_type', 'cost')->firstOrFail();
        Livewire::test(CalculationRowsRelationManager::class, [
            'ownerRecord' => $this->project, 'pageClass' => EditPricingProject::class,
        ])->assertTableBulkActionHidden('delete')->callTableAction('delete', $cost)->assertHasNoTableActionErrors();
        $this->assertSame(0, $this->project->calculationRows()->count());
    }

    public function test_project_read_only_page_cannot_delete_even_with_a_forged_action_call(): void
    {
        $cost = $this->project->calculationRows()->where('price_type', 'cost')->firstOrFail();
        Livewire::test(CalculationRowsRelationManager::class, [
            'ownerRecord' => $this->project, 'pageClass' => ViewPricingProject::class,
        ])->assertTableActionHidden('delete', $cost)->assertTableBulkActionHidden('delete')
            ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $cost->id])
            ->assertSet('mountedActions', []);
        $this->assertSame(2, $this->project->calculationRows()->count());
    }

    public function test_deleting_multiple_rows_of_the_same_item_is_deduplicated(): void
    {
        $ids = $this->project->calculationRows()->pluck('id')->all();
        $this->assertSame(2, app(PricingProjectItemDeletionService::class)->delete($this->project, [...$ids, $ids[0]], bulk: true));
        $this->assertSame(0, $this->project->calculationRows()->count());
    }

    public function test_project_item_delete_rejects_foreign_selection_and_stale_closed_projects(): void
    {
        $other = $this->project->replicate();
        $other->save();
        $foreign = $this->row->replicate();
        $foreign->pricing_project_id = $other->id;
        $foreign->save();
        try {
            app(PricingProjectItemDeletionService::class)->delete($this->project, [$this->row->id, $foreign->id], bulk: true);
            $this->fail('Cross-project selection must not be deleted.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertModelExists($this->row);
        $this->assertModelExists($foreign);
        $this->project->fresh()->update(['status' => 'closed']);
        try {
            app(PricingProjectItemDeletionService::class)->delete($this->project, [$this->row->id]);
            $this->fail('A stale open project must be rechecked.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertModelExists($this->row);
    }

    public function test_bulk_delete_permission_alone_cannot_delete_expanded_price_rows(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([Permission::findOrCreate('Update:PricingProject', 'web'),
            Permission::findOrCreate('DeleteAny:PricingCalculationRow', 'web')]);
        $this->actingAs($user);
        $this->expectException(AuthorizationException::class);
        try {
            app(PricingProjectItemDeletionService::class)->delete($this->project, [$this->row->id], bulk: true);
        } finally {
            $this->assertSame(2, $this->project->calculationRows()->count());
        }
    }

    public function test_project_item_delete_rolls_back_every_row_and_dependency_update_on_failure(): void
    {
        $before = $this->project->calculationRows()->orderBy('id')->get()->map->getAttributes()->all();
        Event::listen('eloquent.deleting: '.PricingCalculationRow::class,
            fn (PricingCalculationRow $row) => $row->id === $this->row->id ? false : null);
        try {
            app(PricingProjectItemDeletionService::class)->delete($this->project, [$this->row->id]);
            $this->fail('A failed row delete must abort the entire operation.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->project->calculationRows()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_publication_updates_only_matching_season_and_closes_atomically_with_audit(): void
    {
        $item = PriceListItem::create(['season_id' => $this->project->season_id, 'product_id' => $this->product->id, 'price_list_id' => $this->retail->id, 'net_price' => 999]);
        $otherSeason = Season::create(['code' => 'W26', 'name' => 'Korábbi', 'active' => true]);
        PriceListItem::create(['season_id' => $otherSeason->id, 'product_id' => $this->product->id, 'price_list_id' => $this->retail->id, 'net_price' => 888]);
        $publication = $this->publish();
        $this->assertEquals(1990, $item->fresh()->net_price);
        $this->assertDatabaseHas('price_list_items', ['season_id' => $otherSeason->id, 'net_price' => 888]);
        $this->assertSame('closed', $this->project->fresh()->status);
        $this->assertNotNull($this->project->fresh()->closed_at);
        $this->assertSame(auth()->id(), $this->project->fresh()->closed_by);
        $this->assertEquals(999, $publication->snapshot['prices'][0]['previous_price']);
        $this->assertSame('460.125', $publication->snapshot['products'][$this->product->id]['cost']);
        $this->assertCount(1, $publication->snapshot['prices']);
    }

    public function test_unapproved_or_stale_prices_do_not_publish_or_close(): void
    {
        foreach ([false, true] as $approved) {
            if ($approved) {
                app(RetailPriceReviewService::class)->approveCurrentPrice($this->row);
                $this->row->fresh()->update(['requires_review' => true]);
            }
            try {
                app(PricingProjectPublicationService::class)->publish($this->project);
                $this->fail('Unapproved/stale publication must fail.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('jóváhagyott', $exception->getMessage());
            }
            $this->assertDatabaseCount('price_list_items', 0);
            $this->assertDatabaseCount('pricing_project_publications', 0);
            $this->assertSame('active', $this->project->fresh()->status);
        }
    }

    public function test_reopen_has_its_own_permission_and_does_not_undo_published_prices(): void
    {
        $publication = $this->publish();
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('Update:PricingProject', 'web'));
        $this->actingAs($user);
        $this->assertFalse(Gate::allows('reopen', $this->project));
        try {
            app(PricingProjectPublicationService::class)->reopen($this->project);
            $this->fail('Update alone must not reopen.');
        } catch (AuthorizationException) {
        }
        try {
            $this->project->fresh()->update(['status' => 'active']);
            $this->fail('Generic status update must not bypass permission.');
        } catch (AuthorizationException) {
        }
        $user->givePermissionTo('Reopen:PricingProjectLifecycle');
        app(PricingProjectPublicationService::class)->reopen($this->project);
        $this->assertSame('active', $this->project->fresh()->status);
        $this->assertSame($user->id, $publication->fresh()->reopened_by);
        $this->assertDatabaseHas('price_list_items', ['net_price' => 1990]);
    }

    public function test_update_permission_does_not_grant_publish_or_export_and_custom_permissions_are_visible(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('Update:PricingProject', 'web'));
        $this->actingAs($user);
        $this->assertFalse(Gate::allows('publish', $this->project));
        $this->assertFalse(Gate::allows('exportErp', $this->project));
        $permissions = FilamentShield::getCustomPermissions();
        $this->assertArrayHasKey('Reopen:PricingProjectLifecycle', $permissions);
        $this->expectException(AuthorizationException::class);
        app(PricingProjectPublicationService::class)->publish($this->project);
    }

    public function test_republication_creates_new_snapshot_and_export_ignores_later_draft_changes(): void
    {
        $first = $this->publish();
        app(PricingProjectPublicationService::class)->reopen($this->project);
        app(RetailPriceReviewService::class)->setManualPrice($this->row, 1790);
        $rows = app(ErpPricingExportService::class)->rows($this->project, [], '2026-10-16');
        $this->assertEquals(1990, $rows[3][10]);
        app(RetailPriceReviewService::class)->approveCurrentPrice($this->row);
        $second = app(PricingProjectPublicationService::class)->publish($this->project);
        $this->assertNotSame($first->id, $second->id);
        $this->assertEquals(1990, $first->fresh()->snapshot['prices'][0]['price']);
        $this->assertEquals(1790, app(ErpPricingExportService::class)->rows($this->project, [], '2026-10-16')[3][10]);
    }

    public function test_excel_matches_all_30_template_headers_and_is_numeric_with_empty_season_fields(): void
    {
        $this->publish();
        $rows = app(ErpPricingExportService::class)->rows($this->project, [], '2026-10-16');
        $this->assertCount(30, $rows[0]);
        $this->assertCount(30, $rows[3]);
        $this->assertSame('B10024', $rows[3][0]);
        $this->assertSame('ERVIN', $rows[3][1]);
        $this->assertSame([null, null, null], array_slice($rows[3], 2, 3));
        $this->assertSame(460.13, $rows[3][5]);
        $this->assertEquals(1990, $rows[3][10]);
        $this->assertEquals($rows[3][10], $rows[3][11]);
        $this->assertSame(['HUF', 'EUR', 'CZK', 'RON', 'PLN'], array_values(array_unique(array_slice($rows[0], 5))));
        $path = tempnam(sys_get_temp_dir(), 'erp-price-');
        $binder = Cell::getValueBinder();
        try {
            file_put_contents($path, Excel::raw(new ErpPricingExport($rows), \Maatwebsite\Excel\Excel::XLSX));
            Cell::setValueBinder(new DefaultValueBinder);
            $sheet = IOFactory::load($path)->getSheet(0);
            $this->assertSame('Import', $sheet->getTitle());
            $this->assertSame('AD', $sheet->getHighestDataColumn());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('F4')->getDataType());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A4')->getDataType());
            $this->assertSame('2026.10.16', $sheet->getCell('A2')->getValue());
            $this->assertNull($sheet->getCell('C4')->getValue());
        } finally {
            Cell::setValueBinder($binder);
            @unlink($path);
        }
    }

    public function test_project_ui_closes_and_reopens_and_downloads_fixed_export(): void
    {
        app(RetailPriceReviewService::class)->approveCurrentPrice($this->row);
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])->callAction('publishProject')->assertHasNoErrors();
        $this->assertSame('closed', $this->project->fresh()->status);
        Livewire::test(EditPricingProject::class, ['record' => $this->project->id])->assertForbidden();
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])
            ->callAction('exportErpPrices', ['start_date' => '2026-10-16', 'mapping' => ['huf_retail' => $this->retail->id]])->assertFileDownloaded();
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])->callAction('reopenProject')->assertHasNoErrors();
        $this->assertSame('active', $this->project->fresh()->status);
    }

    public function test_failed_publication_transaction_restores_previous_prices_and_status(): void
    {
        app(RetailPriceReviewService::class)->approveCurrentPrice($this->row);
        Event::listen('eloquent.creating: App\\Models\\PricingProjectPublication', fn () => throw new \RuntimeException('Failure test'));
        try {
            app(PricingProjectPublicationService::class)->publish($this->project);
            $this->fail('Must rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Failure test', $exception->getMessage());
        }
        $this->assertDatabaseCount('price_list_items', 0);
        $this->assertSame('active', $this->project->fresh()->status);
    }

    public function test_different_colour_prices_cannot_be_silently_collapsed(): void
    {
        $colour = Color::create(['product_id' => $this->product->id, 'code' => 'RED', 'name_hu' => 'Piros', 'active' => true]);
        $cost = PricingCalculationRow::where('price_type', 'cost')->firstOrFail()->replicate();
        $cost->color_id = $colour->id;
        $cost->save();
        app(HufRetailCalculationService::class)->recalculate($this->project, $this->retail);
        $other = PricingCalculationRow::where('price_type', 'retail')->where('color_id', $colour->id)->firstOrFail();
        app(RetailPriceReviewService::class)->setManualPrice($other, 1790);
        app(RetailPriceReviewService::class)->approveCurrentPrices(collect([$this->row, $other]));
        $this->expectException(DomainException::class);
        app(PricingProjectPublicationService::class)->publish($this->project);
    }

    public function test_duplicate_currency_lists_require_explicit_export_mapping(): void
    {
        $second = PriceList::create(['code' => 'RETAIL_ALT', 'name' => 'Másik kisker', 'type' => 'retail', 'currency_id' => $this->retail->currency_id, 'active' => true]);
        app(HufRetailCalculationService::class)->recalculate($this->project, $second);
        app(RetailPriceReviewService::class)->approveCurrentPrices(PricingCalculationRow::where('price_type', 'retail')->get());
        app(PricingProjectPublicationService::class)->publish($this->project);
        try {
            app(ErpPricingExportService::class)->rows($this->project, [], '2026-10-16');
            $this->fail('Ambiguous mapping must be explicit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mapping.huf_retail', $exception->errors());
        }
        $rows = app(ErpPricingExportService::class)->rows($this->project, ['huf_retail' => $second->id], '2026-10-16');
        $this->assertEquals(1990, $rows[3][11]);
    }

    public function test_closed_project_inputs_cannot_be_edited_created_or_bulk_deleted(): void
    {
        $this->publish();
        $parameter = PricingParameter::firstOrFail();
        Livewire::test(EditPricingParameter::class, ['record' => $parameter->id])->assertForbidden();
        Livewire::test(EditPricingCalculationRow::class, ['record' => $this->row->id])->assertForbidden();
        Livewire::test(CreatePricingParameter::class)
            ->fillForm(['pricing_project_id' => $this->project->id, 'parameter_type' => 'retail_multiplier', 'scope_type' => 'global', 'value' => 9, 'active' => true])->call('create')->assertForbidden();
        Livewire::test(ListPricingParameters::class)->callTableBulkAction('delete', [$parameter]);
        $this->assertModelExists($parameter);
    }

    public function test_all_supported_price_levels_publish_and_export_in_their_own_columns(): void
    {
        PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => 'country_multiplier', 'scope_type' => 'global', 'value' => 1.1, 'active' => true]);
        PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => 'wholesale_margin', 'scope_type' => 'global', 'value' => 2.5, 'active' => true]);
        PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => 'distributor_discount', 'scope_type' => 'global', 'value' => 20, 'active' => true]);
        $retails = ['HUF' => $this->retail];
        foreach (['EUR' => 400, 'CZK' => 16, 'RON' => 80, 'PLN' => 90] as $code => $rate) {
            $currency = Currency::create(['code' => $code, 'name' => $code, 'active' => true]);
            ExchangeRate::create(['season_id' => $this->project->season_id, 'currency_id' => $currency->id, 'rate_to_huf' => $rate, 'active' => true]);
            $list = PriceList::create(['code' => 'RETAIL_'.$code, 'name' => $code, 'type' => 'retail', 'currency_id' => $currency->id, 'active' => true]);
            PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => $code, 'price_type' => 'retail', 'price_list_id' => $list->id, 'currency_id' => $currency->id, 'round_to' => 1, 'mode' => 'nearest', 'adjustment' => -0.05, 'active' => true]);
            app(ForeignRetailCalculationService::class)->recalculate($this->project, $this->retail, $list);
            $retails[$code] = $list;
        }
        foreach (['HUF', 'EUR'] as $code) {
            $list = PriceList::create(['code' => 'WHOLE_'.$code, 'name' => $code, 'type' => 'wholesale', 'retail_price_list_id' => $retails[$code]->id, 'currency_id' => $retails[$code]->currency_id, 'active' => true]);
            PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => 'Whole '.$code, 'price_type' => 'wholesale', 'price_list_id' => $list->id, 'currency_id' => $list->currency_id, 'round_to' => 0.01, 'mode' => 'nearest', 'active' => true]);
        }
        app(WholesaleCalculationService::class)->recalculate($this->project);
        $whole = PriceList::where('code', 'WHOLE_EUR')->firstOrFail();
        $dist = PriceList::create(['code' => 'DIST_EUR', 'name' => 'Dist', 'type' => 'distributor', 'currency_id' => $whole->currency_id, 'source_wholesale_price_list_id' => $whole->id, 'active' => true]);
        PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => 'Dist', 'price_type' => 'distributor', 'price_list_id' => $dist->id, 'currency_id' => $dist->currency_id, 'round_to' => 0.01, 'mode' => 'down', 'active' => true]);
        app(DistributorCalculationService::class)->recalculate($this->project, $dist->id);
        foreach (['HUF', 'CZK'] as $code) {
            $source = $retails[$code];
            $list = PriceList::create(['code' => 'OUTLET_'.$code, 'name' => 'Outlet', 'type' => 'outlet', 'currency_id' => $source->currency_id, 'source_retail_price_list_id' => $source->id, 'active' => true]);
            PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => 'outlet_discount', 'scope_type' => 'global', 'currency_id' => $source->currency_id, 'value' => 20, 'active' => true]);
            PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => 'Outlet', 'price_type' => 'outlet', 'price_list_id' => $list->id, 'currency_id' => $list->currency_id, 'round_to' => 1, 'mode' => 'down', 'active' => true]);
            app(OutletCalculationService::class)->recalculate($this->project, $code);
        }
        // Approval order follows the dependency graph; no underlying price is changed.
        foreach (['retail' => RetailPriceReviewService::class, 'wholesale' => WholesalePriceReviewService::class,
            'distributor' => DistributorPriceReviewService::class, 'outlet' => OutletPriceReviewService::class] as $type => $service) {
            app($service)->approveCurrentPrices(PricingCalculationRow::where('price_type', $type)->get());
        }
        $publication = app(PricingProjectPublicationService::class)->publish($this->project);
        $this->assertCount(10, $publication->snapshot['prices']);
        $rows = app(ErpPricingExportService::class)->rows($this->project, [], '2026-10-16');
        foreach (ErpPricingExportService::SLOTS as [$currency, $type, $label, $columns]) {
            $list = collect($publication->snapshot['lists'])->first(fn ($list) => $list['currency'] === $currency && $list['type'] === $type);
            foreach ($columns as $column) {
                if ($list) {
                    $price = PriceListItem::where('price_list_id', $list['id'])->value('net_price');
                    $this->assertEquals((float) $price, $rows[3][$column]);
                } else {
                    $this->assertNull($rows[3][$column]);
                }
            }
        }
    }

    public function test_changed_cost_parameter_blocks_publication_even_before_dependent_recalculation(): void
    {
        $supplier = Supplier::create(['erp_partner_code' => 'S1', 'name' => 'Teszt', 'active' => true]);
        ProductPurchasePrice::create(['product_id' => $this->product->id, 'supplier_id' => $supplier->getKey(),
            'currency_id' => $this->retail->currency_id, 'purchase_price' => 460.125, 'active' => true]);
        app(CostCalculationService::class)->recalculate($this->project);
        app(HufRetailCalculationService::class)->recalculate($this->project, $this->retail);
        app(RetailPriceReviewService::class)->approveCurrentPrices(PricingCalculationRow::where('price_type', 'retail')->get());
        PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => 'shipping_cost_percent', 'scope_type' => 'global', 'value' => 10, 'active' => true]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('bekerülési kalkuláció');
        app(PricingProjectPublicationService::class)->publish($this->project);
    }
}

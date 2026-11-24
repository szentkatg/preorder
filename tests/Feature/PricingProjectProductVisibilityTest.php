<?php

namespace Tests\Feature;

use App\Filament\Resources\PricingProjects\Pages\ViewPricingProject;
use App\Filament\Resources\PricingProjects\RelationManagers\ProductsRelationManager;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\PricingProjectProductVisibilityService;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingProjectProductVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private Product $first;

    private Product $second;

    private Product $outside;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $season = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true, 'visible_to_partners' => false]);
        $group = ItemMainGroup::create(['code' => 'B03', 'name' => 'Táska', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Első ütem', 'status' => 'active', 'active' => true]);
        foreach (['first', 'second', 'outside'] as $key) {
            $this->{$key} = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
                'model_code' => $key, 'name_hu' => $key, 'active' => true, 'visible_to_partners' => false]);
        }
        $this->project->products()->attach([$this->first->id, $this->second->id]);
    }

    private function manager()
    {
        return Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $this->project->fresh(), 'pageClass' => ViewPricingProject::class]);
    }

    private function editor(array $permissions = ['View:PricingProject', 'ViewAny:Product', 'Update:Product']): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);

        return $user;
    }

    public function test_only_selected_project_members_change_and_inactive_products_and_season_are_not_activated(): void
    {
        $this->second->update(['active' => false]);
        $service = app(PricingProjectProductVisibilityService::class);
        $this->assertSame(['selected' => 2, 'updated' => 2, 'unchanged' => 0], $service->updateSelected($this->project, [$this->first->id, $this->second->id], true));
        $this->assertTrue($this->first->fresh()->visible_to_partners);
        $this->assertTrue($this->second->fresh()->visible_to_partners);
        $this->assertFalse((bool) $this->second->fresh()->active);
        $this->assertFalse(Product::visibleToPartners()->whereKey($this->second->id)->exists());
        $this->assertFalse($this->outside->fresh()->visible_to_partners);
        $this->assertFalse($this->project->season->fresh()->visible_to_partners);
        $this->assertSame(['selected' => 1, 'updated' => 0, 'unchanged' => 1], $service->updateSelected($this->project, [$this->first->id], true));
        $service->updateSelected($this->project, [$this->first->id], false);
        $this->assertFalse($this->first->fresh()->visible_to_partners);
        $this->assertTrue($this->second->fresh()->visible_to_partners);
    }

    public function test_closed_project_is_not_reopened_and_prices_and_publication_stay_unchanged(): void
    {
        $this->project->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => auth()->id()]);
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'active' => true]);
        $list = PriceList::create(['code' => 'RETAIL', 'name' => 'Kisker', 'currency_id' => $currency->id, 'type' => 'retail', 'active' => true]);
        $price = PriceListItem::create(['season_id' => $this->project->season_id, 'product_id' => $this->first->id, 'price_list_id' => $list->id, 'net_price' => 1990]);
        $row = PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $this->first->id,
            'currency_id' => $currency->id, 'price_type' => 'cost', 'calculated_price' => 500]);
        $publication = $this->project->publications()->create(['published_by' => auth()->id(), 'published_at' => now(), 'snapshot' => ['prices' => ['keep']]]);
        $before = [$this->project->fresh()->getAttributes(), $price->fresh()->getAttributes(), $row->fresh()->getAttributes(), $publication->fresh()->getAttributes()];
        $this->editor(); // No project-update, publish or reopen permission.
        $this->assertFalse(Gate::allows('reopen', $this->project));
        app(PricingProjectProductVisibilityService::class)->updateSelected($this->project, [$this->first->id], true);
        $this->assertTrue($this->first->fresh()->visible_to_partners);
        $this->assertSame($before, [$this->project->fresh()->getAttributes(), $price->fresh()->getAttributes(), $row->fresh()->getAttributes(), $publication->fresh()->getAttributes()]);
    }

    public function test_bulk_action_works_on_read_only_closed_project_view_with_confirmation_then_can_hide_again(): void
    {
        $this->project->update(['status' => 'closed']);
        $page = $this->manager()->assertTableColumnExists('visible_to_partners');
        $this->assertTrue($page->instance()->isReadOnly());
        $this->assertTrue($page->instance()->getTable()->isSelectionEnabled());
        $page->assertTableBulkActionHidden('removeProducts');
        $page->mountTableBulkAction('setPartnerVisibility', [$this->first, $this->second]);
        $this->assertStringContainsString('2 db', $page->instance()->getMountedAction()->getModalDescription());
        $this->assertFalse($this->first->fresh()->visible_to_partners);
        $page->set('mountedActions.0.data.visible_to_partners', '1')->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();
        $this->assertTrue($this->first->fresh()->visible_to_partners);
        $this->assertTrue($this->second->fresh()->visible_to_partners);
        $this->assertFalse($this->outside->fresh()->visible_to_partners);
        $page->mountTableBulkAction('setPartnerVisibility', [$this->first])
            ->set('mountedActions.0.data.visible_to_partners', '0')->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();
        $this->assertFalse($this->first->fresh()->visible_to_partners);
        $this->assertTrue($this->second->fresh()->visible_to_partners);
        $this->assertSame('closed', $this->project->fresh()->status);
    }

    public function test_cancel_does_not_change_visibility(): void
    {
        $this->manager()->mountTableBulkAction('setPartnerVisibility', [$this->first])
            ->set('mountedActions.0.data.visible_to_partners', '1')->call('unmountAction');
        $this->assertFalse($this->first->fresh()->visible_to_partners);
    }

    public function test_select_all_respects_the_filtered_project_product_list(): void
    {
        $page = $this->manager()->searchTable('first')->set('isTrackingDeselectedTableRecords', true)
            ->mountTableBulkAction('setPartnerVisibility', []);
        $this->assertStringContainsString('1 db', $page->instance()->getMountedAction()->getModalDescription());
        $page->set('mountedActions.0.data.visible_to_partners', '1')->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();
        $this->assertTrue($this->first->fresh()->visible_to_partners);
        $this->assertFalse($this->second->fresh()->visible_to_partners);
        $this->assertFalse($this->outside->fresh()->visible_to_partners);
    }

    public function test_cancelled_model_save_rolls_back_preceding_visibility_changes(): void
    {
        Event::listen('eloquent.saving: '.Product::class, fn (Product $product) => $product->id === $this->second->id ? false : null);
        $this->expectException(DomainException::class);
        try {
            app(PricingProjectProductVisibilityService::class)->updateSelected($this->project, [$this->first->id, $this->second->id], true);
        } finally {
            $this->assertFalse($this->first->fresh()->visible_to_partners);
            $this->assertFalse($this->second->fresh()->visible_to_partners);
        }
    }

    public function test_changed_selection_token_and_removed_members_are_rejected_without_partial_updates(): void
    {
        $page = $this->manager()->mountTableBulkAction('setPartnerVisibility', [$this->first, $this->second]);
        $page->set('selectedTableRecords', [(string) $this->first->id])->set('mountedActions.0.data.visible_to_partners', '1')
            ->callMountedTableBulkAction()->assertNotified('A láthatóság nem módosítható');
        $this->assertFalse($this->first->fresh()->visible_to_partners);
        $page = $this->manager()->mountTableBulkAction('setPartnerVisibility', [$this->first, $this->second]);
        $this->project->products()->detach($this->second->id);
        $page->set('mountedActions.0.data.visible_to_partners', '1')->callMountedTableBulkAction()->assertNotified('A láthatóság nem módosítható');
        $this->assertFalse($this->first->fresh()->visible_to_partners);
        $this->assertFalse($this->second->fresh()->visible_to_partners);
    }

    public function test_forged_foreign_product_selection_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        try {
            app(PricingProjectProductVisibilityService::class)->updateSelected($this->project, [$this->first->id, $this->outside->id], true);
        } finally {
            $this->assertFalse($this->first->fresh()->visible_to_partners);
            $this->assertFalse($this->outside->fresh()->visible_to_partners);
        }
    }

    public function test_product_update_and_project_view_permissions_are_enforced(): void
    {
        $this->editor(['View:PricingProject', 'ViewAny:Product']);
        $this->assertFalse(PricingProjectProductVisibilityService::canChange($this->project));
        $this->manager()->assertTableBulkActionHidden('setPartnerVisibility');
        $this->expectException(AuthorizationException::class);
        app(PricingProjectProductVisibilityService::class)->updateSelected($this->project, [$this->first->id], true);
    }

    public function test_cannot_change_visibility_in_an_unviewable_project_even_with_product_update_permission(): void
    {
        $this->editor(['ViewAny:Product', 'Update:Product']);
        $this->assertFalse(PricingProjectProductVisibilityService::canChange($this->project));
        $this->expectException(AuthorizationException::class);
        app(PricingProjectProductVisibilityService::class)->updateSelected($this->project, [$this->first->id], true);
    }

    public function test_individual_product_denial_rolls_back_every_selected_product(): void
    {
        $this->editor();
        Gate::before(fn ($user, string $ability, array $arguments) => $ability === 'update'
            && ($arguments[0] ?? null) instanceof Product && $arguments[0]->id === $this->second->id ? false : null);
        $this->expectException(AuthorizationException::class);
        try {
            app(PricingProjectProductVisibilityService::class)->updateSelected($this->project, [$this->first->id, $this->second->id], true);
        } finally {
            $this->assertFalse($this->first->fresh()->visible_to_partners);
            $this->assertFalse($this->second->fresh()->visible_to_partners);
        }
    }
}

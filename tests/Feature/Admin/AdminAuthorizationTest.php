<?php

namespace Tests\Feature\Admin;

use App\Exports\AdminTableExport;
use App\Filament\Pages\StockOrderProportioning;
use App\Filament\Resources\AdminUsers\AdminUserResource;
use App\Filament\Resources\AdminUsers\Pages\CreateAdminUser;
use App\Filament\Resources\AdminUsers\Pages\EditAdminUser;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Resources\Languages\LanguageResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\PartnerUsers\Pages\ListPartnerUsers;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\RelationManagers\ColorsRelationManager;
use App\Filament\Resources\Skus\Pages\ListSkus;
use App\Filament\Resources\Translations\Pages\ListTranslations;
use App\Filament\Support\Pages\ReadOnlyRecord;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Language;
use App\Models\PartnerUser;
use App\Models\Product;
use App\Models\Sku;
use App\Models\User;
use BezhanSalleh\FilamentShield\Facades\FilamentShield as Shield;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_bypasses_admin_authorization_checks(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->assertTrue(Gate::forUser($user)->allows('an-unregistered-admin-ability'));
    }

    public function test_super_admin_role_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $role = Role::findOrCreate('super_admin', 'web');
        $user->assignRole($role);

        $this->assertTrue(Gate::forUser($user)->denies('update', $role));
        $this->assertTrue(Gate::forUser($user)->denies('delete', $role));
        $this->assertTrue(Gate::forUser($user)->denies('deleteAny', Role::class));
    }

    public function test_super_admin_bypass_does_not_apply_to_partner_users(): void
    {
        $partnerUser = new PartnerUser;

        $this->assertFalse(Gate::forUser($partnerUser)->allows('an-unregistered-admin-ability'));
    }

    public function test_role_resource_is_registered_in_the_admin_panel(): void
    {
        $this->assertContains(RoleResource::class, Filament::getPanel('admin')->getResources());
    }

    public function test_admin_panel_loads_the_table_scroll_enhancements(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->actingAs($user)
            ->get('/admin/products')
            ->assertOk()
            ->assertSee('/css/admin-table-scroll.css', false)
            ->assertSee('/js/admin-table-scroll.js', false);
    }

    public function test_only_users_with_an_admin_role_can_access_the_admin_panel(): void
    {
        $user = User::factory()->create();
        $adminPanel = Filament::getPanel('admin');

        $this->assertFalse($user->canAccessPanel($adminPanel));

        $user->assignRole(Role::findOrCreate('test_admin', 'web'));

        $this->assertTrue($user->canAccessPanel($adminPanel));
    }

    public function test_only_super_admin_can_open_the_role_management_page(): void
    {
        $regularUser = User::factory()->create();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->actingAs($regularUser)
            ->get('/admin/shield/roles')
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->get('/admin/shield/roles')
            ->assertOk();
    }

    public function test_only_super_admin_can_open_admin_user_management(): void
    {
        $regularUser = User::factory()->create();
        $regularUser->assignRole(Role::findOrCreate('test_admin', 'web'));

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->assertContains(AdminUserResource::class, Filament::getPanel('admin')->getResources());

        $this->actingAs($regularUser)
            ->get('/admin/admin-users')
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->get('/admin/admin-users')
            ->assertOk();
    }

    public function test_super_admin_can_create_an_admin_user_with_a_role(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $assignedRole = Role::findOrCreate('order_manager', 'web');

        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateAdminUser::class)
            ->fillForm([
                'name' => 'Rendelés kezelő',
                'email' => 'order.manager@example.com',
                'password' => 'secret-password',
                'roles' => [$assignedRole->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $createdUser = User::query()
            ->where('email', 'order.manager@example.com')
            ->firstOrFail();

        $this->assertTrue($createdUser->hasRole('order_manager'));
    }

    public function test_super_admin_cannot_change_own_roles_or_delete_self(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditAdminUser::class, [
            'record' => $superAdmin->getRouteKey(),
        ])->assertFormFieldIsDisabled('roles');

        $this->assertTrue(Gate::forUser($superAdmin)->denies('delete', $superAdmin));
    }

    public function test_resource_permissions_control_list_and_record_access(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('sales_rep', 'web'));

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse(LanguageResource::canViewAny());
        $this->get('/admin/languages')->assertForbidden();
        $this->assertFalse(Gate::forUser($user)->allows('update', new Language));

        $user->givePermissionTo(Permission::findOrCreate('ViewAny:Language', 'web'));

        $this->assertTrue(LanguageResource::canViewAny());
        $this->get('/admin/languages')->assertOk();
        $this->assertFalse(Gate::forUser($user)->allows('update', new Language));

        $user->givePermissionTo(Permission::findOrCreate('Update:Language', 'web'));

        $this->assertTrue(Gate::forUser($user)->allows('update', new Language));
    }

    public function test_product_and_color_view_permissions_provide_a_read_only_product_page(): void
    {
        $seasonId = DB::table('seasons')->insertGetId([
            'name' => 'Teszt szezon',
            'code' => 'TST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemMainGroupId = DB::table('item_main_groups')->insertGetId([
            'code' => 'TST',
            'name' => 'Teszt főcsoport',
            'name_hu' => 'Teszt főcsoport',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::query()->create([
            'season_id' => $seasonId,
            'item_main_group_id' => $itemMainGroupId,
            'model_code' => 'TEST001',
            'name_hu' => 'Teszt termék',
        ]);
        $color = Color::query()->create([
            'product_id' => $product->getKey(),
            'code' => '01',
            'name_hu' => 'Fekete',
        ]);

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('sales_rep', 'web'));
        $user->givePermissionTo([
            Permission::findOrCreate('ViewAny:Product', 'web'),
            Permission::findOrCreate('View:Product', 'web'),
            Permission::findOrCreate('ViewAny:Color', 'web'),
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get("/admin/products/{$product->getRouteKey()}")
            ->assertOk();
        $this->get("/admin/products/{$product->getRouteKey()}/edit")
            ->assertForbidden();

        $this->assertTrue(ColorsRelationManager::canViewForRecord($product, ViewProduct::class));

        $relationManager = Livewire::test(ColorsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => ViewProduct::class,
        ]);

        $this->assertTrue($relationManager->instance()->getTable()->hasAction('exportExcel'));

        foreach ($relationManager->instance()->getTable()->getColumns() as $column) {
            $this->assertTrue($column->isSortable());
            $this->assertTrue($column->isIndividuallySearchable());
        }

        $relationManager
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $color)
            ->assertTableActionHidden('delete', $color);
    }

    public function test_every_admin_resource_has_a_read_only_page_and_standard_table_features(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $resources = collect(Filament::getPanel('admin')->getResources())
            ->filter(fn (string $resource): bool => str_starts_with(
                $resource,
                'App\\Filament\\Resources\\',
            ))
            ->values();

        $this->assertCount(26, $resources);

        foreach ($resources as $resource) {
            $this->assertTrue($resource::hasPage('view'), "Missing view page on {$resource}");
            $this->assertTrue(
                is_subclass_of($resource::getPages()['view']->getPage(), ReadOnlyRecord::class),
                "The view page of {$resource} is not read-only",
            );

            $listPage = $resource::getPages()['index']->getPage();
            $table = Livewire::test($listPage)->instance()->getTable();

            $this->assertNotEmpty($table->getColumns(), "Missing columns on {$resource}");
            $this->assertTrue($table->hasAction('view'), "Missing view action on {$resource}");
            $this->assertTrue($table->hasAction('exportExcel'), "Missing Excel export on {$resource}");

            foreach ($table->getColumns() as $column) {
                $this->assertTrue(
                    $column->isSortable(),
                    "Column {$column->getName()} is not sortable on {$resource}",
                );
                $this->assertTrue(
                    $column->isIndividuallySearchable(),
                    "Column {$column->getName()} is not individually searchable on {$resource}",
                );

                $query = $resource::getEloquentQuery();

                foreach ($table->getColumns() as $aggregateColumn) {
                    $aggregateColumn->applyRelationshipAggregates($query);
                }

                $isFirstSearchConstraint = true;
                $column->applySearchConstraint($query, '0', $isFirstSearchConstraint);
                $column->applySort($query);
                $query->limit(1)->get();
            }
        }
    }

    public function test_excel_export_uses_the_current_table_filters(): void
    {
        $exportedBrand = Brand::query()->create([
            'code' => 'KEEP',
            'name' => 'Exportálandó',
        ]);
        Brand::query()->create([
            'code' => 'SKIP',
            'name' => 'Kihagyandó',
        ]);

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->travelTo(now()->setDate(2026, 9, 10)->setTime(12, 34, 56));

        $component = Livewire::test(ListBrands::class)
            ->searchTableColumns(['code' => 'KEEP']);
        $table = $component->instance()->getTable();
        $export = new AdminTableExport(
            $component->instance()->getTableQueryForExport(),
            array_values($table->getColumns()),
        );

        $this->assertContains('KEEP', $export->map($exportedBrand));
        $this->assertStringStartsWith('PK', Excel::raw($export, ExcelWriter::XLSX));

        Excel::fake();

        Livewire::test(ListBrands::class)
            ->searchTableColumns(['code' => 'KEEP'])
            ->callTableAction('exportExcel');

        Excel::assertDownloaded(
            'brands-20260910-123456.xlsx',
            fn (AdminTableExport $export): bool => $export->query()
                ->pluck('code')
                ->all() === ['KEEP'],
        );

        $this->travelBack();
    }

    public function test_skus_can_be_filtered_by_their_products_season(): void
    {
        $firstSeasonId = DB::table('seasons')->insertGetId([
            'name' => 'Első szezon',
            'code' => 'S01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondSeasonId = DB::table('seasons')->insertGetId([
            'name' => 'Második szezon',
            'code' => 'S02',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemMainGroupId = DB::table('item_main_groups')->insertGetId([
            'code' => 'TST',
            'name' => 'Teszt főcsoport',
            'name_hu' => 'Teszt főcsoport',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $firstProduct = Product::query()->create([
            'season_id' => $firstSeasonId,
            'item_main_group_id' => $itemMainGroupId,
            'model_code' => 'SEASON01',
            'name_hu' => 'Első szezon terméke',
        ]);
        $secondProduct = Product::query()->create([
            'season_id' => $secondSeasonId,
            'item_main_group_id' => $itemMainGroupId,
            'model_code' => 'SEASON02',
            'name_hu' => 'Második szezon terméke',
        ]);
        $firstColor = Color::query()->create([
            'product_id' => $firstProduct->getKey(),
            'code' => '01',
            'name_hu' => 'Első szín',
        ]);
        $secondColor = Color::query()->create([
            'product_id' => $secondProduct->getKey(),
            'code' => '02',
            'name_hu' => 'Második szín',
        ]);
        $firstSku = Sku::query()->create([
            'product_id' => $firstProduct->getKey(),
            'color_id' => $firstColor->getKey(),
            'sku_code' => 'SKU-SEASON-01',
            'sku_name' => 'Első SKU',
        ]);
        $secondSku = Sku::query()->create([
            'product_id' => $secondProduct->getKey(),
            'color_id' => $secondColor->getKey(),
            'sku_code' => 'SKU-SEASON-02',
            'sku_name' => 'Második SKU',
        ]);

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListSkus::class)
            ->filterTable('season_id', $firstSeasonId)
            ->assertCanSeeTableRecords([$firstSku])
            ->assertCanNotSeeTableRecords([$secondSku]);
    }

    public function test_calculated_columns_can_be_filtered_and_sorted(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach ([
            ListOrders::class => [
                'total_ordered_units',
                'total_effective_quantity',
                'total_value',
            ],
            ListPartnerUsers::class => [
                'addresses_count',
                'partners_count',
            ],
            ListTranslations::class => [
                'original_value',
            ],
        ] as $page => $columns) {
            foreach ($columns as $column) {
                Livewire::test($page)
                    ->searchTableColumns([$column => '0'])
                    ->sortTable($column)
                    ->assertCountTableRecords(0);
            }
        }
    }

    public function test_custom_page_permission_controls_page_access(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('sales_rep', 'web'));

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse(StockOrderProportioning::canAccess());

        $user->givePermissionTo(
            Permission::findOrCreate('View:StockOrderProportioning', 'web'),
        );

        $this->assertTrue(StockOrderProportioning::canAccess());
    }

    public function test_every_managed_resource_and_page_has_authorization_enabled(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $resources = collect(Shield::getResources());
        $pages = collect(Shield::getPages());

        $this->assertCount(26, $resources);
        $this->assertCount(5, $pages);

        foreach ($resources as $resource) {
            $policy = Gate::getPolicyFor($resource['modelFqcn']);

            $this->assertNotNull($policy, "Missing policy for {$resource['modelFqcn']}");
            $policyClass = $policy::class;

            foreach (['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'] as $method) {
                $this->assertTrue(
                    method_exists($policy, $method),
                    "Missing {$method} method on {$policyClass}",
                );
            }
        }

        foreach ($pages->keys() as $pageClass) {
            $this->assertContains(
                HasPageShield::class,
                class_uses_recursive($pageClass),
                "Missing page authorization on {$pageClass}",
            );
        }
    }
}

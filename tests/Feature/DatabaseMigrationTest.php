<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DatabaseMigrationTest extends TestCase
{
    public function test_fresh_database_schema_can_be_built(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The pdo_sqlite extension is required.');
        }

        $this->artisan('migrate:fresh', [
            '--database' => 'sqlite',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertTrue(Schema::hasColumns('item_main_groups', [
            'code',
            'name_hu',
            'name_en',
            'active',
        ]));
        $this->assertTrue(Schema::hasTable('size_ranges'));
        $this->assertTrue(Schema::hasTable('size_range_items'));
        $this->assertTrue(Schema::hasColumn('partners', 'sales_rep_erp_partner_code'));
        $this->assertFalse(Schema::hasColumn('products', 'serial_number'));
        $this->assertTrue(Schema::hasColumn('skus', 'sku_name'));
        $this->assertTrue(Schema::hasColumn('price_list_items', 'product_id'));
        $this->assertFalse(Schema::hasColumn('price_list_items', 'sku_id'));
        $this->assertTrue(Schema::hasColumn('order_import_logs', 'partner_user_id'));
        $this->assertTrue(Schema::hasTable('roles'));
        $this->assertTrue(Schema::hasTable('permissions'));
        $this->assertTrue(Schema::hasTable('model_has_roles'));
        $this->assertTrue(Schema::hasTable('model_has_permissions'));
        $this->assertTrue(Schema::hasTable('role_has_permissions'));
        $this->assertTrue(Schema::hasTable('order_types'));
        $this->assertTrue(Schema::hasTable('order_share_links'));
        $this->assertTrue(Schema::hasColumns('orders', [
            'reference_number',
            'order_type_id',
            'sordid',
        ]));
        $this->assertTrue(Schema::hasColumns('order_types', [
            'code',
            'name',
            'include_in_supplier_order',
            'active',
        ]));
        $this->assertTrue(Schema::hasColumns('order_share_links', [
            'order_id',
            'token',
            'price_list_id',
            'created_by_partner_user_id',
            'expires_at',
            'last_accessed_at',
            'revoked_at',
        ]));
        $this->assertSame(5, DB::table('order_types')->count());
        $this->assertTrue(
            (bool) DB::table('order_types')
                ->where('code', 'VRELO')
                ->value('include_in_supplier_order')
        );
        $this->assertFalse(
            (bool) DB::table('order_types')
                ->where('code', 'TSTOCK')
                ->value('include_in_supplier_order')
        );

        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'migration_test_admin',
            'guard_name' => 'web',
        ]);

        $user->assignRole($role);

        $this->assertTrue($user->hasRole('migration_test_admin'));
    }
}

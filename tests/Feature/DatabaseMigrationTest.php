<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use PDO;
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
        $this->assertTrue(Schema::hasColumn('order_import_logs', 'partner_user_id'));
    }
}

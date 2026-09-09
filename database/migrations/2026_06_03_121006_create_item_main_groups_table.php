<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // The table and these columns are already created by the earlier
        // 2026_06_03_115303_create_itemmaingroups_table migration.
    }

    public function down(): void
    {
        // The earlier migration owns the table and removes it on rollback.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // partner_user_id is already added by the immediately preceding
        // 2026_06_09_161747_add_partner_user_id_to_order_import_logs_table migration.
    }

    public function down(): void
    {
        // The preceding migration owns the column and removes it on rollback.
    }
};

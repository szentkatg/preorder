<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_import_logs', function (Blueprint $table) {
            $table->foreignId('partner_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('partner_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_import_logs', function (Blueprint $table) {
            $table->dropForeign(['partner_user_id']);
            $table->dropColumn('partner_user_id');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }
};
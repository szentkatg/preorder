<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('brand_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('order_sheet_type_id')
                ->nullable()
                ->after('brand_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_sheet_type_id');
            $table->dropConstrainedForeignId('brand_id');
        });
    }
};
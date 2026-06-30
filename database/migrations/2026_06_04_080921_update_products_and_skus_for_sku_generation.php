<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'serial_number')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('serial_number');
            });
        }

        if (! Schema::hasColumn('skus', 'sku_name')) {
            Schema::table('skus', function (Blueprint $table) {
                $table->string('sku_name')->after('sku_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('skus', 'sku_name')) {
            Schema::table('skus', function (Blueprint $table) {
                $table->dropColumn('sku_name');
            });
        }

        if (! Schema::hasColumn('products', 'serial_number')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('serial_number', 3)->after('size_range_id');
            });
        }
    }
};
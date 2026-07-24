<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(
                ['season_id', 'brand_id', 'active'],
                'products_selector_brand_index'
            );

            $table->index(
                ['season_id', 'brand_id', 'order_sheet_type_id', 'active'],
                'products_selector_type_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_selector_brand_index');
            $table->dropIndex('products_selector_type_index');
        });
    }
};

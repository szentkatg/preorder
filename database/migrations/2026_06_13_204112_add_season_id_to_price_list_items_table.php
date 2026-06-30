<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSeasonIdToPriceListItemsTable extends Migration
{
    public function up(): void
    {
        Schema::table('price_list_items', function (Blueprint $table) {
            $table->foreignId('season_id')
                ->nullable()
                ->after('price_list_id')
                ->constrained('seasons')
                ->cascadeOnDelete();

            $table->unique(
                ['price_list_id', 'season_id', 'product_id'],
                'price_list_items_price_season_product_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('price_list_items', function (Blueprint $table) {
            $table->dropUnique('price_list_items_price_season_product_unique');
            $table->dropConstrainedForeignId('season_id');
        });
    }
}
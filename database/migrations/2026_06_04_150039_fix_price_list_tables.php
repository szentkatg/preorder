<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->string('code')->unique()->after('id');
            $table->string('name_hu')->after('code');
            $table->string('name_en')->nullable()->after('name_hu');

            $table->foreignId('season_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete()
                ->after('name_en');

            $table->boolean('active')
                ->default(true)
                ->after('season_id');
        });

        Schema::table('price_list_items', function (Blueprint $table) {
            $table->foreignId('price_list_id')
                ->constrained()
                ->cascadeOnDelete()
                ->after('id');

            $table->foreignId('sku_id')
                ->constrained()
                ->cascadeOnDelete()
                ->after('price_list_id');

            $table->decimal('net_price', 12, 2)
                ->after('sku_id');

            $table->unique(
                ['price_list_id', 'sku_id'],
                'price_list_sku_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('price_list_items', function (Blueprint $table) {
            $table->dropUnique('price_list_sku_unique');

            $table->dropConstrainedForeignId('sku_id');
            $table->dropConstrainedForeignId('price_list_id');

            $table->dropColumn('net_price');
        });

        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropConstrainedForeignId('season_id');

            $table->dropColumn([
                'code',
                'name_hu',
                'name_en',
                'active',
            ]);
        });
    }
};
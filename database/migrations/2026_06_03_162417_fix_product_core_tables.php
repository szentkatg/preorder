<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sizes', function (Blueprint $table) {
            $table->string('code')->unique()->after('id');
            $table->integer('sort_order')->default(0)->after('code');
            $table->boolean('active')->default(true)->after('sort_order');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('season_id')->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_main_group_id')->after('season_id')->constrained()->restrictOnDelete();
            $table->foreignId('size_range_id')->nullable()->after('item_main_group_id')->constrained()->nullOnDelete();

            $table->string('serial_number', 3)->after('size_range_id');
            $table->string('model_code')->index()->after('serial_number');

            $table->string('name_hu')->after('model_code');
            $table->string('name_en')->nullable()->after('name_hu');

            $table->string('catalog_group_name_hu')->nullable()->after('name_en');
            $table->string('catalog_group_name_en')->nullable()->after('catalog_group_name_hu');
            $table->integer('catalog_group_sort')->default(0)->after('catalog_group_name_en');
            $table->integer('catalog_page')->nullable()->after('catalog_group_sort');

            $table->boolean('active')->default(true)->after('catalog_page');

            $table->unique(['season_id', 'item_main_group_id', 'serial_number'], 'products_unique_model');
        });

        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('code', 2);
            $table->string('name_hu');
            $table->string('name_en')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'code']);
        });

        Schema::create('skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('color_id')->constrained()->cascadeOnDelete();
            $table->foreignId('size_id')->nullable()->constrained()->nullOnDelete();

            $table->string('sku_code')->unique();
            $table->string('type')->default('normal');

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'color_id', 'type']);
        });

        Schema::create('item_assortments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('color_id')->constrained()->cascadeOnDelete();

            $table->foreignId('assortment_sku_id')
                ->constrained('skus')
                ->cascadeOnDelete();

            $table->foreignId('component_sku_id')
                ->constrained('skus')
                ->cascadeOnDelete();

            $table->integer('quantity');

            $table->timestamps();

            $table->unique(['assortment_sku_id', 'component_sku_id'], 'item_assortment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_assortments');
        Schema::dropIfExists('skus');
        Schema::dropIfExists('colors');

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_unique_model');

            $table->dropForeign(['season_id']);
            $table->dropForeign(['item_main_group_id']);
            $table->dropForeign(['size_range_id']);

            $table->dropColumn([
                'season_id',
                'item_main_group_id',
                'size_range_id',
                'serial_number',
                'model_code',
                'name_hu',
                'name_en',
                'catalog_group_name_hu',
                'catalog_group_name_en',
                'catalog_group_sort',
                'catalog_page',
                'active',
            ]);
        });

        Schema::table('sizes', function (Blueprint $table) {
            $table->dropColumn([
                'code',
                'sort_order',
                'active',
            ]);
        });
    }
};

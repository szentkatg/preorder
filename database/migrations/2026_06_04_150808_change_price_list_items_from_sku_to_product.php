<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('price_list_items', 'product_id')) {
            Schema::table('price_list_items', function (Blueprint $table): void {
                $table->foreignId('product_id')
                    ->nullable()
                    ->after('price_list_id')
                    ->constrained()
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('price_list_items', 'sku_id')) {
            DB::table('price_list_items')
                ->select(['id', 'sku_id'])
                ->orderBy('id')
                ->each(function (object $item): void {
                    DB::table('price_list_items')
                        ->where('id', $item->id)
                        ->update([
                            'product_id' => DB::table('skus')
                                ->where('id', $item->sku_id)
                                ->value('product_id'),
                        ]);
                });

            Schema::table('price_list_items', function (Blueprint $table): void {
                $table->dropUnique('price_list_sku_unique');
                $table->dropConstrainedForeignId('sku_id');
            });
        }
    }

    public function down(): void
    {
        // Not reversible.
    }
};

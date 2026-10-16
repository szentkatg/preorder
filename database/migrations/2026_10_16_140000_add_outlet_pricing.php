<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->foreignId('source_retail_price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
        });
        Schema::table('pricing_calculation_rows', function (Blueprint $table): void {
            $table->decimal('outlet_discount_percent', 9, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pricing_calculation_rows', fn (Blueprint $table) => $table->dropColumn('outlet_discount_percent'));
        Schema::table('price_lists', fn (Blueprint $table) => $table->dropConstrainedForeignId('source_retail_price_list_id'));
    }
};

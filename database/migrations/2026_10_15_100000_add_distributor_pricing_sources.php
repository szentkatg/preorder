<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->foreignId('source_wholesale_price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
        });
        Schema::table('pricing_calculation_rows', function (Blueprint $table): void {
            $table->foreignId('source_wholesale_row_id')->nullable()->constrained('pricing_calculation_rows')->nullOnDelete();
            $table->decimal('distributor_discount_percent', 9, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pricing_calculation_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_wholesale_row_id');
            $table->dropColumn('distributor_discount_percent');
        });
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_wholesale_price_list_id');
        });
    }
};

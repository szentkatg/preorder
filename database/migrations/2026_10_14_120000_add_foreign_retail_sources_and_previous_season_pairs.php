<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('previous_season_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->boolean('previous_season_match_override')->default(false);
        });
        Schema::table('pricing_calculation_rows', function (Blueprint $table): void {
            $table->foreignId('source_retail_row_id')->nullable()->constrained('pricing_calculation_rows')->nullOnDelete();
            $table->decimal('source_retail_price', 15, 4)->nullable();
            $table->decimal('retail_exchange_rate', 15, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pricing_calculation_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_retail_row_id');
            $table->dropColumn(['source_retail_price', 'retail_exchange_rate']);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('previous_season_product_id');
            $table->dropColumn('previous_season_match_override');
        });
    }
};

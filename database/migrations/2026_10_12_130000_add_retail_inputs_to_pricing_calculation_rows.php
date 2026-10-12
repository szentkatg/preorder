<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_calculation_rows', function (Blueprint $table): void {
            $table->decimal('base_price', 15, 4)->nullable();
            $table->decimal('multiplier', 15, 6)->nullable();
            $table->decimal('unrounded_price', 15, 4)->nullable();
            $table->foreignId('pricing_rounding_rule_id')->nullable()
                ->constrained('pricing_rounding_rules')->nullOnDelete();
            $table->decimal('retained_price', 15, 4)->nullable();
            $table->boolean('requires_review')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('pricing_calculation_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pricing_rounding_rule_id');
            $table->dropColumn(['base_price', 'multiplier', 'unrounded_price', 'retained_price', 'requires_review']);
        });
    }
};

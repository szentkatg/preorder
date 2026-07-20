<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('rounding_rule_id')
                ->nullable();

            $table->date('promised_delivery_date')
                ->nullable();

            $table->foreign('rounding_rule_id')
                ->references('rounding_rule_id')
                ->on('rounding_rules')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['rounding_rule_id']);

            $table->dropColumn([
                'rounding_rule_id',
                'promised_delivery_date',
            ]);
        });
    }
};
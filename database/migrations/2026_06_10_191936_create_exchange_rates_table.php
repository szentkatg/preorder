<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
    Schema::create('exchange_rates', function (Blueprint $table) {
        $table->id();
        $table->foreignId('season_id')->constrained('seasons')->cascadeOnDelete();
        $table->foreignId('currency_id')->constrained('currencies')->cascadeOnDelete();
        $table->decimal('rate_to_huf', 12, 4);
        $table->date('valid_from')->nullable();
        $table->boolean('active')->default(true);
        $table->timestamps();
    
        $table->unique(['season_id', 'currency_id'], 'exchange_rates_season_currency_unique');
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};

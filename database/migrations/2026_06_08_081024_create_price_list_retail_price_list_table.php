<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_retail_price_list', function (Blueprint $table) {
            $table->id();

            $table->foreignId('price_list_id')
                ->constrained('price_lists')
                ->cascadeOnDelete();

            $table->foreignId('retail_price_list_id')
                ->constrained('price_lists')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique('price_list_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_retail_price_list');
    }
};
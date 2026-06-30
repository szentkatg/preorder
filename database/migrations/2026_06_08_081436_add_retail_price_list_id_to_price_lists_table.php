<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->foreignId('retail_price_list_id')
                ->nullable()
                ->after('currency_id')
                ->constrained('price_lists')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retail_price_list_id');
        });
    }
};
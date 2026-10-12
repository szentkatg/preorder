<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only the obsolete header season is removed. Item seasons and prices stay intact.
        // Back up the database before deployment: down() restores the schema, not the old values.
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('season_id');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->foreignId('season_id')
                ->nullable()
                ->constrained('seasons')
                ->nullOnDelete();
        });
    }
};

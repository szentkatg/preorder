<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            if (! Schema::hasColumn('currencies', 'code')) {
                $table->string('code', 3)->unique()->after('id');
            }

            if (! Schema::hasColumn('currencies', 'name_hu')) {
                $table->string('name_hu')->after('code');
            }

            if (! Schema::hasColumn('currencies', 'name_en')) {
                $table->string('name_en')->nullable()->after('name_hu');
            }

            if (! Schema::hasColumn('currencies', 'symbol')) {
                $table->string('symbol', 10)->nullable()->after('name_en');
            }

            if (! Schema::hasColumn('currencies', 'active')) {
                $table->boolean('active')->default(true)->after('symbol');
            }
        });

        Schema::table('price_lists', function (Blueprint $table) {
            if (! Schema::hasColumn('price_lists', 'currency_id')) {
                $table->foreignId('currency_id')
                    ->nullable()
                    ->after('season_id')
                    ->constrained()
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            if (Schema::hasColumn('price_lists', 'currency_id')) {
                $table->dropConstrainedForeignId('currency_id');
            }
        });

        Schema::table('currencies', function (Blueprint $table) {
            foreach (['code', 'name_hu', 'name_en', 'symbol', 'active'] as $column) {
                if (Schema::hasColumn('currencies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
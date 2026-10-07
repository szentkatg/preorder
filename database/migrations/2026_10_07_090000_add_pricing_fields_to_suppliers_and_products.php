<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('country_code', 2)
                ->nullable()
                ->after('short_name')
                ->index();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->text('material_composition')
                ->nullable()
                ->after('name_en');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('material_composition');
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropIndex(['country_code']);
            $table->dropColumn('country_code');
        });
    }
};

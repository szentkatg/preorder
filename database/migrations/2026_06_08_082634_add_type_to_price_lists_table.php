<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->string('type')
                ->default('wholesale')
                ->after('name_en');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
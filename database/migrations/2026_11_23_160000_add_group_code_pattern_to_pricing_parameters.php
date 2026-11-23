<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_parameters', fn (Blueprint $table) => $table->string('item_main_group_pattern', 100)->nullable());
    }

    public function down(): void
    {
        Schema::table('pricing_parameters', fn (Blueprint $table) => $table->dropColumn('item_main_group_pattern'));
    }
};

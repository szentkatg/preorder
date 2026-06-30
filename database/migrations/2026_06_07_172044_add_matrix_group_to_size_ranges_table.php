<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('size_ranges', function (Blueprint $table) {
            $table->string('matrix_group')
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('size_ranges', function (Blueprint $table) {
            $table->dropColumn('matrix_group');
        });
    }
};
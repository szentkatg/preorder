<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_main_groups', function (Blueprint $table) {
            $table->string('code', 3)->unique()->after('id');
            $table->string('name_hu')->after('code');
            $table->string('name_en')->nullable()->after('name_hu');
            $table->boolean('active')->default(true)->after('name_en');
        });
    }

    public function down(): void
    {
        Schema::table('item_main_groups', function (Blueprint $table) {
            $table->dropColumn([
                'code',
                'name_hu',
                'name_en',
                'active',
            ]);
        });
    }
};
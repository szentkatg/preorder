<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasons', fn (Blueprint $table) => $table->boolean('visible_to_partners')->default(false));
        // Preserve existing access, including historical/inactive seasons.
        // All seasons inserted after this migration start hidden.
        DB::table('seasons')->update(['visible_to_partners' => true]);
    }

    public function down(): void
    {
        Schema::table('seasons', fn (Blueprint $table) => $table->dropColumn('visible_to_partners'));
    }
};

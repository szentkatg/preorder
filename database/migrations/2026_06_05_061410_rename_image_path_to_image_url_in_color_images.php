<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('color_images', function (Blueprint $table) {
            $table->renameColumn('image_path', 'image_url');
        });
    }

    public function down(): void
    {
        Schema::table('color_images', function (Blueprint $table) {
            $table->renameColumn('image_url', 'image_path');
        });
    }
};
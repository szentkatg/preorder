<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::create('catalogs', function (Blueprint $table) {
        $table->id();

        $table->foreignId('season_id')->constrained()->cascadeOnDelete();
        $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
        $table->foreignId('order_sheet_type_id')->constrained()->cascadeOnDelete();

        $table->string('name');
        $table->string('pdf_file')->nullable();
        $table->string('image_folder');

        $table->integer('page_offset')->default(0);

        $table->boolean('active')->default(true);

        $table->timestamps();

        $table->unique([
            'season_id',
            'brand_id',
            'order_sheet_type_id',
        ], 'catalog_context_unique');
    });
}

public function down(): void
{
    Schema::dropIfExists('catalogs');
}
};

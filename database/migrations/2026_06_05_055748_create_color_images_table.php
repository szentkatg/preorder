<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('color_images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('color_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('image_path');

            $table->integer('sort_order')->default(0);

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index(['product_id', 'color_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('color_images');
    }
};
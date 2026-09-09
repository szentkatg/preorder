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
        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        Schema::create('size_ranges', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name_hu');
            $table->string('name_en')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('size_range_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('size_range_id')->constrained()->cascadeOnDelete();
            $table->foreignId('size_id')->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['size_range_id', 'size_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('size_range_items');
        Schema::dropIfExists('size_ranges');
        Schema::dropIfExists('sizes');
    }
};

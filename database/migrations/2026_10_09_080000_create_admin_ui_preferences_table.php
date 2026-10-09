<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_ui_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('preference_key');
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'preference_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_ui_preferences');
    }
};

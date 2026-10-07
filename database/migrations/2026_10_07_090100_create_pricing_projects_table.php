<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('name');
            $table->string('status', 40)->default('draft');
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['season_id', 'status', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_projects');
    }
};

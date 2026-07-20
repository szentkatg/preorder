<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rounding_rules', function (Blueprint $table) {
            $table->bigIncrements('rounding_rule_id');

            $table->string('code', 50)->unique();
            $table->string('name');

            $table->boolean('include_assortments')->default(false);

            $table->unsignedSmallInteger('rounding_multiple');

            $table->enum('rounding_mode', [
                'threshold',
                'floor',
                'ceil',
            ])->default('threshold');

            $table->unsignedSmallInteger('round_up_from_remainder')
                ->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rounding_rules');
    }
};
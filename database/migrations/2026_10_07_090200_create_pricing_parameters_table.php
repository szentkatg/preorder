<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_parameters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pricing_project_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('parameter_type', 80);
            $table->string('scope_type', 40)->default('global');
            $table->string('supplier_country_code', 2)->nullable();
            $table->foreignId('item_main_group_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('price_list_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('currency_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->decimal('value', 15, 6);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(
                ['pricing_project_id', 'parameter_type', 'scope_type', 'active'],
                'pricing_parameters_lookup_index'
            );
            $table->index(
                ['price_list_id', 'currency_id'],
                'pricing_parameters_price_currency_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_parameters');
    }
};

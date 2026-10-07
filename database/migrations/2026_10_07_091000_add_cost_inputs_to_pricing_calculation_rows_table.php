<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_calculation_rows', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_purchase_price_id')
                ->nullable()
                ->after('color_id');
            $table->unsignedBigInteger('supplier_id')
                ->nullable()
                ->after('product_purchase_price_id');
            $table->foreignId('purchase_currency_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('currencies')
                ->nullOnDelete();
            $table->decimal('purchase_price', 15, 4)
                ->nullable()
                ->after('purchase_currency_id');
            $table->decimal('exchange_rate', 15, 6)
                ->nullable()
                ->after('purchase_price');
            $table->decimal('shipping_cost_percent', 8, 4)
                ->nullable()
                ->after('exchange_rate');
            $table->decimal('customs_percent', 8, 4)
                ->nullable()
                ->after('shipping_cost_percent');
            $table->unsignedInteger('candidate_count')
                ->default(1)
                ->after('customs_percent');

            $table->foreign('product_purchase_price_id', 'pricing_rows_purchase_price_fk')
                ->references('product_purchase_price_id')
                ->on('product_purchase_prices')
                ->nullOnDelete();

            $table->foreign('supplier_id', 'pricing_rows_supplier_fk')
                ->references('supplier_id')
                ->on('suppliers')
                ->nullOnDelete();

            $table->index(
                ['pricing_project_id', 'price_type', 'supplier_id'],
                'pricing_rows_project_type_supplier_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('pricing_calculation_rows', function (Blueprint $table): void {
            $table->dropIndex('pricing_rows_project_type_supplier_index');
            $table->dropForeign('pricing_rows_purchase_price_fk');
            $table->dropForeign('pricing_rows_supplier_fk');
            $table->dropConstrainedForeignId('purchase_currency_id');
            $table->dropColumn([
                'product_purchase_price_id',
                'supplier_id',
                'purchase_price',
                'exchange_rate',
                'shipping_cost_percent',
                'customs_percent',
                'candidate_count',
            ]);
        });
    }
};

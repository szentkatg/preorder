<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('include_in_supplier_order')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $now = now();

        DB::table('order_types')->insert([
            [
                'code' => 'VRELO',
                'name' => 'Normál előrendelés',
                'include_in_supplier_order' => true,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'VRZELO',
                'name' => 'Bizományos előrendelés',
                'include_in_supplier_order' => true,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'VRBELO',
                'name' => 'Bolti előrendelés',
                'include_in_supplier_order' => true,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'TSTOCK',
                'name' => 'Készlet rendelés',
                'include_in_supplier_order' => false,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'TSHARE',
                'name' => 'Arányosításhoz',
                'include_in_supplier_order' => false,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('reference_number', 100)
                ->nullable()
                ->after('order_sheet_type_id');
            $table->foreignId('order_type_id')
                ->nullable()
                ->after('reference_number')
                ->constrained('order_types')
                ->restrictOnDelete();
        });

        $defaultOrderTypeId = DB::table('order_types')
            ->where('code', 'VRELO')
            ->value('id');

        DB::table('orders')
            ->orderBy('id')
            ->eachById(function (object $order) use ($defaultOrderTypeId): void {
                DB::table('orders')
                    ->where('id', $order->id)
                    ->update([
                        'reference_number' => 'LEGACY-'.$order->id,
                        'order_type_id' => $defaultOrderTypeId,
                    ]);
            });

        Schema::table('orders', function (Blueprint $table) {
            $table->unique([
                'season_id',
                'partner_id',
                'partner_address_id',
                'brand_id',
                'order_sheet_type_id',
                'reference_number',
            ], 'orders_reference_context_unique');

            $table->index('reference_number', 'orders_reference_number_index');
            $table->index('order_type_id', 'orders_order_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_reference_context_unique');
            $table->dropIndex('orders_reference_number_index');
            $table->dropIndex('orders_order_type_index');
            $table->dropConstrainedForeignId('order_type_id');
            $table->dropColumn('reference_number');
        });

        Schema::dropIfExists('order_types');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('sales_rep_erp_partner_code')
                ->nullable()
                ->after('erp_partner_code')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropIndex(['sales_rep_erp_partner_code']);
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('sales_rep_erp_partner_code');
        });
    }
};

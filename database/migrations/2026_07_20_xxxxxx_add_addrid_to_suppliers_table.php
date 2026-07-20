public function up(): void
{
    Schema::table('suppliers', function (Blueprint $table) {
        $table->string('addrid', 20)
            ->nullable()
            ->after('erp_partner_code');
    });
}

public function down(): void
{
    Schema::table('suppliers', function (Blueprint $table) {
        $table->dropColumn('addrid');
    });
}
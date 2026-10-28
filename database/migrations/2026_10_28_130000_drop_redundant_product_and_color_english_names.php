<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FIELDS = [
        'products' => ['name_en' => 'name_hu', 'catalog_group_name_en' => 'catalog_group_name_hu'],
        'colors' => ['name_en' => 'name_hu'],
    ];

    public function up(): void
    {
        // Check ALL fields first: MySQL DDL cannot be rolled back as a group.
        // Compare in PHP, not a case-insensitive / trailing-space-insensitive collation.
        foreach (self::FIELDS as $table => $fields) {
            foreach ($fields as $legacy => $common) {
                if (! Schema::hasColumn($table, $legacy)) {
                    continue;
                }

                DB::table($table)->select(['id', $legacy, $common])->orderBy('id')
                    ->chunkById(500, function ($records) use ($table, $legacy, $common): void {
                        foreach ($records as $record) {
                            $value = (string) ($record->{$legacy} ?? '');
                            if ($value !== '' && $value !== (string) ($record->{$common} ?? '')) {
                                throw new RuntimeException("Néveltérés: {$table} #{$record->id}, {$legacy} / {$common}. A migráció nem törölt oszlopot; előbb rendezd az eltérést.");
                            }
                        }
                    });
            }
        }

        foreach (self::FIELDS as $table => $fields) {
            $columns = array_values(array_filter(array_keys($fields), fn ($column) => Schema::hasColumn($table, $column)));
            if ($columns !== []) {
                Schema::table($table, fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
    }

    public function down(): void
    {
        // Rollback restores the old columns from the CURRENT shared values.
        foreach (self::FIELDS as $table => $fields) {
            $restore = [];
            foreach ($fields as $legacy => $common) {
                if (! Schema::hasColumn($table, $legacy)) {
                    Schema::table($table, fn (Blueprint $table) => $table->string($legacy)->nullable());
                    $restore[$legacy] = DB::raw($common);
                }
            }
            if ($restore !== []) {
                DB::table($table)->update($restore);
            }
        }
    }
};

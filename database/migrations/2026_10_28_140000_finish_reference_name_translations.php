<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ARCHIVE = 'legacy_name_archives';

    private const KEY = '2026_10_28_reference_names';

    private const ENTITIES = [
        'price_lists' => 'price_list', 'order_sheet_types' => 'order_sheet_type',
        'currencies' => 'currency', 'languages' => 'language', 'item_main_groups' => 'item_main_group',
    ];

    public function up(): void
    {
        $languages = DB::table('languages')->get(['id', 'code'])->groupBy(fn ($row) => strtoupper(trim($row->code)));
        foreach (['HU' => 'name_hu', 'EN' => 'name_en'] as $language => $column) {
            $needed = false;
            foreach (array_keys(self::ENTITIES) as $table) {
                if (Schema::hasColumn($table, $column) && DB::table($table)->whereNotNull($column)->where($column, '!=', '')->exists()) {
                    $needed = true;
                }
            }
            if ($needed && ($languages->get($language)?->count() ?? 0) !== 1) {
                throw new RuntimeException("A névmigrációhoz pontosan egy {$language} nyelv szükséges. Nem történt oszloptörlés.");
            }
        }
        if (! Schema::hasTable(self::ARCHIVE)) {
            Schema::create(self::ARCHIVE, function (Blueprint $table): void {
                $table->id();
                $table->string('migration_key', 64);
                $table->string('source_table', 64);
                $table->unsignedBigInteger('source_id');
                $table->json('payload');
                $table->unique(['migration_key', 'source_table', 'source_id'], 'legacy_names_source_unique');
            });
        }
        if (! Schema::hasColumn('price_lists', 'name')) {
            Schema::table('price_lists', fn (Blueprint $table) => $table->string('name')->nullable());
        }

        foreach ([...array_keys(self::ENTITIES), 'size_ranges'] as $table) {
            $legacyColumns = $table === 'size_ranges' ? ['name_en'] : ['name_hu', 'name_en'];
            $legacyColumns = array_values(array_filter($legacyColumns, fn ($column) => Schema::hasColumn($table, $column)));
            if ($legacyColumns === []) {
                continue;
            }
            DB::table($table)->orderBy('id')->chunkById(300, function ($records) use ($table, $legacyColumns, $languages): void {
                foreach ($records as $record) {
                    $payload = ['code' => $record->code];
                    foreach ($legacyColumns as $column) {
                        $payload[$column] = $record->{$column};
                    }
                    DB::table(self::ARCHIVE)->insertOrIgnore([
                        'migration_key' => self::KEY, 'source_table' => $table, 'source_id' => $record->id,
                        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]);
                    if ($table === 'size_ranges') {
                        continue;
                    }
                    if (trim((string) $record->name) === '') {
                        $name = trim((string) ($record->name_hu ?? '')) ?: (trim((string) ($record->name_en ?? '')) ?: $record->code);
                        DB::table($table)->where('id', $record->id)->update(['name' => $name]);
                    }
                    foreach (['HU' => 'name_hu', 'EN' => 'name_en'] as $language => $column) {
                        $value = trim((string) ($record->{$column} ?? ''));
                        $languageId = $languages->get($language)?->first()?->id;
                        if ($value === '' || ! $languageId) {
                            continue;
                        }
                        DB::table('translations')->insertOrIgnore([
                            'entity' => self::ENTITIES[$table], 'entity_code' => $record->code, 'field' => 'name',
                            'language_id' => $languageId, 'value' => $value, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            });
        }
        // Every legacy value is archived and translations are seeded BEFORE any drop.
        foreach ([...array_keys(self::ENTITIES), 'size_ranges'] as $table) {
            $columns = $table === 'size_ranges' ? ['name_en'] : ['name_hu', 'name_en'];
            $columns = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));
            if ($columns !== []) {
                Schema::table($table, fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
    }

    public function down(): void
    {
        foreach ([...array_keys(self::ENTITIES), 'size_ranges'] as $table) {
            $columns = $table === 'size_ranges' ? ['name_en'] : ['name_hu', 'name_en'];
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $table) => $table->string($column)->nullable());
                }
            }
            if ($table !== 'size_ranges') {
                DB::table($table)->update(['name_hu' => DB::raw('name')]);
            }
        }
        if (Schema::hasTable(self::ARCHIVE)) {
            DB::table(self::ARCHIVE)->where('migration_key', self::KEY)->orderBy('id')->each(function ($row): void {
                $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
                $code = $payload['code'];
                unset($payload['code']);
                DB::table($row->source_table)->where('id', $row->source_id)->where('code', $code)->update($payload);
            });
            Schema::drop(self::ARCHIVE);
        }
        Schema::table('price_lists', fn (Blueprint $table) => $table->dropColumn('name'));
        // Translations are deliberately retained; rollback must not erase later edits.
    }
};

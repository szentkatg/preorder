<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const ENTITIES = [
        'order_sheet_types' => 'order_sheet_type',
        'currencies' => 'currency',
        'languages' => 'language',
        'item_main_groups' => 'item_main_group',
    ];

    public function up(): void
    {
        foreach (array_keys(self::ENTITIES) as $table) {
            if (! Schema::hasColumn($table, 'name')) {
                Schema::table($table, function (Blueprint $table): void {
                    $table->string('name')->nullable()->after('code');
                });
            }
        }

        foreach (array_keys(self::ENTITIES) as $table) {
            DB::table($table)
                ->select(['id', 'code', 'name_hu', 'name_en'])
                ->orderBy('id')
                ->each(function (object $record) use ($table): void {
                    DB::table($table)
                        ->where('id', $record->id)
                        ->update([
                            'name' => $this->firstFilled(
                                $record->name_hu,
                                $record->name_en,
                                $record->code
                            ),
                        ]);
                });
        }

        $languageIds = DB::table('languages')
            ->select(['id', 'code'])
            ->get()
            ->mapWithKeys(fn (object $language): array => [
                strtoupper((string) $language->code) => (int) $language->id,
            ]);

        $now = now();

        foreach (self::ENTITIES as $table => $entity) {
            DB::table($table)
                ->select(['code', 'name_hu', 'name_en'])
                ->orderBy('id')
                ->each(function (object $record) use ($entity, $languageIds, $now): void {
                    foreach (['HU' => 'name_hu', 'EN' => 'name_en'] as $languageCode => $column) {
                        $value = trim((string) ($record->{$column} ?? ''));
                        $languageId = $languageIds->get($languageCode);

                        if ($value === '' || ! $languageId) {
                            continue;
                        }

                        DB::table('translations')->insertOrIgnore([
                            'entity' => $entity,
                            'entity_code' => $record->code,
                            'field' => 'name',
                            'language_id' => $languageId,
                            'value' => $value,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::ENTITIES) as $table) {
            if (Schema::hasColumn($table, 'name')) {
                Schema::table($table, function (Blueprint $table): void {
                    $table->dropColumn('name');
                });
            }
        }
    }

    private function firstFilled(?string ...$values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
};

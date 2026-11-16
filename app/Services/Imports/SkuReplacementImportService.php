<?php

namespace App\Services\Imports;

use App\Models\Season;
use App\Models\Sku;
use App\Models\SkuReplacement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class SkuReplacementImportService
{
    public const MAX_ROWS = 10000;

    public static function modes(): array
    {
        return ['skip' => 'Meglévő cserék kihagyása', 'overwrite' => 'Meglévő cserék felülírása'];
    }

    public function preview(mixed $file, string $mode = 'skip'): array
    {
        $this->authorize($mode);

        return $this->plan($this->read($file), $mode);
    }

    public function import(mixed $file, string $mode, string $expectedToken): array
    {
        $this->authorize($mode);
        $source = $this->read($file);

        return DB::transaction(function () use ($source, $mode, $expectedToken): array {
            $plan = $this->plan($source, $mode, lock: true);
            if ($plan['errors'] !== []) {
                $this->fail('Az Excel vagy a cikkcsere-törzs hibás. Ellenőrizd újra az importot; semmi sem módosult.');
            }
            if ($expectedToken === '' || ! hash_equals($plan['token'], $expectedToken)) {
                $this->fail('A fájl, az import módja vagy az érintett törzsadatok az ellenőrzés óta megváltoztak. Kérj új előnézetet; semmi sem módosult.');
            }
            $now = now()->format('Y-m-d H:i:s');
            $writes = [];
            foreach ($plan['rows'] as $row) {
                if (! in_array($row['status'], ['create', 'overwrite'], true)) {
                    continue;
                }
                $writes[] = ['season_id' => $row['season_id'], 'old_sku_code' => $row['old_sku_code'],
                    'new_sku_code' => $row['new_sku_code'], 'created_at' => $now, 'updated_at' => $now];
            }
            // The entire final graph is validated under the same season locks as
            // manual saves. A bulk write avoids transient cycles and quadratic
            // per-row graph scans; existing IDs and created_at remain unchanged.
            foreach (array_chunk($writes, 250) as $chunk) {
                DB::table('sku_replacements')->upsert($chunk, ['season_id', 'old_sku_code'], ['new_sku_code', 'updated_at']);
            }

            return $plan['counts'];
        });
    }

    private function authorize(string $mode): void
    {
        Gate::authorize('viewAny', SkuReplacement::class);
        Gate::authorize('create', SkuReplacement::class);
        if (! array_key_exists($mode, self::modes())) {
            $this->fail('Válassz érvényes importálási módot.');
        }
        if ($mode === 'overwrite') {
            Gate::authorize('update', new SkuReplacement);
        }
    }

    private function read(mixed $file): array
    {
        if (is_array($file)) {
            $file = count($file) === 1 ? reset($file) : null;
        }
        if (! $file instanceof UploadedFile || ! $file->getRealPath() || ! is_file($file->getRealPath())) {
            $this->fail('Tölts fel egy Excel-fájlt.');
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            $this->fail('A fájl legfeljebb 10 MB lehet.');
        }
        $book = null;
        try {
            $type = IOFactory::identify($file->getRealPath());
            if (! in_array($type, ['Xlsx', 'Xls'], true)) {
                $this->fail('Csak XLSX vagy XLS formátumú Excel-fájl tölthető be.');
            }
            $reader = IOFactory::createReader($type);
            $info = $reader->listWorksheetInfo($file->getRealPath())[0] ?? null;
            if (! $info || $info['totalRows'] > self::MAX_ROWS + 1 || $info['totalColumns'] > 100) {
                $this->fail('Az első munkalap legfeljebb 10 000 adatsort és 100 oszlopot tartalmazhat.');
            }
            $reader->setLoadSheetsOnly([$info['worksheetName']]);
            $book = $reader->load($file->getRealPath());
            $sheet = $book->getSheet(0);
            $headers = [];
            foreach ($sheet->getRowIterator(1, 1)->current()->getCellIterator() as $cell) {
                $header = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(Str::ascii((string) $cell->getValue()))), '_');
                $field = match ($header) {
                    'season_code', 'szezon', 'szezon_kod' => 'season_code',
                    'old_sku_code', 'regi_cikkszam', 'regi_cikkszam_sku', 'regi_sku' => 'old_sku_code',
                    'new_sku_code', 'uj_cikkszam', 'uj_cikkszam_torolt', 'uj_cikkszam_vagy_torolt', 'uj_sku' => 'new_sku_code',
                    default => null,
                };
                if ($field === null) {
                    continue;
                }
                if (isset($headers[$field])) {
                    $this->fail('Többször szereplő oszlop: '.$field.'.');
                }
                $headers[$field] = $cell->getColumn();
            }
            foreach (['season_code', 'old_sku_code', 'new_sku_code'] as $field) {
                if (! isset($headers[$field])) {
                    $this->fail('Hiányzó oszlop: '.$field.'. A fejléc az első sorban legyen.');
                }
            }
            $rows = [];
            foreach ($sheet->getRowIterator(2, max(2, $sheet->getHighestDataRow())) as $sheetRow) {
                $row = ['row_number' => $sheetRow->getRowIndex(), 'errors' => []];
                foreach ($headers as $field => $column) {
                    $cell = $sheet->getCell($column.$row['row_number']);
                    $row[$field] = trim((string) $cell->getValue());
                    if ($cell->isFormula()) {
                        $row['errors'][] = $field.': képlet helyett szöveges kódot adj meg.';
                    } elseif ($cell->getDataType() === DataType::TYPE_NUMERIC && $field !== 'season_code') {
                        $row['errors'][] = $field.': a cikkszámot szövegként add meg, hogy a vezető nullák megmaradjanak.';
                    }
                }
                if ($row['season_code'] === '' && $row['old_sku_code'] === '' && $row['new_sku_code'] === '') {
                    continue;
                }
                $rows[] = $row;
            }
            if ($rows === []) {
                $this->fail('Az Excel első munkalapja nem tartalmaz betölthető adatsort.');
            }

            return ['rows' => $rows, 'file_hash' => hash_file('sha256', $file->getRealPath())];
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            $this->fail('Az Excel-fájl nem olvasható. Ellenőrizd a formátumát, majd töltsd fel újra.');
        } finally {
            $book?->disconnectWorksheets();
        }
    }

    private function plan(array $source, string $mode, bool $lock = false): array
    {
        $seasonQuery = Season::whereIn(DB::raw('LOWER(code)'), array_unique(array_map(
            fn ($row) => mb_strtolower($row['season_code'], 'UTF-8'), $source['rows'],
        )))->orderBy('id');
        if ($lock) {
            $seasonQuery->lockForUpdate();
        }
        $seasons = $seasonQuery->get()->keyBy(fn ($season) => mb_strtolower($season->code, 'UTF-8'));
        $existingQuery = SkuReplacement::whereIn('season_id', $seasons->pluck('id'))->orderBy('id');
        if ($lock) {
            $existingQuery->lockForUpdate();
        }
        $existing = $existingQuery->get();
        $codes = collect($source['rows'])->flatMap(fn ($row) => [$row['old_sku_code'], $row['new_sku_code']])
            ->merge($existing->pluck('new_sku_code'))->map(fn ($code) => mb_strtolower(trim($code), 'UTF-8'))->unique()->values();
        $skus = Sku::whereIn(DB::raw('LOWER(sku_code)'), $codes)->with('product')->get()->keyBy(fn ($sku) => mb_strtolower($sku->sku_code, 'UTF-8'));
        $key = fn ($seasonId, $code): string => $seasonId.':'.mb_strtolower($code, 'UTF-8');
        $byKey = $existing->keyBy(fn ($record) => $key($record->season_id, $record->old_sku_code));
        $graph = $existing->mapWithKeys(fn ($record) => [$key($record->season_id, $record->old_sku_code) => $record->new_sku_code])->all();
        $rows = [];
        $errors = [];
        $seen = [];
        $counts = ['create' => 0, 'overwrite' => 0, 'skip' => 0, 'unchanged' => 0];
        foreach ($source['rows'] as $input) {
            $rowErrors = $input['errors'];
            $season = $seasons->get(mb_strtolower($input['season_code'], 'UTF-8'));
            if (! $season) {
                $rowErrors[] = 'Ismeretlen vagy üres szezonkód: '.$input['season_code'];
            }
            $row = ['row_number' => $input['row_number'], 'season_id' => $season?->id, 'season_code' => $season?->code ?? $input['season_code'],
                'old_sku_code' => $input['old_sku_code'], 'new_sku_code' => $input['new_sku_code'], 'old_sku_name' => '', 'new_sku_name' => '', 'status' => 'invalid'];
            foreach (['old_sku_code', 'new_sku_code'] as $field) {
                if ($field === 'new_sku_code' && mb_strtoupper($row[$field], 'UTF-8') === SkuReplacement::DELETED) {
                    $row[$field] = SkuReplacement::DELETED;

                    continue;
                }
                $sku = $skus->get(mb_strtolower($row[$field], 'UTF-8'));
                if (! $sku || ! $season || (int) $sku->product?->season_id !== (int) $season->id || mb_strlen($row[$field]) > 255) {
                    $rowErrors[] = $field.': nem található SKU a megadott szezonban: '.$row[$field];
                } else {
                    $row[$field] = $sku->sku_code;
                    $row[str_replace('_code', '_name', $field)] = $sku->sku_name;
                }
            }
            $rowKey = $key($row['season_id'], $row['old_sku_code']);
            if (isset($seen[$rowKey])) {
                $rowErrors[] = 'Azonos szezon és régi cikkszám már szerepel a fájl '.$seen[$rowKey].'. sorában.';
            }
            $seen[$rowKey] = $row['row_number'];
            if ($rowErrors !== []) {
                foreach ($rowErrors as $message) {
                    $errors[] = $row['row_number'].'. sor: '.$message;
                }
            } else {
                $current = $byKey->get($rowKey);
                $row['status'] = ! $current ? 'create' : ($current->new_sku_code === $row['new_sku_code'] ? 'unchanged' : $mode);
                if ($row['status'] === 'overwrite') {
                    Gate::authorize('update', $current);
                }
                $counts[$row['status']]++;
                if (in_array($row['status'], ['create', 'overwrite'], true)) {
                    $graph[$rowKey] = $row['new_sku_code'];
                }
            }
            $rows[] = $row;
        }
        foreach ($rows as $row) {
            if (! in_array($row['status'], ['create', 'overwrite'], true)) {
                continue;
            }
            $code = $row['old_sku_code'];
            $visited = [];
            while ($code !== SkuReplacement::DELETED && isset($graph[$key($row['season_id'], $code)])) {
                $graphKey = $key($row['season_id'], $code);
                if (isset($visited[$graphKey])) {
                    $errors[] = $row['row_number'].'. sor: önmagára mutató vagy körkörös cikkcsere: '.$row['old_sku_code'];
                    break;
                }
                $visited[$graphKey] = true;
                $code = $graph[$graphKey];
                $target = $skus->get(mb_strtolower($code, 'UTF-8'));
                if ($code !== SkuReplacement::DELETED && (! $target || (int) $target->product?->season_id !== (int) $row['season_id'])) {
                    $errors[] = $row['row_number'].'. sor: hiányzó vagy más szezonhoz tartozó cikkszám a csereláncban: '.$code;
                    break;
                }
            }
        }
        $snapshot = $existing->map(fn ($record) => [$record->id, $record->season_id, $record->old_sku_code, $record->new_sku_code, $record->updated_at?->format('Y-m-d H:i:s.u')])->all();
        $token = $errors === [] ? hash_hmac('sha256', json_encode([auth()->id(), $mode, $source['file_hash'], $rows, $snapshot], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string) config('app.key')) : null;

        return ['rows' => $rows, 'errors' => $errors, 'counts' => $counts, 'token' => $token];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['data.file' => $message]);
    }
}

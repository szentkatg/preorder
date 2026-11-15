<?php

namespace App\Services\Imports;

use App\Models\Partner;
use App\Models\PartnerPool;
use App\Models\PartnerPoolSyncState;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PartnerPoolSalesRepresentativeUpdater
{
    public const MODE_EMPTY = 'empty';

    public const MODE_ALL = 'all';

    public static function modes(): array
    {
        return [
            self::MODE_EMPTY => 'Csak ahol nincs kitöltve a területi képviselő',
            self::MODE_ALL => 'Minden kijelölt partner — a meglévő képviselő felülírásával',
        ];
    }

    public static function selectionToken(array $ids, string $batch): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        return hash_hmac('sha256', 'pool-sales-reps:'.auth()->id().':'.$batch.':'.implode(',', $ids), (string) config('app.key'));
    }

    public function update(array $rowIds, string $expectedBatch, string $mode, ?string $selectionToken = null): array
    {
        Gate::authorize('View:ErpPartnerPool');
        Gate::authorize('Import:PartnerPool');
        Gate::authorize('update', new Partner);
        Validator::make(['ids' => $rowIds, 'batch' => $expectedBatch, 'mode' => $mode], [
            'ids' => ['required', 'array', 'min:1'], 'ids.*' => ['required', 'integer', 'min:1'],
            'batch' => ['required', 'uuid'], 'mode' => ['required', Rule::in(array_keys(self::modes()))],
        ])->validate();
        $ids = array_values(array_unique(array_map('intval', $rowIds)));
        if ($selectionToken !== null && ! hash_equals(self::selectionToken($ids, $expectedBatch), $selectionToken)) {
            throw new DomainException('A kijelölés az ablak megnyitása óta megváltozott. Nyisd meg újra a műveletet; semmit sem módosítottunk.');
        }

        return DB::transaction(function () use ($ids, $expectedBatch, $mode): array {
            // Same lock order as the pool publisher/importer. Never mix snapshots.
            $state = PartnerPoolSyncState::lockForUpdate()->findOrFail(1);
            if ($state->active_batch_id !== $expectedBatch) {
                throw new DomainException('A partnerpool időközben frissült. Frissítsd a listát és jelölj ki újra; semmit sem módosítottunk.');
            }
            $selected = PartnerPool::where('batch_id', $expectedBatch)->whereKey($ids)->get();
            if ($selected->count() !== count($ids)) {
                throw new DomainException('Egy kijelölt poolsor már nem található az aktuális pillanatképben. Frissítsd a listát; semmit sem módosítottunk.');
            }
            $key = static fn (?string $code): string => Str::lower(trim((string) $code));
            $codes = $selected->map(fn (PartnerPool $row): string => $key($row->erp_partner_code))->uniqueStrict()->values()->all();
            Validator::make(['codes' => $codes], ['codes.*' => ['required', 'string', 'max:50']])->validate();
            // Check ALL current addresses for these partners, not just the selected
            // address: the representative is a partner-level field.
            $sources = PartnerPool::where('batch_id', $expectedBatch)
                ->whereIn(DB::raw('LOWER(TRIM(erp_partner_code))'), $codes)->orderBy('id')->get()
                // MySQL collations may equate more than case differences. Never
                // broaden the selected partner set (e.g. accented ERP codes).
                ->filter(fn (PartnerPool $row): bool => in_array($key($row->erp_partner_code), $codes, true))
                ->groupBy(fn (PartnerPool $row): string => $key($row->erp_partner_code));
            if ($sources->count() !== count($codes)) {
                throw new DomainException('Egy kijelölt partner ERP-kódja nem párosítható egyértelműen. Ellenőrizd a forráskódokat; semmit sem módosítottunk.');
            }
            $partners = Partner::whereIn(DB::raw('LOWER(TRIM(erp_partner_code))'), $codes)
                ->orderBy('id')->lockForUpdate()->get()->groupBy(fn (Partner $partner): string => $key($partner->erp_partner_code));

            $result = ['selected_rows' => count($ids), 'selected_partners' => count($codes),
                'updated' => 0, 'unchanged' => 0, 'already_filled' => 0, 'missing_partner' => 0, 'missing_representative' => 0];
            foreach ($sources as $code => $rows) {
                $representatives = $rows->map(fn (PartnerPool $row): string => trim((string) $row->sales_rep_erp_partner_code))
                    ->uniqueStrict(fn (string $value): string => $key($value))->values();
                if ($representatives->count() !== 1) {
                    throw new DomainException($rows->first()->erp_partner_code.': a pool címsorai eltérő területi képviselőt tartalmaznak. Semmit sem módosítottunk.');
                }
                $matches = $partners->get($code, collect());
                if ($matches->count() > 1) {
                    throw new DomainException($rows->first()->erp_partner_code.': az ERP-kódhoz több partnertörzsrekord tartozik. Semmit sem módosítottunk.');
                }
                $partner = $matches->first();
                if (! $partner) {
                    $result['missing_partner']++;

                    continue;
                }
                Gate::authorize('update', $partner);
                if ($mode === self::MODE_EMPTY && filled($partner->sales_rep_erp_partner_code)) {
                    $result['already_filled']++;

                    continue;
                }
                $representative = $representatives->first();
                if ($representative === '') {
                    $result['missing_representative']++;

                    continue;
                }
                Validator::make(['representative' => $representative], ['representative' => ['string', 'max:50']])->validate();
                if ($partner->sales_rep_erp_partner_code === $representative) {
                    $result['unchanged']++;

                    continue;
                }
                $partner->update(['sales_rep_erp_partner_code' => $representative]);
                $result['updated']++;
            }

            return $result;
        }, 3);
    }
}

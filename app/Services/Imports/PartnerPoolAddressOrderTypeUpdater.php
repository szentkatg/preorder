<?php

namespace App\Services\Imports;

use App\Models\OrderType;
use App\Models\PartnerAddress;
use App\Models\PartnerPool;
use App\Models\PartnerPoolSyncState;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PartnerPoolAddressOrderTypeUpdater
{
    public static function modes(): array
    {
        return ['all' => 'Minden kijelölt cím – a meglévő típusok cseréjével',
            'empty' => 'Csak azok a kijelölt címek, ahol nincs engedélyezett típus'];
    }

    public static function selectionToken(array $ids, string $batch, string $selectionSource = 'addresses'): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        return hash_hmac('sha256', 'address-order-types:'.$selectionSource.':'.auth()->id().':'.$batch.':'.implode(',', $ids), (string) config('app.key'));
    }

    public function update(array $addressIds, string $expectedBatch, string $mode, ?string $token = null, string $selectionSource = 'addresses'): array
    {
        Gate::authorize('View:ErpPartnerPool');
        Gate::authorize('Import:PartnerPool');
        Gate::authorize('viewAny', PartnerAddress::class);
        Gate::authorize('update', new PartnerAddress);
        Validator::make(['ids' => $addressIds, 'batch' => $expectedBatch, 'mode' => $mode, 'source' => $selectionSource], [
            'ids' => ['required', 'array', 'min:1'], 'ids.*' => ['required', 'integer', 'min:1'],
            'batch' => ['required', 'uuid'], 'mode' => ['required', Rule::in(array_keys(self::modes()))],
            'source' => ['required', Rule::in(['addresses', 'pool'])],
        ])->validate();
        $ids = array_values(array_unique(array_map('intval', $addressIds)));
        if ($token !== null && ! hash_equals(self::selectionToken($ids, $expectedBatch, $selectionSource), $token)) {
            throw new DomainException('A kijelölés megváltozott. Nyisd meg újra a műveletet; semmit sem módosítottunk.');
        }

        return DB::transaction(function () use ($ids, $expectedBatch, $mode, $selectionSource): array {
            $state = PartnerPoolSyncState::lockForUpdate()->findOrFail(1);
            if ($state->active_batch_id !== $expectedBatch) {
                throw new DomainException('A partnerpool időközben frissült. Frissítsd a listát és jelölj ki újra; semmit sem módosítottunk.');
            }
            $selectedCount = count($ids);
            $missingAddress = 0;
            if ($selectionSource === 'pool') {
                [$ids, $missingAddress] = $this->resolvePoolAddressIds($ids, $expectedBatch);
            }
            $addresses = PartnerAddress::with(['partner', 'orderTypes'])->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($addresses->count() !== count($ids)) {
                throw new DomainException('Egy kijelölt cím már nem található. Semmit sem módosítottunk.');
            }
            $key = static fn ($value): string => Str::lower(trim((string) $value));
            $partnerCodes = $addresses->map(fn ($address) => $key($address->partner?->erp_partner_code))
                ->filter(fn ($code) => $code !== '')->unique()->values();
            $pairKey = static fn ($code, $addrid): string => json_encode([$key($code), $key($addrid)], JSON_THROW_ON_ERROR);
            $sources = PartnerPool::where('batch_id', $expectedBatch)
                ->whereIn(DB::raw('LOWER(TRIM(erp_partner_code))'), $partnerCodes)->get()
                ->groupBy(fn ($row) => $pairKey($row->erp_partner_code, $row->addrid));
            $types = OrderType::where('active', true)->get()->groupBy(fn ($type) => $key($type->code));
            $result = ['selected' => $selectedCount, 'updated' => 0, 'unchanged' => 0, 'missing_address' => $missingAddress,
                'already_filled' => 0, 'missing_source' => 0, 'empty_source' => 0, 'inactive_source' => 0];
            foreach ($addresses as $address) {
                Gate::authorize('update', $address);
                if ($mode === 'empty' && $address->orderTypes->isNotEmpty()) {
                    $result['already_filled']++;

                    continue;
                }
                $code = $key($address->partner?->erp_partner_code);
                $addrid = $key($address->addrid);
                // Exact normalized pair keys also guard against broader MySQL
                // collation matches. Keep leading zeroes in ERP/address codes.
                $matches = $code !== '' && $addrid !== ''
                    ? $sources->get($pairKey($code, $addrid), collect()) : collect();
                if ($matches->count() > 1) {
                    throw new DomainException($address->name.': több poolsor illeszkedik a partnerkód és címkód párjára. Semmit sem módosítottunk.');
                }
                $row = $matches->first();
                if (! $row) {
                    $result['missing_source']++;

                    continue;
                }
                if (! $row->permits('customer') || ! $row->partner_active || ! $row->address_active) {
                    $result['inactive_source']++;

                    continue;
                }
                $codes = $row->order_type_codes;
                if ($codes === null || $codes === []) {
                    $result['empty_source']++;

                    continue;
                }
                if (! is_array($codes) || ! array_is_list($codes)) {
                    throw new DomainException($address->name.': hibás rendeléstípus-tömb a poolban. Semmit sem módosítottunk.');
                }
                $newIds = [];
                foreach ($codes as $typeCode) {
                    $matchingTypes = is_string($typeCode) ? $types->get($key($typeCode), collect()) : collect();
                    if ($matchingTypes->count() !== 1) {
                        throw new DomainException($address->name.': ismeretlen, inaktív vagy nem egyértelmű rendeléstípus a poolban. Semmit sem módosítottunk.');
                    }
                    $newIds[] = (int) $matchingTypes->first()->id;
                }
                $newIds = array_values(array_unique($newIds));
                sort($newIds);
                $oldIds = $address->orderTypes->modelKeys();
                sort($oldIds);
                if ($oldIds === $newIds) {
                    $result['unchanged']++;

                    continue;
                }
                $address->orderTypes()->sync($newIds);
                $result['updated']++;
            }

            return $result;
        }, 3);
    }

    private function resolvePoolAddressIds(array $ids, string $batch): array
    {
        $rows = PartnerPool::where('batch_id', $batch)->whereKey($ids)->get();
        if ($rows->count() !== count($ids)) {
            throw new DomainException('Egy kijelölt poolsor már nem található az aktuális pillanatképben. Semmit sem módosítottunk.');
        }
        $key = static fn ($value): string => Str::lower(trim((string) $value));
        $pairKey = static fn ($code, $addrid): string => json_encode([$key($code), $key($addrid)], JSON_THROW_ON_ERROR);
        $codes = $rows->pluck('erp_partner_code')->map($key)->filter(fn ($code) => $code !== '')->unique()->values();
        $addresses = PartnerAddress::with('partner')->whereHas('partner', fn ($query) => $query->whereIn(DB::raw('LOWER(TRIM(erp_partner_code))'), $codes))->get()
            ->groupBy(fn ($address) => $pairKey($address->partner?->erp_partner_code, $address->addrid));
        $addressIds = [];
        $missing = 0;
        foreach ($rows as $row) {
            $matches = $key($row->erp_partner_code) !== '' && $key($row->addrid) !== ''
                ? $addresses->get($pairKey($row->erp_partner_code, $row->addrid), collect()) : collect();
            if ($matches->count() > 1) {
                throw new DomainException('A kijelölt poolsorhoz több partnercím tartozik. Semmit sem módosítottunk.');
            }
            if ($matches->isEmpty()) {
                $missing++;

                continue;
            }
            $addressIds[] = (int) $matches->first()->id;
        }

        return [array_values(array_unique($addressIds)), $missing];
    }
}

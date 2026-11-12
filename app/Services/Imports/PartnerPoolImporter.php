<?php

namespace App\Services\Imports;

use App\Models\Brand;
use App\Models\Currency;
use App\Models\Language;
use App\Models\OrderSheetType;
use App\Models\Partner;
use App\Models\PartnerAddress;
use App\Models\PartnerPool;
use App\Models\PartnerPoolSyncState;
use App\Models\PriceList;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PartnerPoolImporter
{
    public function defaults(PartnerPool $row, string $type): array
    {
        $missing = [];
        $ids = function (string $model, array $codes, string $label) use (&$missing): array {
            $codes = collect($codes)->filter(fn ($code): bool => is_string($code) && trim($code) !== '')
                ->map(fn (string $code): string => trim($code))->unique(fn (string $code): string => Str::lower($code));
            if ($codes->isEmpty()) {
                return [];
            }
            $matches = $model::where('active', true)
                ->whereIn(DB::raw('LOWER(code)'), $codes->map(fn (string $code): string => Str::lower($code))->values()->all())
                ->get(['id', 'code'])->groupBy(fn ($record): string => Str::lower($record->code));
            $result = [];
            foreach ($codes as $code) {
                $options = $matches->get(Str::lower($code));
                if ($options?->count() === 1) {
                    $result[] = $options->first()->id;
                } else {
                    $missing[] = $label.': '.$code;
                }
            }

            return $result;
        };
        $priceLists = $ids(PriceList::class, filled($row->price_list_code) ? [$row->price_list_code] : [], 'Árlista');
        $currencies = $ids(Currency::class, filled($row->currency_code) ? [$row->currency_code] : [], 'Pénznem');
        $languages = $ids(Language::class, filled($row->language_code) ? [$row->language_code] : [], 'Nyelv');
        $brands = $ids(Brand::class, $row->brand_codes ?? [], 'Márka');
        $sheets = $ids(OrderSheetType::class, $row->order_sheet_type_codes ?? [], 'Rendelőlaptípus');

        return [
            'target_type' => $type,
            'country' => preg_match('/^[A-Za-z]{2}$/D', (string) $row->country) ? strtoupper($row->country) : null,
            'price_list_id' => $priceLists[0] ?? null,
            'currency_id' => $currencies[0] ?? null,
            'language_id' => $languages[0] ?? null,
            'brand_ids' => $brands, 'order_sheet_type_ids' => $sheets, 'allow_assortment_ordering' => false,
            'suggestion_notice' => $missing === [] ? null : 'Nem párosítható (hiányzó, inaktív vagy nem egyértelmű) ERP-javaslatok: '
                .implode('; ', $missing).'. Válaszd ki a megfelelő törzsadatokat kézzel.',
        ];
    }

    public function import(int $rowId, string $expectedBatch, array $input): array
    {
        Gate::authorize('View:ErpPartnerPool');
        Gate::authorize('Import:PartnerPool');

        return DB::transaction(function () use ($rowId, $expectedBatch, $input): array {
            // The Python publisher locks this same singleton: a refresh cannot
            // replace the selected snapshot halfway through an import.
            $state = PartnerPoolSyncState::lockForUpdate()->findOrFail(1);
            if ($state->active_batch_id !== $expectedBatch) {
                throw ValidationException::withMessages(['target_type' => 'A partnerpool időközben frissült. Zárd be az ablakot, frissítsd a listát és válassz újra.']);
            }
            $row = PartnerPool::where('batch_id', $expectedBatch)->findOrFail($rowId);
            $type = Validator::make($input, ['target_type' => ['required', Rule::in(['customer', 'supplier', 'both'])]])->validate()['target_type'];
            foreach ($type === 'both' ? ['customer', 'supplier'] : [$type] as $destination) {
                if (! $row->permits($destination)) {
                    throw ValidationException::withMessages(['target_type' => 'Az ERP-beli partnertípus ezt az átvételt nem engedélyezi.']);
                }
            }
            if (! $row->partner_active || ($row->addrid !== null && ! $row->address_active)) {
                throw ValidationException::withMessages(['target_type' => 'Inaktív ERP-partner vagy cím nem vehető át.']);
            }
            $customer = in_array($type, ['customer', 'both'], true);
            $supplier = in_array($type, ['supplier', 'both'], true);
            if ($customer) {
                Gate::authorize('create', Partner::class);
                Gate::authorize('create', PartnerAddress::class);
            }
            if ($supplier) {
                Gate::authorize('create', Supplier::class);
            }
            $rules = ['country' => ['required', 'regex:/^[A-Za-z]{2}$/D']];
            if ($customer) {
                $rules += [
                    'price_list_id' => ['required', 'integer', Rule::exists('price_lists', 'id')->where('active', true)],
                    'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')->where('active', true)],
                    'language_id' => ['required', 'integer', Rule::exists('languages', 'id')->where('active', true)],
                    'brand_ids' => ['required', 'array', 'min:1'], 'brand_ids.*' => ['integer', 'distinct', Rule::exists('brands', 'id')->where('active', true)],
                    'order_sheet_type_ids' => ['required', 'array', 'min:1'], 'order_sheet_type_ids.*' => ['integer', 'distinct', Rule::exists('order_sheet_types', 'id')->where('active', true)],
                    'allow_assortment_ordering' => ['required', 'boolean'],
                ];
            }
            $data = Validator::make($input, $rules)->validate();
            $sourceRules = [
                'erp_partner_code' => ['required', 'string', 'max:50'], 'partner_name' => ['required', 'string', 'max:255'],
                'short_name' => ['nullable', 'string', 'max:255'],
                'addrid' => [$customer ? 'required' : 'nullable', 'string', 'max:100'],
            ];
            if ($customer) {
                $sourceRules += [
                    'partner_email' => ['nullable', 'string', 'max:255'], 'partner_phone' => ['nullable', 'string', 'max:255'],
                    'sales_rep_erp_partner_code' => ['nullable', 'string', 'max:50'],
                    'address_name' => ['required', 'string', 'max:255'],
                    'street' => ['nullable', 'string', 'max:255'], 'address_email' => ['nullable', 'string', 'max:255'],
                ];
            }
            Validator::make($row->getAttributes(), $sourceRules)->validate();
            if ($customer && (int) PriceList::findOrFail($data['price_list_id'])->currency_id !== (int) $data['currency_id']) {
                throw ValidationException::withMessages(['currency_id' => 'Az árlista és a cím pénzneme egyezzen meg.']);
            }

            $created = [];
            $partner = $address = $vendor = null;
            if ($customer) {
                $partner = Partner::where('erp_partner_code', $row->erp_partner_code)->lockForUpdate()->first();
                if (! $partner) {
                    $partner = Partner::create(['erp_partner_code' => $row->erp_partner_code, 'name' => $row->partner_name,
                        'email' => $row->partner_email, 'phone' => $row->partner_phone,
                        'sales_rep_erp_partner_code' => $row->sales_rep_erp_partner_code, 'active' => true]);
                    $created[] = 'partner';
                }
                // Do not reuse the legacy importer's addrid-only lookup: a
                // matching address owned by a different partner is never moved.
                $address = PartnerAddress::where('partner_id', $partner->id)->where('addrid', $row->addrid)->lockForUpdate()->first();
                if (! $address) {
                    if (PartnerAddress::where('addrid', $row->addrid)->exists()) {
                        throw ValidationException::withMessages(['target_type' => 'Ez a címkód már másik partnerhez tartozik. Az átvétel nem helyezhet át meglévő címet.']);
                    }
                    $address = PartnerAddress::create(['partner_id' => $partner->id, 'addrid' => $row->addrid,
                        'name' => $row->address_name, 'country' => strtoupper($data['country']), 'zip' => $row->zip,
                        'city' => $row->city, 'street' => $row->street, 'contact_name' => $row->contact_name,
                        'email' => $row->address_email, 'phone' => $row->address_phone,
                        'price_list_id' => $data['price_list_id'], 'currency_id' => $data['currency_id'],
                        'language_id' => $data['language_id'], 'allow_assortment_ordering' => $data['allow_assortment_ordering'], 'active' => true]);
                    $address->brands()->sync($data['brand_ids']);
                    $address->orderSheetTypes()->sync($data['order_sheet_type_ids']);
                    $created[] = 'address';
                }
            }
            if ($supplier) {
                $vendor = Supplier::where('erp_partner_code', $row->erp_partner_code)->lockForUpdate()->first();
                if (! $vendor) {
                    $vendor = Supplier::create(['erp_partner_code' => $row->erp_partner_code, 'name' => $row->partner_name,
                        'short_name' => $row->short_name, 'addrid' => $row->addrid, 'country_code' => strtoupper($data['country']), 'active' => true]);
                    $created[] = 'supplier';
                }
            }
            DB::table('partnerpool_import_logs')->insert(['user_id' => auth()->id(), 'source_batch_id' => $expectedBatch,
                'source_row_key' => $row->row_key, 'erp_partner_code' => $row->erp_partner_code, 'addrid' => $row->addrid,
                'target_type' => $type, 'partner_id' => $partner?->id, 'partner_address_id' => $address?->id,
                'supplier_id' => $vendor?->getKey(), 'created_records' => json_encode($created, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return ['created' => $created, 'partner_id' => $partner?->id, 'address_id' => $address?->id, 'supplier_id' => $vendor?->getKey()];
        }, 3);
    }
}

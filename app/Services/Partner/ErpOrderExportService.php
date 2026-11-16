<?php

namespace App\Services\Partner;

use App\Exports\ErpOrderExport;
use App\Models\PartnerUser;
use App\Models\Sku;
use App\Models\SkuReplacement;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ErpOrderExportService
{
    public function selectedOrders(array $ids): Collection
    {
        $user = PartnerUser::find(auth('partner')->id());
        abort_unless($user?->active && $user->role === PartnerUser::ROLE_SALES_REP, 403);
        Validator::make(['selectedSummaryOrderIds' => $ids], [
            'selectedSummaryOrderIds' => ['required', 'array', 'min:1'],
            'selectedSummaryOrderIds.*' => ['required', 'integer', 'min:1'],
        ], ['selectedSummaryOrderIds.required' => __('partner.select_at_least_one_order_for_summary')])->validate();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $orders = $user->accessibleOrdersQuery()->whereKey($ids)
            ->where('season_id', PartnerSeasonContext::id())
            ->with(['season', 'partner', 'partnerAddress', 'orderType'])->get();
        // Never silently export only the accessible subset of a tampered/stale selection.
        abort_unless($orders->count() === count($ids), 403);

        return $orders->sort(function ($a, $b): int {
            return strcmp((string) $a->partner?->erp_partner_code, (string) $b->partner?->erp_partner_code)
                ?: strcmp((string) $a->partnerAddress?->addrid, (string) $b->partnerAddress?->addrid)
                ?: strcmp((string) $a->reference_number, (string) $b->reference_number)
                ?: $a->id <=> $b->id;
        })->values();
    }

    public function build(array $ids, array $input): ErpOrderExport
    {
        $orders = $this->selectedOrders($ids);
        $data = Validator::make($input, [
            'erpExportNote' => ['nullable', 'string', 'max:250'],
            'erpExportDeliveryDate' => ['required', 'string'],
        ], [
            'erpExportNote.max' => __('partner.erp_export_note_max'),
            'erpExportDeliveryDate.required' => __('partner.erp_export_date_required'),
        ])->validate();
        $delivery = $this->deliveryDate($data['erpExportDeliveryDate']);
        $date = CarbonImmutable::now('Europe/Budapest')->startOfDay();
        $orders->load(['items.sku.product', 'items.sku.color', 'items.sku.size']);
        $seasonIds = $orders->pluck('season_id')->unique();
        $rules = SkuReplacement::whereIn('season_id', $seasonIds)->get();
        $maps = $rules->groupBy('season_id')->map(function ($rows): array {
            if ($rows->pluck('old_sku_code')->uniqueStrict()->count() !== $rows->count()) {
                $this->fail('duplicate');
            }

            return $rows->pluck('new_sku_code', 'old_sku_code')->all();
        });
        $targetSkus = Sku::whereIn('sku_code', $rules->pluck('new_sku_code')->reject(fn ($code) => $code === SkuReplacement::DELETED))
            ->with(['product', 'color', 'size'])->get()->keyBy('sku_code');

        $rows = [];
        $audit = [];
        foreach ($orders as $order) {
            if (blank($order->partner?->erp_partner_code) || blank($order->partnerAddress?->addrid)
                || blank($order->orderType?->code) || blank($order->reference_number)
                || (int) $order->partnerAddress->partner_id !== (int) $order->partner_id) {
                $this->fail('order', (string) $order->id);
            }
            $counts = ['unchanged' => 0, 'replaced' => 0, 'deleted' => 0];
            $original = 0;
            $destinations = [];
            $map = $maps->get($order->season_id, []);
            foreach ($order->items as $item) {
                $quantity = (int) $item->quantity;
                if ($quantity < 0 || (float) $item->quantity !== (float) $quantity || ! $item->sku) {
                    $this->fail('sku', (string) $item->sku_id);
                }
                if ($quantity === 0) {
                    continue;
                }
                $sku = $item->sku;
                // Gyűjtők: the SKU and the ordered pack count are preserved, never expanded.
                $original += $quantity;
                $this->checkSku($sku, (int) $order->season_id);
                $code = $sku->sku_code;
                $seen = [];
                while ($code !== SkuReplacement::DELETED && array_key_exists($code, $map)) {
                    if (isset($seen[$code])) {
                        $this->fail('cycle', $code);
                    }
                    $seen[$code] = true;
                    $code = $map[$code];
                    if ($code !== SkuReplacement::DELETED) {
                        $this->checkSku($targetSkus->get($code), (int) $order->season_id);
                    }
                }
                if ($code === SkuReplacement::DELETED) {
                    $counts['deleted'] += $quantity;

                    continue;
                }
                $target = $code === $sku->sku_code ? $sku : $targetSkus->get($code);
                $this->checkSku($target, (int) $order->season_id);
                $counts[$code === $sku->sku_code ? 'unchanged' : 'replaced'] += $quantity;
                $destinations[$target->id] ??= ['sku' => $target, 'quantity' => 0];
                $destinations[$target->id]['quantity'] += $quantity;
            }
            $sorted = collect($destinations)->sort(function ($a, $b): int {
                return strcmp((string) $a['sku']->product->model_code, (string) $b['sku']->product->model_code)
                    ?: strcmp((string) $a['sku']->color->code, (string) $b['sku']->color->code)
                    ?: ((int) ($a['sku']->size?->sort_order ?? PHP_INT_MAX) <=> (int) ($b['sku']->size?->sort_order ?? PHP_INT_MAX))
                    ?: strcmp($a['sku']->sku_code, $b['sku']->sku_code);
            });
            if (array_sum($counts) !== $original || $sorted->sum('quantity') !== $counts['unchanged'] + $counts['replaced']) {
                $this->fail('quantity', (string) $order->id);
            }
            foreach ($sorted as $destination) {
                $rows[] = [
                    'HUPS', $order->orderType->code, $date, $order->partner->erp_partner_code,
                    $order->partnerAddress->addrid, $order->partnerAddress->name,
                    null, null, null, null, $order->reference_number, $data['erpExportNote'] ?? '',
                    $destination['sku']->sku_code, $destination['sku']->sku_name,
                    null, null, null, null, $destination['quantity'], null, null, null, $delivery,
                ];
            }
            $audit[] = [$order->id, $order->season->code, $order->partner->erp_partner_code,
                $order->partner->name, $order->partnerAddress->addrid, $order->partnerAddress->name,
                $order->orderType->code, $order->reference_number,
                $counts['unchanged'], $counts['replaced'], $counts['deleted'], null, $original, null];
        }

        return new ErpOrderExport($rows, $audit);
    }

    protected function checkSku(?Sku $sku, int $seasonId): void
    {
        if (! $sku || ! $sku->product || ! $sku->color || blank($sku->sku_code)
            || (int) $sku->product->season_id !== $seasonId || (int) $sku->color->product_id !== (int) $sku->product_id) {
            $this->fail('sku', $sku?->sku_code ?? '');
        }
    }

    protected function deliveryDate(string $input): CarbonImmutable
    {
        $normalized = str_replace('.', '-', rtrim(trim($input), '.'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $normalized)) {
            throw ValidationException::withMessages(['erpExportDeliveryDate' => __('partner.erp_export_date_invalid')]);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $normalized);
        if (! $date || $date->format('Y-m-d') !== $normalized) {
            throw ValidationException::withMessages(['erpExportDeliveryDate' => __('partner.erp_export_date_invalid')]);
        }

        return CarbonImmutable::instance($date);
    }

    protected function fail(string $reason, string $code = ''): never
    {
        throw ValidationException::withMessages(['erpExport' => __('partner.erp_export_error_'.$reason, ['code' => $code])]);
    }
}

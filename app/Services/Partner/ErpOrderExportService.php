<?php

namespace App\Services\Partner;

use App\Exports\ErpOrderExport;
use App\Models\PartnerUser;
use App\Models\Sku;
use App\Models\SkuReplacement;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

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
        // Compatibility for in-memory callers. The portal uses download(), never this path.
        $rows = [];
        $audit = [];
        foreach ($this->batches($ids, $input) as $batch) {
            foreach ($batch['rows'] as $row) {
                $rows[] = $row;
            }
            $audit[] = $batch['audit'];
        }

        return new ErpOrderExport($rows, $audit);
    }

    public function download(array $ids, array $input): BinaryFileResponse
    {
        // tempnam creates a private file; no public URL or reusable export is left behind.
        $path = tempnam(sys_get_temp_dir(), 'erp-orders-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create ERP export temporary file.');
        }
        try {
            app(ErpOrderXlsxWriter::class)->write($this->batches($ids, $input), $path);
            clearstatcache(true, $path);

            return response()->download($path, 'ERP-rendelesek-'.now('Europe/Budapest')->format('Ymd-His').'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $exception;
        }
    }

    /** @return Generator<array{rows: Generator, audit: array}> */
    public function batches(array $ids, array $input): Generator
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
        $seasonIds = $orders->pluck('season_id')->unique();
        $rules = SkuReplacement::whereIn('season_id', $seasonIds)->get();
        $maps = $rules->groupBy('season_id')->map(function ($rows): array {
            if ($rows->pluck('old_sku_code')->uniqueStrict()->count() !== $rows->count()) {
                $this->fail('duplicate');
            }

            return $rows->pluck('new_sku_code', 'old_sku_code')->all();
        });
        $targetSkus = Sku::whereIn('sku_code', $rules->pluck('new_sku_code')->reject(fn ($code) => $code === SkuReplacement::DELETED))
            ->with($this->skuRelations())->get()->keyBy('sku_code');

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
            // Bound hydrated models to one chunk, not all selected orders (or one huge order).
            foreach ($order->items()->select(['id', 'sku_id', 'quantity'])
                ->with(['sku' => fn ($query) => $query->with($this->skuRelations())])
                ->lazyById(500) as $item) {
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
                // Keep only the compact sort/output data, never the hydrated SKU graph.
                $destinations[$target->id] ??= [
                    'model_code' => (string) $target->product->model_code,
                    'color_code' => (string) $target->color->code,
                    'size_order' => (int) ($target->size?->sort_order ?? PHP_INT_MAX),
                    'sku_code' => $target->sku_code, 'sku_name' => $target->sku_name, 'quantity' => 0,
                ];
                $destinations[$target->id]['quantity'] += $quantity;
            }
            $sorted = collect($destinations)->sort(function ($a, $b): int {
                return strcmp($a['model_code'], $b['model_code'])
                    ?: strcmp($a['color_code'], $b['color_code'])
                    ?: $a['size_order'] <=> $b['size_order']
                    ?: strcmp($a['sku_code'], $b['sku_code']);
            });
            if (array_sum($counts) !== $original || $sorted->sum('quantity') !== $counts['unchanged'] + $counts['replaced']) {
                $this->fail('quantity', (string) $order->id);
            }
            $rows = (function () use ($sorted, $order, $date, $data, $delivery): Generator {
                foreach ($sorted as $destination) {
                    yield [
                        'HUPS', $order->orderType->code, $date, $order->partner->erp_partner_code,
                        $order->partnerAddress->addrid, $order->partnerAddress->name,
                        null, null, null, null, $order->reference_number, $data['erpExportNote'] ?? '',
                        $destination['sku_code'], $destination['sku_name'],
                        null, null, null, null, $destination['quantity'], null, null, null, $delivery,
                    ];
                }
            })();
            $audit = [$order->id, $order->season->code, $order->partner->erp_partner_code,
                $order->partner->name, $order->partnerAddress->addrid, $order->partnerAddress->name,
                $order->orderType->code, $order->reference_number,
                $counts['unchanged'], $counts['replaced'], $counts['deleted'], null, $original, null];
            yield ['rows' => $rows, 'audit' => $audit];
        }
    }

    protected function skuRelations(): array
    {
        return ['product:id,season_id,model_code', 'color:id,product_id,code', 'size:id,sort_order'];
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

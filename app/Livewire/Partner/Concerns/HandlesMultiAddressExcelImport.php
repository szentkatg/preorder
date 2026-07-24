<?php

namespace App\Livewire\Partner\Concerns;

use App\Imports\PartnerOrderMatrixImport;
use App\Models\Order;
use App\Models\OrderImportLog;
use App\Models\PartnerAddress;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;


trait HandlesMultiAddressExcelImport
{
    public array $importResults = [];

    public function importExcelFiles(): void
    {
        if ($this->isSubmittedOrder($this->order)) {
            $message = __('partner.import_order_already_submitted');

            $this->importResults = [
                'success' => false,
                'message' => $message,
                'files' => [],
            ];

            $notification = Notification::make()
                ->title($errorCount === 0 ? __('partner.import_finished') : __('partner.import_finished_with_errors'))
                ->body($message);
            
            if ($errorCount === 0) {
                $notification->success();
            } else {
                $notification
                    ->warning()
                    ->persistent();
            }
            
            $notification->send();

            return;
        }

        $this->validate([
            'excelFiles' => ['required', 'array', 'min:1'],
            'excelFiles.*' => ['file', 'mimes:xlsx,xls'],
        ]);

        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach (Arr::wrap($this->excelFiles) as $file) {
            $path = $file->getRealPath();
            $originalName = method_exists($file, 'getClientOriginalName')
                ? $file->getClientOriginalName()
                : 'excel';

            try {
                $header = $this->readExcelHeader($path);
                
                if ($header['address_code'] === '') {
                    throw new \RuntimeException(__('partner.import_missing_address_code'));
                }
                
                $targetOrder = $this->resolveImportOrder($header);
                
                $addressCode = $header['address_code'];

                if (! $targetOrder) {
                    throw new \RuntimeException(__('partner.import_order_not_found_for_address', ['address' => $addressCode]));
                }

                if ($this->isSubmittedOrder($targetOrder)) {
                    throw new \RuntimeException(__('partner.import_target_order_already_submitted', ['address' => $addressCode]));
                }

                $import = new PartnerOrderMatrixImport($targetOrder);

                Excel::import($import, $path);

                $stats = $import->stats();

                if (($stats['rows_with_meta'] ?? 0) < 1) {
                    throw new \RuntimeException(__('partner.import_no_import_map_found'));
                }

                $this->writeImportLog($targetOrder, $originalName, $addressCode, 'success', $stats, null);

                $results[] = [
                    'filename' => $originalName,
                    'address_code' => $addressCode,
                    'success' => true,
                    'message' => __('partner.import_file_successful'),
                    'stats' => $stats,
                ];

                $successCount++;
            } catch (Throwable $exception) {
                $errorCount++;

                $stats = [
                    'created' => 0,
                    'updated' => 0,
                    'deleted' => 0,
                    'unchanged' => 0,
                    'invalid' => 0,
                    'changed' => 0,
                    'mapped_cells' => 0,
                    'rows_with_meta' => 0,
                    'warnings' => [],
                ];

                $this->writeImportLog(null, $originalName, null, 'failed', $stats, $exception->getMessage());

                $results[] = [
                    'filename' => $originalName,
                    'address_code' => null,
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'stats' => $stats,
                ];
            }
        }

        $this->reset('excelFiles');

        $this->refreshAfterExcelImport();

        $message = __('partner.import_finished_summary', [
            'success' => $successCount,
            'failed' => $errorCount,
        ]);

        $this->importResults = [
            'success' => $errorCount === 0,
            'message' => $message,
            'files' => $results,
        ];

        $notification = Notification::make()
            ->title($errorCount === 0 ? __('partner.import_finished') : __('partner.import_finished_with_errors'))
            ->body($message);
        
        if ($errorCount === 0) {
            $notification->success();
            } else {
                $notification
                    ->warning()
                    ->persistent();
            }
        
        $notification->send();
    }

    protected function readExcelHeader(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
    
        return [
            'address_code' => trim((string) $sheet->getCell('C3')->getFormattedValue()),
            'season' => trim((string) $sheet->getCell('C5')->getFormattedValue()),
            'brand' => trim((string) $sheet->getCell('C6')->getFormattedValue()),
            'order_sheet_type' => trim((string) $sheet->getCell('C7')->getFormattedValue()),
        ];
    }

    protected function resolveImportOrder(array $header): ?Order
    {
        $this->order->loadMissing(['partner', 'partnerAddress']);
    
        $partnerAddress = PartnerAddress::query()
            ->where('partner_id', $this->order->partner_id)
            ->where('addrid', $header['address_code'])
            ->first();
    
        if (! $partnerAddress) {
            return null;
        }
    
        $season = \App\Models\Season::query()
            ->where('code', $header['season'])
            ->orWhere('name', $header['season'])
            ->first();
    
        if (! $season) {
            return null;
        }
    
        $brand = \App\Models\Brand::query()
            ->where('code', $header['brand'])
            ->orWhere('name', $header['brand'])
            ->first();
    
        if (! $brand) {
            return null;
        }
    
        $orderSheetType = \App\Models\OrderSheetType::query()
            ->where('code', $header['order_sheet_type'])
            ->orWhere('name', $header['order_sheet_type'])
            ->orWhere('name_hu', $header['order_sheet_type'])
            ->orWhere('name_en', $header['order_sheet_type'])
            ->orWhereIn(
                'code',
                \App\Models\Translation::query()
                    ->where('entity', 'order_sheet_type')
                    ->where('field', 'name')
                    ->where('value', $header['order_sheet_type'])
                    ->select('entity_code')
            )
            ->first();
    
        if (! $orderSheetType) {
            return null;
        }
    
        return Order::query()
            ->where('partner_id', $this->order->partner_id)
            ->where('partner_address_id', $partnerAddress->id)
            ->where('season_id', $season->id)
            ->where('brand_id', $brand->id)
            ->where('order_sheet_type_id', $orderSheetType->id)
            ->where('price_list_id', $this->order->price_list_id)
            ->first();
    }

    protected function refreshAfterExcelImport(): void
    {
        $this->order->refresh();

        if (method_exists($this, 'loadExistingQuantities')) {
            $this->loadExistingQuantities();
        }

        if (method_exists($this, 'buildMatrixGroups')) {
            $this->buildMatrixGroups();
        }

        $this->dispatch('$refresh');
    }

    protected function writeImportLog(?Order $order, string $filename, ?string $addressCode, string $status, array $stats, ?string $errorMessage): void
    {
        if (! class_exists(OrderImportLog::class)) {
            return;
        }

        if (! Schema::hasTable('order_import_logs')) {
            return;
        }

        OrderImportLog::query()->create([
            'order_id' => $order?->id,
            'user_id' => auth('web')->id(),
            'partner_user_id' => auth('partner')->id(),
            'partner_id' => $this->order->partner_id ?? null,
            'partner_address_id' => $order?->partner_address_id,
            'address_code' => $addressCode,
            'filename' => $filename,
            'status' => $status,
            'created_count' => (int) ($stats['created'] ?? 0),
            'updated_count' => (int) ($stats['updated'] ?? 0),
            'deleted_count' => (int) ($stats['deleted'] ?? 0),
            'unchanged_count' => (int) ($stats['unchanged'] ?? 0),
            'invalid_count' => (int) ($stats['invalid'] ?? 0),
            'changed_count' => (int) ($stats['changed'] ?? 0),
            'warnings' => $stats['warnings'] ?? [],
            'error_message' => $errorMessage,
            'imported_at' => now(),
        ]);
    }

    protected function isSubmittedOrder(Order $order): bool
    {
        return method_exists($order, 'isSubmitted') && $order->isSubmitted();
    }
}

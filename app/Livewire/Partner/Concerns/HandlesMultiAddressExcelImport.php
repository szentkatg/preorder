<?php

namespace App\Livewire\Partner\Concerns;

use App\Imports\PartnerOrderMatrixImport;
use App\Models\Brand;
use App\Models\Order;
use App\Models\OrderImportLog;
use App\Models\OrderSheetType;
use App\Models\PartnerAddress;
use App\Models\Season;
use App\Models\Translation;
use App\Services\Partner\PartnerSeasonContext;
use App\Services\Partner\PartnerSeasonVisibility;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

trait HandlesMultiAddressExcelImport
{
    public array $importResults = [];

    public array $importPreviews = [];

    public array $selectedImportPreviewKeys = [];

    public array $importReferenceNumbers = [];

    public function updatedExcelFiles(): void
    {
        $this->importResults = [];
        $this->importPreviews = [];
        $this->selectedImportPreviewKeys = [];
        $this->importReferenceNumbers = [];
        $this->resetValidation('importReferenceNumbers');
    }

    public function previewExcelImports(): void
    {
        if (! $this->canStartExcelImport()) {
            return;
        }

        $this->validateExcelImportFiles();

        $previews = [];
        foreach (Arr::wrap($this->excelFiles) as $index => $file) {
            $previews[] = $this->previewExcelFile($file, (int) $index);
        }
        $this->setImportPreviewState($previews);
        $this->selectAllImportPreviews();

        $notification = Notification::make()
            ->title(__('partner.import_preview'))
            ->body($this->importPreviews['message']);

        if ($this->importPreviews['success']) {
            $notification->success();
        } else {
            $notification
                ->warning()
                ->persistent();
        }

        $notification->send();
        $this->dispatch('order-import-feedback', componentId: $this->getId());
    }

    public function updatedImportReferenceNumbers(mixed $value, string $key): void
    {
        if (! $this->canStartExcelImport() || ! preg_match('/^file-(\d+)$/', $key, $matches)) {
            return;
        }

        $index = (int) $matches[1];
        $previews = $this->importPreviews['files'] ?? [];
        $position = array_search($key, array_column($previews, 'key'), true);
        if ($position === false || ! isset($this->excelFiles[$index])) {
            return;
        }

        $this->validateOnly('importReferenceNumbers.'.$key, [
            'importReferenceNumbers.'.$key => ['nullable', 'string', 'max:100'],
        ]);
        $preview = $this->previewExcelFile($this->excelFiles[$index], $index);
        $previews[$position] = $preview;
        $this->selectedImportPreviewKeys = array_values(array_diff($this->selectedImportPreviewKeys, [$key]));
        if ($preview['success']) {
            $this->selectedImportPreviewKeys[] = $key;
        }
        $this->setImportPreviewState($previews);
    }

    protected function previewExcelFile(mixed $file, int $index): array
    {
        $key = $this->importPreviewKey($index);
        $header = null;
        $missingReference = false;
        try {
            $header = $this->readExcelHeader($file->getRealPath());
            $missingReference = $header['reference_number'] === '';
            ['header' => $header, 'targetOrder' => $targetOrder, 'addressCode' => $addressCode] =
                $this->prepareImportHeader($header, $this->importReferenceNumbers[$key] ?? null);

            if ($this->isSubmittedOrder($targetOrder)) {
                throw new \RuntimeException(__('partner.import_target_order_already_submitted', ['address' => $addressCode]));
            }
            $import = new PartnerOrderMatrixImport($targetOrder, previewOnly: true);
            Excel::import($import, $file->getRealPath());
            $stats = $import->stats();
            if (($stats['rows_with_meta'] ?? 0) < 1) {
                throw new \RuntimeException(__('partner.import_no_import_map_found'));
            }
            $success = true;
            $message = __('partner.import_preview_ready');
        } catch (Throwable $exception) {
            $success = false;
            $message = $exception->getMessage();
            $stats = $this->emptyImportStats();
        }

        return [
            'key' => $key,
            'index' => $index,
            'filename' => $this->originalImportFilename($file),
            'address_code' => $header['address_code'] ?? null,
            'reference_number' => $header['reference_number'] ?? null,
            'missing_reference_number' => $missingReference,
            'success' => $success,
            'message' => $message,
            'stats' => $stats,
        ];
    }

    protected function setImportPreviewState(array $previews): void
    {
        $ready = count(array_filter($previews, fn (array $preview) => $preview['success']));
        $failed = count($previews) - $ready;
        $this->importPreviews = [
            'success' => $failed === 0,
            'message' => __('partner.import_preview_summary', ['ready' => $ready, 'failed' => $failed]),
            'files' => $previews,
        ];
        $this->importResults = [];
    }

    public function selectAllImportPreviews(): void
    {
        $this->selectedImportPreviewKeys = collect($this->importPreviews['files'] ?? [])
            ->filter(fn (array $preview) => (bool) ($preview['success'] ?? false))
            ->pluck('key')
            ->values()
            ->all();
    }

    public function clearSelectedImportPreviews(): void
    {
        $this->selectedImportPreviewKeys = [];
    }

    public function importSelectedExcelFiles(): void
    {
        if (! $this->canStartExcelImport()) {
            return;
        }

        $this->validateExcelImportFiles();

        $selectedKeys = array_flip($this->selectedImportPreviewKeys);

        if ($selectedKeys === []) {
            $message = __('partner.no_import_selected');

            Notification::make()
                ->title(__('partner.import_failed'))
                ->body($message)
                ->warning()
                ->send();

            return;
        }

        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach (Arr::wrap($this->excelFiles) as $index => $file) {
            if (! isset($selectedKeys[$this->importPreviewKey((int) $index)])) {
                continue;
            }

            $result = $this->importExcelFile($file, $this->importPreviewKey((int) $index));
            $results[] = $result;

            if ($result['success'] ?? false) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        $this->reset('excelFiles', 'importReferenceNumbers');
        $this->importPreviews = [];
        $this->selectedImportPreviewKeys = [];

        $this->refreshAfterExcelImport();

        $this->finishExcelImport($results, $successCount, $errorCount);
    }

    public function importExcelFiles(): void
    {
        if (! $this->canStartExcelImport()) {
            return;
        }

        $this->validateExcelImportFiles();

        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach (Arr::wrap($this->excelFiles) as $index => $file) {
            $result = $this->importExcelFile($file, $this->importPreviewKey((int) $index));
            $results[] = $result;

            if ($result['success'] ?? false) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        $this->reset('excelFiles', 'importReferenceNumbers');
        $this->importPreviews = [];
        $this->selectedImportPreviewKeys = [];

        $this->refreshAfterExcelImport();

        $this->finishExcelImport($results, $successCount, $errorCount);
    }

    protected function canStartExcelImport(): bool
    {
        PartnerSeasonVisibility::assertVisible($this->order->season_id);
        if (! $this->isSubmittedOrder($this->order)) {
            return true;
        }

        $message = __('partner.import_order_already_submitted');

        $this->importResults = [
            'success' => false,
            'message' => $message,
            'files' => [],
        ];
        $this->importPreviews = [];
        $this->selectedImportPreviewKeys = [];

        $notification = Notification::make()
            ->title(__('partner.import_finished_with_errors'))
            ->body($message)
            ->warning()
            ->persistent();

        $notification->send();

        $this->dispatch('order-import-feedback', componentId: $this->getId());

        return false;
    }

    protected function validateExcelImportFiles(): void
    {
        $this->validate([
            'excelFiles' => ['required', 'array', 'min:1'],
            'excelFiles.*' => ['file', 'mimes:xlsx,xls'],
            'importReferenceNumbers' => ['array'],
            'importReferenceNumbers.*' => ['nullable', 'string', 'max:100'],
        ]);
    }

    protected function importExcelFile(mixed $file, string $key): array
    {
        $path = $file->getRealPath();
        $originalName = $this->originalImportFilename($file);
        $header = null;

        try {
            $header = $this->readExcelHeader($path);
            ['header' => $header, 'targetOrder' => $targetOrder, 'addressCode' => $addressCode] =
                $this->prepareImportHeader($header, $this->importReferenceNumbers[$key] ?? null);

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

            return [
                'filename' => $originalName,
                'address_code' => $addressCode,
                'reference_number' => $header['reference_number'],
                'success' => true,
                'message' => __('partner.import_file_successful'),
                'stats' => $stats,
            ];
        } catch (Throwable $exception) {
            $stats = $this->emptyImportStats();

            $this->writeImportLog(null, $originalName, $header['address_code'] ?? null, 'failed', $stats, $exception->getMessage());

            return [
                'filename' => $originalName,
                'address_code' => $header['address_code'] ?? null,
                'reference_number' => $header['reference_number'] ?? null,
                'success' => false,
                'message' => $exception->getMessage(),
                'stats' => $stats,
            ];
        }
    }

    protected function finishExcelImport(array $results, int $successCount, int $errorCount): void
    {
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
        $this->dispatch('order-import-feedback', componentId: $this->getId());
    }

    protected function prepareImportHeader(array $header, ?string $referenceNumber = null): array
    {
        // Only supplement an empty F1; never redirect a file that already identifies an order.
        if ($header['reference_number'] === '') {
            $header['reference_number'] = trim($referenceNumber ?? '');
        }

        if ($header['address_code'] === '') {
            throw new \RuntimeException(__('partner.import_missing_address_code'));
        }

        if ($header['reference_number'] === '') {
            throw new \RuntimeException(__('partner.import_missing_reference_number'));
        }

        $targetOrder = $this->resolveImportOrder($header);
        $addressCode = $header['address_code'];

        if (! $targetOrder) {
            throw new \RuntimeException(__('partner.import_order_not_found_for_reference', [
                'address' => $addressCode,
                'reference' => $header['reference_number'],
            ]));
        }

        return [
            'header' => $header,
            'targetOrder' => $targetOrder,
            'addressCode' => $addressCode,
        ];
    }

    protected function importPreviewKey(int $index): string
    {
        return 'file-'.$index;
    }

    protected function originalImportFilename(mixed $file): string
    {
        return method_exists($file, 'getClientOriginalName')
            ? $file->getClientOriginalName()
            : 'excel';
    }

    protected function emptyImportStats(): array
    {
        return [
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
    }

    protected function readExcelHeader(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        try {
            $sheet = $spreadsheet->getActiveSheet();

            return [
                'address_code' => trim((string) $sheet->getCell('C3')->getFormattedValue()),
                'season' => trim((string) $sheet->getCell('C5')->getFormattedValue()),
                'brand' => trim((string) $sheet->getCell('C6')->getFormattedValue()),
                'order_sheet_type' => trim((string) $sheet->getCell('C7')->getFormattedValue()),
                'reference_number' => trim((string) $sheet->getCell('F1')->getFormattedValue()),
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    protected function resolveImportOrder(array $header): ?Order
    {
        $partnerId = $this->excelImportPartnerId();

        $partnerAddress = PartnerAddress::query()
            ->when($partnerId !== null, fn ($query) => $query->where('partner_id', $partnerId))
            ->where('addrid', $header['address_code'])
            ->first();

        if (! $partnerAddress) {
            return null;
        }

        $partnerUser = auth('partner')->user();

        if (! $partnerUser || ! $partnerUser->canAccessAddress($partnerAddress)) {
            throw new \RuntimeException(__('partner.import_address_not_allowed', [
                'address' => $header['address_code'],
            ]));
        }

        $season = Season::query()
            ->visibleToPartners()
            ->whereKey(PartnerSeasonContext::id())
            ->where(fn ($query) => $query->where('code', $header['season'])->orWhere('name', $header['season']))
            ->first();

        if (! $season) {
            return null;
        }

        $brand = Brand::query()
            ->where('code', $header['brand'])
            ->orWhere('name', $header['brand'])
            ->first();

        if (! $brand) {
            return null;
        }

        $orderSheetType = OrderSheetType::query()
            ->where('code', $header['order_sheet_type'])
            ->orWhere('name', $header['order_sheet_type'])
            ->orWhereIn(
                'code',
                Translation::query()
                    ->where('entity', 'order_sheet_type')
                    ->where('field', 'name')
                    ->where('value', $header['order_sheet_type'])
                    ->select('entity_code')
            )
            ->first();

        if (! $orderSheetType) {
            return null;
        }

        return $partnerUser->accessibleOrdersQuery()
            ->where('partner_id', $partnerAddress->partner_id)
            ->where('partner_address_id', $partnerAddress->id)
            ->where('season_id', $season->id)
            ->where('brand_id', $brand->id)
            ->where('order_sheet_type_id', $orderSheetType->id)
            ->where('reference_number', $header['reference_number'])
            ->first();
    }

    /** An open order limits imports to that partner; the selector import has no such context. */
    protected function excelImportPartnerId(): ?int
    {
        return (int) $this->order->partner_id;
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
            'partner_id' => $order?->partner_id ?? $this->excelImportPartnerId(),
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

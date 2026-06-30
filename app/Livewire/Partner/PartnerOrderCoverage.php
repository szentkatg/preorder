<?php

namespace App\Livewire\Partner;

use App\Models\Season;
use App\Services\PartnerOrderCoverageService;
use Livewire\Component;
use App\Exports\PartnerOrderCoverageExport;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PartnerOrderCoverage extends Component
{
    public ?int $seasonId = null;

    public string $search = '';

    public $onlyMissing = false;

    public function getSeasonsProperty()
    {
        return Season::query()
            ->orderByDesc('id')
            ->get();
    }

    public function export(): BinaryFileResponse
    {
        return Excel::download(
            new PartnerOrderCoverageExport(
                $this->seasonId,
                $this->locale ?? app()->getLocale(),
            ),
            'partner-order-coverage.xlsx'
        );
    }

    public function mount(): void
    {
        if ($locale = request('locale')) {
            app()->setLocale($locale);
        }
    }
    public function getCoverageProperty(): array
    {
        $coverage = app(PartnerOrderCoverageService::class)
            ->build($this->seasonId);

        if ($this->search !== '') {
            $search = mb_strtolower(trim($this->search));

            $coverage['rows'] = collect($coverage['rows'])
                ->filter(function (array $row) use ($search) {
                    return str_contains(mb_strtolower((string) $row['partner_code']), $search)
                        || str_contains(mb_strtolower((string) $row['partner_name']), $search)
                        || str_contains(mb_strtolower((string) $row['address_name']), $search)
                        || str_contains(mb_strtolower((string) $row['address']), $search);
                })
                ->values()
                ->all();
        }

        if (filter_var($this->onlyMissing, FILTER_VALIDATE_BOOLEAN)) {
            $coverage['rows'] = collect($coverage['rows'])
                ->filter(fn (array $row): bool => (int) ($row['grand_total'] ?? 0) > 0)
                ->values()
                ->all();
        }

        return $coverage;
    }

    public function openCoverageCell(
        ?int $orderId,
        int $partnerAddressId,
        int $brandId,
        int $orderSheetTypeId,
    ): void {
        if ($orderId) {
            session(['order_selector.open_order_id' => $orderId]);
    
            $this->redirectRoute('partner.orders.select');
    
            return;
        }
    
        session([
            'order_selector.preselect' => [
                'partner_address_id' => $partnerAddressId,
                'brand_id' => $brandId,
                'order_sheet_type_id' => $orderSheetTypeId,
                'season_id' => $this->seasonId,
            ],
        ]);
    
        $this->redirectRoute('partner.orders.select');
    }

    public function render()
    {
        return view('livewire.partner.partner-order-coverage');
    }
}

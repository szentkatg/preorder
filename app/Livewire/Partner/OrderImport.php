<?php

namespace App\Livewire\Partner;

use App\Livewire\Partner\Concerns\HandlesMultiAddressExcelImport;
use App\Livewire\Partner\Concerns\RequiresSelectedPartnerSeason;
use App\Models\PartnerUser;
use App\Services\Partner\PartnerSeasonContext;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class OrderImport extends Component
{
    use HandlesMultiAddressExcelImport;
    use RequiresSelectedPartnerSeason;
    use WithFileUploads;

    #[Locked]
    public int $seasonId;

    public bool $showImportModal = false;

    public array $excelFiles = [];

    public function boot(): void
    {
        app()->setLocale((string) session('partner.locale', 'hu'));
    }

    public function mount(): void
    {
        $this->seasonId = PartnerSeasonContext::id();
        $this->authorizeImport();
    }

    #[On('open-order-import')]
    public function open(): void
    {
        $this->authorizeImport();
        $this->resetValidation();
        $this->showImportModal = true;
    }

    public function close(): void
    {
        $this->reset('excelFiles', 'importPreviews', 'importResults', 'selectedImportPreviewKeys', 'importReferenceNumbers', 'showImportModal');
        $this->resetValidation();
    }

    protected function canStartExcelImport(): bool
    {
        $this->authorizeImport();

        return true;
    }

    protected function excelImportPartnerId(): ?int
    {
        return null;
    }

    protected function refreshAfterExcelImport(): void
    {
        $this->dispatch('order-summary-changed');
    }

    private function authorizeImport(): void
    {
        $user = auth('partner')->user();
        abort_unless($user?->active && in_array($user->role, [
            PartnerUser::ROLE_PARTNER_ADMIN, PartnerUser::ROLE_ADDRESS_USER, PartnerUser::ROLE_SALES_REP,
        ], true), 403);
        PartnerSeasonContext::assertMatches($this->seasonId);
    }

    public function render()
    {
        return view('livewire.partner.order-import');
    }
}

<?php

namespace App\Livewire\Partner;

use App\Models\OrderShareLink;

class SharedCatalogGroupOrder extends CatalogGroupOrder
{
    public function mount(mixed $shareLink, string $catalogGroupName): void
    {
        if (! $shareLink instanceof OrderShareLink) {
            $shareLink = OrderShareLink::query()
                ->where('token', $shareLink)
                ->firstOrFail();
        }

        abort_unless($shareLink->isAvailable(), 404);

        $this->sharedMode = true;
        $this->shareLink = $shareLink;

        $this->order = $shareLink->order()->with([
            'partner',
            'partnerAddress.language',
            'priceList',
            'priceList.currency',
            'items.sku.assortmentComponents',
        ])->firstOrFail();

        abort_if($this->order->isSubmitted(), 404);

        $this->setPartnerLocale();

        $this->catalogGroupName = urldecode($catalogGroupName);

        $this->setDisplayPriceList($shareLink->price_list_id ?: $this->order->price_list_id);

        $this->allowAssortmentOrdering = (bool) $this->order->partnerAddress?->allow_assortment_ordering;

        $this->loadExistingQuantities();

        $this->buildMatrixGroups();

        $this->loadNavigation();

        $shareLink->forceFill([
            'last_accessed_at' => now(),
        ])->save();
    }
}

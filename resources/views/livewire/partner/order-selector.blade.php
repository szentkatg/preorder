<div class="w-full min-w-0 max-w-none p-6" data-order-tabs
    x-data="{
        activeTab: 'selection',
        summaryOpened: false,
        selectTab(tab) {
            if (! ['selection', 'summary'].includes(tab) || this.activeTab === tab) return;
            this.activeTab = tab;
            if (tab === 'summary' && ! this.summaryOpened) {
                this.summaryOpened = true;
                this.$dispatch('order-summary-tab-opened');
            }
        }
    }">
    <div
        class="sticky top-0 z-50 -mx-6 bg-gray-100 px-6 pb-2 pt-6"
    >
        <div class="grid grid-cols-1 gap-3 rounded-lg border bg-white p-4 shadow-sm md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] md:items-center">
            <div class="min-w-0 text-sm">
                @if ($this->selectedAddress)
                    <div class="truncate font-semibold">
                        {{ $this->selectedAddress->partner?->erp_partner_code ?? '' }}
                        -
                        {{ $this->selectedAddress->partner?->name ?? '' }}
                    </div>

                    <div class="mt-1 truncate">
                        {{ $this->selectedAddress->name ?? $this->selectedAddress->addrid }}
                    </div>

                    <div class="truncate text-gray-600">
                        {{ $this->formatAddress($this->selectedAddress) }}
                    </div>
                @endif
            </div>

            <div class="min-w-0 space-y-2">
                <h1 class="text-center text-2xl font-bold">{{ __('partner.orders_workspace') }}</h1>
                <x-partner-season-banner :season="$this->selectedSeason" />
            </div>

            <div class="flex flex-wrap items-center gap-2 justify-self-start md:justify-self-end">
                <x-partner-language-selector />
                <form method="POST" action="{{ route('partner.logout') }}">
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 rounded bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="2"
                            stroke="currentColor"
                            class="h-4 w-4"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-7.5A2.25 2.25 0 003.75 5.25v13.5A2.25 2.25 0 006 21h7.5a2.25 2.25 0 002.25-2.25V15m-6-3h10.5m0 0l-3-3m3 3l-3 3"
                            />
                        </svg>
                        {{ __('partner.logout') }}
                    </button>
                </form>

            </div>
        </div>

        <div role="tablist" aria-label="{{ __('partner.order_sections') }}"
            class="mt-2 flex gap-1 overflow-x-auto rounded-lg border bg-white p-1 shadow-sm">
            <button type="button" role="tab" id="order-selection-tab" x-ref="selectionTab"
                aria-controls="order-selection-panel" aria-selected="true" tabindex="0"
                :aria-selected="activeTab === 'selection'" :tabindex="activeTab === 'selection' ? 0 : -1"
                @click="selectTab('selection')"
                @keydown.arrow-right.prevent="selectTab('summary'); $refs.summaryTab.focus()"
                @keydown.arrow-left.prevent="selectTab('summary'); $refs.summaryTab.focus()"
                @keydown.home.prevent="selectTab('selection'); $refs.selectionTab.focus()"
                @keydown.end.prevent="selectTab('summary'); $refs.summaryTab.focus()"
                :class="activeTab === 'selection' ? 'bg-sky-100 text-sky-950 border-sky-400' : 'bg-white text-gray-600 border-transparent hover:bg-gray-50'"
                class="flex-1 whitespace-nowrap rounded-md border px-4 py-3 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-sky-600">
                {{ __('partner.order_selection') }}
            </button>
            <button type="button" role="tab" id="order-summary-tab" x-ref="summaryTab"
                aria-controls="order-summary-panel" aria-selected="false" tabindex="-1"
                :aria-selected="activeTab === 'summary'" :tabindex="activeTab === 'summary' ? 0 : -1"
                @click="selectTab('summary')"
                @keydown.arrow-right.prevent="selectTab('selection'); $refs.selectionTab.focus()"
                @keydown.arrow-left.prevent="selectTab('selection'); $refs.selectionTab.focus()"
                @keydown.home.prevent="selectTab('selection'); $refs.selectionTab.focus()"
                @keydown.end.prevent="selectTab('summary'); $refs.summaryTab.focus()"
                :class="activeTab === 'summary' ? 'bg-sky-100 text-sky-950 border-sky-400' : 'bg-white text-gray-600 border-transparent hover:bg-gray-50'"
                class="flex-1 whitespace-nowrap rounded-md border px-4 py-3 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-sky-600">
                {{ __('partner.sales_rep_summary') }}
            </button>
        </div>
    </div>

    <section id="order-selection-panel" role="tabpanel" aria-labelledby="order-selection-tab"
        x-show="activeTab === 'selection'" tabindex="0">
        @include('livewire.partner.partials.order-selector-form')
    </section>
    <section id="order-summary-panel" role="tabpanel" aria-labelledby="order-summary-tab"
        x-show="activeTab === 'summary'" style="display: none;" tabindex="0">
        <livewire:partner.sales-rep-order-summary />
    </section>
    <livewire:partner.order-bulk-submission />
    <livewire:partner.order-import />

</div>

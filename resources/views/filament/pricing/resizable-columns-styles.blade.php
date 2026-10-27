<style>
        [data-pricing-resizable-columns] .fi-ta-table {
            width: max-content;
            min-width: 100%;
            table-layout: auto;
        }

        [data-pricing-resizable-columns] {
            max-width: 100%;
        }

        [data-pricing-resizable-columns] .fi-ta-table th,
        [data-pricing-resizable-columns] .fi-ta-table td {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        [data-pricing-resizable-columns] .fi-ta-table th {
            position: relative;
            padding-block: 0.25rem;
            line-height: 1.25rem;
            white-space: normal;
            overflow-wrap: anywhere;
            text-overflow: clip;
            vertical-align: bottom;
        }

        /* Sortable headings contain a text node and icons. Inline flow lets the
           label wrap inside a fixed-width column without enlarging the column. */
        [data-pricing-resizable-columns] .fi-ta-header-cell-sort-btn {
            display: block;
            width: 100%;
            min-width: 0;
            white-space: normal;
            overflow-wrap: anywhere;
            text-align: inherit;
        }

        [data-pricing-resizable-columns] .fi-ta-header-cell-tooltip {
            white-space: normal;
            overflow-wrap: anywhere;
        }

        [data-pricing-resizable-columns] .fi-ta-header-cell-sort-btn > svg:not(.fi-loading-indicator) {
            display: inline-block;
            vertical-align: middle;
        }

        [data-pricing-resizable-columns] th[data-pricing-average-multiplier]::before {
            content: attr(data-pricing-average-multiplier);
            display: block;
            width: fit-content;
            max-width: 100%;
            margin-bottom: 0.125rem;
            margin-inline-start: auto;
            padding: 0.125rem 0.375rem;
            border: 1px solid rgb(147 197 253);
            border-radius: 0.375rem;
            background-color: rgb(239 246 255);
            color: rgb(30 64 175);
            font-size: 0.8125rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            line-height: 1.125rem;
        }

        .dark [data-pricing-resizable-columns] th[data-pricing-average-multiplier]::before {
            border-color: rgb(59 130 246);
            background-color: rgb(30 58 138 / 0.35);
            color: rgb(191 219 254);
        }

        [data-pricing-resizable-columns] .fi-ta-table > tbody > tr > .fi-ta-cell {
            padding-block: 0.125rem;
            font-size: 0.875rem;
            line-height: 1.25rem;
        }

        [data-pricing-resizable-columns] .fi-ta-cell .fi-ta-text,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-ta-text-input,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-ta-icon {
            padding-block: 0;
        }

        [data-pricing-resizable-columns] .fi-ta-cell .fi-input-wrp,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-btn {
            min-height: 0;
        }

        [data-pricing-resizable-columns] .fi-ta-cell .fi-input {
            height: 1.5rem;
        }

        [data-pricing-resizable-columns] .fi-ta-cell .fi-ta-icon .fi-icon {
            width: 1.25rem;
            height: 1.25rem;
        }

        [data-pricing-resizable-columns] .pricing-retail-manual-input {
            font-variant-numeric: tabular-nums;
            text-align: right;
        }

        [data-pricing-resizable-columns] .pricing-retail-manual-down {
            color: rgb(185 28 28);
        }

        [data-pricing-resizable-columns] .pricing-retail-manual-up {
            color: rgb(21 128 61);
        }

        .dark [data-pricing-resizable-columns] .pricing-retail-manual-down {
            color: rgb(248 113 113);
        }

        .dark [data-pricing-resizable-columns] .pricing-retail-manual-up {
            color: rgb(74 222 128);
        }

        [data-pricing-resizable-columns] .fi-ta-cell .fi-input,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-btn,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-badge {
            padding-block: 0;
        }

        [data-pricing-resizable-columns] .fi-ta-cell .fi-ta-text-item,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-input,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-link,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-btn {
            line-height: 1.25rem;
        }

        [data-pricing-resizable-columns] .fi-ta-cell-shipping-cost-percent .fi-ta-text-input,
        [data-pricing-resizable-columns] .fi-ta-cell-customs-percent .fi-ta-text-input {
            min-width: 0;
            padding-inline: 0.5rem;
        }

        [data-pricing-resizable-columns] .fi-ta-header-cell-shipping-cost-percent,
        [data-pricing-resizable-columns] .fi-ta-header-cell-customs-percent,
        [data-pricing-resizable-columns] .fi-ta-individual-search-cell-shipping-cost-percent,
        [data-pricing-resizable-columns] .fi-ta-individual-search-cell-customs-percent {
            min-width: 0;
            padding-inline: 0.5rem;
        }

        [data-pricing-resizable-columns] [data-pricing-column-width].fi-ta-individual-search-cell,
        [data-pricing-resizable-columns] [data-pricing-column-width] .fi-ta-search-field,
        [data-pricing-resizable-columns] [data-pricing-column-width] .fi-ta-text-input {
            min-width: 0;
        }

        [data-pricing-resizable-columns] .fi-ta-cell-shipping-cost-percent .fi-input-wrp,
        [data-pricing-resizable-columns] .fi-ta-cell-customs-percent .fi-input-wrp {
            width: 5.25rem;
            min-width: 5.25rem;
        }

        [data-pricing-resizable-columns] .fi-ta-cell-shipping-cost-percent .fi-input-wrp-suffix,
        [data-pricing-resizable-columns] .fi-ta-cell-customs-percent .fi-input-wrp-suffix {
            flex: 0 0 auto;
            padding-inline: 0.25rem 0.5rem;
        }

        [data-pricing-resizable-columns] .pricing-percent-input {
            min-width: 0;
            padding-inline: 0.5rem 0.25rem;
            font-variant-numeric: tabular-nums;
            text-align: right;
        }

        [data-pricing-resizable-columns] .pricing-percent-input::-webkit-outer-spin-button,
        [data-pricing-resizable-columns] .pricing-percent-input::-webkit-inner-spin-button {
            margin: 0;
            appearance: none;
        }

        [data-pricing-column-resizer] {
            position: absolute;
            top: 0;
            right: -4px;
            z-index: 20;
            width: 8px;
            height: 100%;
            cursor: col-resize;
            touch-action: none;
            user-select: none;
        }

        [data-pricing-column-resizer]::after {
            position: absolute;
            top: 0.65rem;
            right: 3px;
            bottom: 0.65rem;
            width: 2px;
            content: "";
            border-radius: 999px;
            background: transparent;
        }

        [data-pricing-column-resizer]:hover::after,
        [data-pricing-column-resizer].is-resizing::after {
            background: rgb(59 130 246);
        }

        body.pricing-column-resize-active {
            cursor: col-resize;
            user-select: none;
        }
</style>

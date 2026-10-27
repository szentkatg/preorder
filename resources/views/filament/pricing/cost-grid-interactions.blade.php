<style>
    [data-pricing-cost-grid] td[data-cost-editable="true"],
    [data-pricing-price-grid] td[data-pricing-editable="true"] { cursor: text; background-color: rgba(59,130,246,.10); box-shadow: inset 0 -1px rgba(59,130,246,.35); }
    .dark [data-pricing-cost-grid] td[data-cost-editable="true"],
    .dark [data-pricing-price-grid] td[data-pricing-editable="true"] { background-color: rgba(96,165,250,.14); }
    [data-pricing-cost-grid] tr.pricing-cost-details-enabled { cursor: pointer; }
    [data-pricing-price-grid] tr.pricing-price-details-enabled { cursor: pointer; }
    [data-pricing-cost-grid] tr.pricing-cost-row:focus-visible,
    [data-pricing-cost-grid] td[data-cost-parameter]:focus-visible,
    [data-pricing-price-grid] tr.pricing-price-row:focus-visible,
    [data-pricing-price-grid] td[data-pricing-editable]:focus-visible { outline: 2px solid #3b82f6; outline-offset: -2px; }
    [data-pricing-cost-grid] .pricing-cost-cell-editor,
    [data-pricing-price-grid] .pricing-cost-cell-editor {
        display: block; width: 100%; min-width: 0; height: 1.5rem;
        padding: 0 .25rem; border: 1px solid #3b82f6; border-radius: .25rem;
        background: transparent; color: inherit; font: inherit; text-align: right;
    }
    [data-pricing-cost-grid] .pricing-cost-cell-editor:invalid { border-color: #dc2626; }
    [data-pricing-cost-grid] .pricing-cost-cell-editor:disabled { opacity: .6; }
    [data-cost-grid-error] { padding: .5rem; color: #dc2626; }
    [data-pricing-operations-menu] { position:fixed; z-index:100; min-width:16rem; max-width:calc(100vw - 1rem); border:1px solid #d1d5db; border-radius:.5rem; padding:.25rem; background:#fff; color:#111827; box-shadow:0 8px 24px #0003; }
    .dark [data-pricing-operations-menu] { background:#1f2937; color:#f3f4f6; border-color:#4b5563; }
    [data-pricing-operations-menu] :is(button,a) { display:block; text-align:left; width:100%; padding:.5rem .75rem; border-radius:.25rem; font-size:.875rem; }
    [data-pricing-operations-menu] :is(button,a):hover, [data-pricing-operations-menu] :is(button,a):focus-visible { background:rgba(59,130,246,.15); outline:2px solid #3b82f6; outline-offset:-2px; }
</style>
<script>
(() => {
    if (window.__pricingCostGridInteractions) return;
    window.__pricingCostGridInteractions = true;
    let active = null;
    let openingDetails = false;
    let menu = null;
    let menuTrigger = null;
    const grids = '[data-pricing-cost-grid],[data-pricing-price-grid]';
    const blocked = 'input,textarea,select,button,a,label,[role="checkbox"],[data-pricing-column-resizer]';

    window.addEventListener('pricing-grid-refreshed', event => {
        const { componentId, rows = [], headers = {} } = event.detail;
        const root = document.querySelector('[wire\\:id="' + CSS.escape(componentId) + '"]');
        if (!root) return;
        for (const row of rows) {
            root.querySelector('[wire\\:partial="' + row.anchor + '"]')?.closest('tr')
                ?.classList.toggle('pricing-needs-attention', row.attention);
        }
        for (const [name, attributes] of Object.entries(headers)) {
            const header = Array.from(root.querySelectorAll('th[data-pricing-column]'))
                .find(element => element.dataset.pricingColumn === name);
            if (header) for (const [attribute, value] of Object.entries(attributes)) header.setAttribute(attribute, value);
        }
        // Filament cell partials morph after the response. Keep user-resized widths too.
        requestAnimationFrame(() => {
            for (const row of rows) {
                const element = root.querySelector('[wire\\:partial="' + row.anchor + '"]')?.closest('tr');
                if (!element) continue;
                for (const cell of element.querySelectorAll('td[data-pricing-column]')) {
                    const header = Array.from(cell.closest('table').querySelectorAll('th[data-pricing-column]'))
                        .find(item => item.dataset.pricingColumn === cell.dataset.pricingColumn);
                    const width = Number(header?.dataset.pricingColumnWidth);
                    if (Number.isFinite(width) && width > 0) {
                        cell.style.width = cell.style.minWidth = cell.style.maxWidth = width + 'px';
                        cell.dataset.pricingColumnWidth = String(width);
                    }
                }
            }
        });
    });

    function wireFor(grid) {
        let component = grid;
        while (component && !component.hasAttribute('wire:id')) component = component.parentElement;
        return component ? window.Livewire?.find(component.getAttribute('wire:id')) : null;
    }

    function showError(grid) {
        const message = document.createElement('div');
        message.dataset.costGridError = 'true';
        message.setAttribute('role', 'alert');
        message.textContent = 'A művelet nem sikerült. Frissítsd az oldalt, és próbáld újra.';
        grid.querySelector('[data-cost-grid-error]')?.remove();
        grid.prepend(message);
    }

    function startEditor(cell, grid) {
        const wire = wireFor(grid);
        if (!wire || active?.saving || openingDetails) return;
        if (active) active.cancel();
        const input = document.createElement('input');
        input.type = 'text';
        input.inputMode = 'decimal';
        input.className = 'pricing-cost-cell-editor';
        const isCost = cell.hasAttribute('data-cost-parameter');
        const initialValue = (isCost ? cell.dataset.costValue : cell.dataset.pricingValue) || '';
        input.value = initialValue;
        input.setAttribute('aria-label', `${isCost ? cell.dataset.costLabel : cell.dataset.pricingLabel} szerkesztése`);
        const original = Array.from(cell.children).map(node => [node, node.style.display]);
        original.forEach(([node]) => node.style.display = 'none');
        cell.append(input);
        const editor = {
            saving: false,
            cancel() {
                if (active === editor) active = null;
                input.remove();
                original.forEach(([node, display]) => node.style.display = display);
            },
        };
        active = editor;
        async function commit() {
            if (active !== editor || editor.saving) return;
            const text = input.value.trim().replace(',', '.');
            const value = Number(text);
            if ((isCost && (!text || !Number.isFinite(value) || value < 0 || value > 9999.9999))
                || (!isCost && text && (!Number.isFinite(value) || value <= 0 || value > 99999999999.9999))) {
                input.setCustomValidity(isCost ? 'Adj meg 0 és 9999,9999 közötti százalékot.' : 'Adj meg pozitív árat, vagy hagyd üresen a visszaállításhoz.');
                input.reportValidity();
                input.focus();
                return;
            }
            if ((text === '' && initialValue === '') || (text !== '' && initialValue !== '' && value === Number(initialValue))) {
                editor.cancel();
                return;
            }
            editor.saving = true;
            input.disabled = true;
            try {
                if (isCost) await wire.startInlineCostPercentParameterAdjustment(Number(cell.dataset.costRecordId), cell.dataset.costParameter, text);
                else if (cell.dataset.pricingOutletListId) await wire.setOutletManualPrice(Number(cell.dataset.pricingRecordId), Number(cell.dataset.pricingOutletListId), text);
                else await wire.setPricingManualPrice(Number(cell.dataset.pricingRecordId), text);
            } catch (_) {
                showError(grid);
            } finally {
                editor.cancel();
            }
        }
        input.addEventListener('input', () => input.setCustomValidity(''));
        input.addEventListener('blur', commit);
        input.addEventListener('dblclick', event => event.stopPropagation());
        input.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault(); event.stopPropagation(); editor.cancel(); cell.focus();
            } else if (event.key === 'Enter') {
                event.preventDefault(); event.stopPropagation(); commit();
            }
        });
        input.focus(); input.select();
    }

    async function activate(event) {
        const target = event.target;
        if (!(target instanceof Element) || target.closest(blocked)) return;
        const grid = target.closest(grids);
        const row = target.closest('tr.pricing-cost-row,tr.pricing-price-row');
        if (!grid || !row || active?.saving || openingDetails) return;
        const cell = target.closest('td[data-cost-parameter],td[data-pricing-editable]');
        if (cell?.dataset.costEditable === 'true' || cell?.dataset.pricingEditable === 'true') {
            event.preventDefault(); event.stopPropagation(); startEditor(cell, grid); return;
        }
        const isCost = row.classList.contains('pricing-cost-row');
        if (!row.classList.contains(isCost ? 'pricing-cost-details-enabled' : 'pricing-price-details-enabled')) return;
        const prefix = isCost ? 'pricing-cost-row-id-' : 'pricing-price-row-id-';
        const key = Array.from(row.classList).find(name => name.startsWith(prefix) && /^\d+$/.test(name.slice(prefix.length)));
        const wire = wireFor(grid);
        if (!key || !wire) return;
        event.preventDefault(); event.stopPropagation();
        openingDetails = true;
        closeMenu();
        try {
            const id = Number(key.slice(prefix.length));
            if (isCost) await wire.openCostDetails(id);
            else await wire.openPricingDetails(id);
        }
        catch (_) { showError(grid); }
        finally { openingDetails = false; }
    }

    document.addEventListener('dblclick', activate);
    function closeMenu(focus = false) {
        menu?.remove(); menu = null;
        menuTrigger?.setAttribute('aria-expanded', 'false');
        if (focus && menuTrigger?.isConnected) menuTrigger.focus();
        menuTrigger = null;
    }
    document.addEventListener('click', event => {
        const trigger = event.target instanceof Element ? event.target.closest('[data-pricing-operations]') : null;
        if (!trigger) { if (!menu?.contains(event.target)) closeMenu(); return; }
        event.preventDefault(); event.stopPropagation();
        if (menuTrigger === trigger) { closeMenu(true); return; }
        closeMenu();
        const grid = trigger.closest('[data-pricing-operations-grid]');
        const wire = grid && wireFor(grid);
        if (!wire || active?.saving || openingDetails) return;
        let items;
        try { items = JSON.parse(trigger.dataset.pricingMenu); } catch (_) { return; }
        menuTrigger = trigger;
        trigger.setAttribute('aria-expanded', 'true');
        menu = document.createElement('div');
        menu.dataset.pricingOperationsMenu = 'true';
        menu.setAttribute('role', 'menu');
        menu.setAttribute('aria-label', 'Műveletek');
        items.forEach(item => {
            const button = document.createElement(item.url ? 'a' : 'button');
            if (item.url) button.href = item.url;
            else button.type = 'button';
            button.setAttribute('role', 'menuitem'); button.textContent = item.label;
            button.addEventListener('click', async () => {
                if (item.url) return;
                closeMenu(true);
                if (openingDetails || active?.saving) return;
                openingDetails = true;
                try { await wire.mountAction(item.action, { record_id: Number(trigger.dataset.pricingRecordId) }); }
                catch (_) { showError(grid); }
                finally { openingDetails = false; }
            });
            menu.append(button);
        });
        menu.addEventListener('keydown', event => {
            const buttons = Array.from(menu.querySelectorAll('[role="menuitem"]'));
            const index = buttons.indexOf(document.activeElement);
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault(); buttons[(index + (event.key === 'ArrowDown' ? 1 : buttons.length - 1)) % buttons.length]?.focus();
            } else if (event.key === 'Escape') { event.preventDefault(); closeMenu(true); }
            else if (event.key === 'Tab') closeMenu();
        });
        document.body.append(menu);
        const rect = trigger.getBoundingClientRect();
        menu.style.left = `${Math.max(8, Math.min(rect.right - menu.offsetWidth, window.innerWidth - menu.offsetWidth - 8))}px`;
        menu.style.top = `${Math.max(8, Math.min(rect.bottom + 4, window.innerHeight - menu.offsetHeight - 8))}px`;
        menu.querySelector('[role="menuitem"]')?.focus();
    });
    window.addEventListener('resize', () => closeMenu());
    document.addEventListener('scroll', () => closeMenu(), true);
    document.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === 'F2') activate(event);
    });
    function initializeRows() {
        document.querySelectorAll('[data-pricing-cost-grid] tr.pricing-cost-details-enabled,[data-pricing-price-grid] tr.pricing-price-details-enabled').forEach(row => {
            row.tabIndex = 0;
            row.setAttribute('aria-label', 'Kalkuláció részletei: dupla kattintás vagy Enter');
        });
    }
    document.addEventListener('DOMContentLoaded', initializeRows);
    document.addEventListener('livewire:navigating', () => { active?.cancel(); closeMenu(); });
    document.addEventListener('livewire:navigated', () => { active?.cancel(); closeMenu(); initializeRows(); });
    let scheduled = false;
    function scheduleInitialize() {
        closeMenu();
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(() => { scheduled = false; initializeRows(); });
    }
    if (window.Livewire) window.Livewire.hook('morph.updated', scheduleInitialize);
    else document.addEventListener('livewire:init', () => window.Livewire.hook('morph.updated', scheduleInitialize), { once: true });
    initializeRows();
})();
</script>

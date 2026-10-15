<style>
    [data-pricing-cost-grid] td[data-cost-editable="true"] { cursor: text; }
    [data-pricing-cost-grid] tr.pricing-cost-details-enabled { cursor: pointer; }
    [data-pricing-cost-grid] tr.pricing-cost-row:focus-visible,
    [data-pricing-cost-grid] td[data-cost-parameter]:focus-visible { outline: 2px solid #3b82f6; outline-offset: -2px; }
    [data-pricing-cost-grid] .pricing-cost-cell-editor {
        display: block; width: 100%; min-width: 0; height: 1.5rem;
        padding: 0 .25rem; border: 1px solid #3b82f6; border-radius: .25rem;
        background: transparent; color: inherit; font: inherit; text-align: right;
    }
    [data-pricing-cost-grid] .pricing-cost-cell-editor:invalid { border-color: #dc2626; }
    [data-pricing-cost-grid] .pricing-cost-cell-editor:disabled { opacity: .6; }
    [data-cost-grid-error] { padding: .5rem; color: #dc2626; }
</style>
<script>
(() => {
    if (window.__pricingCostGridInteractions) return;
    window.__pricingCostGridInteractions = true;
    let active = null;
    let openingDetails = false;
    const blocked = 'input,textarea,select,button,a,label,[role="checkbox"],[data-pricing-column-resizer]';

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
        input.value = cell.dataset.costValue || '';
        input.setAttribute('aria-label', `${cell.dataset.costLabel} szerkesztése`);
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
            if (!text || !Number.isFinite(value) || value < 0 || value > 9999.9999) {
                input.setCustomValidity('Adj meg 0 és 9999,9999 közötti százalékot.');
                input.reportValidity();
                input.focus();
                return;
            }
            if (cell.dataset.costValue !== '' && value === Number(cell.dataset.costValue)) {
                editor.cancel();
                return;
            }
            editor.saving = true;
            input.disabled = true;
            try {
                await wire.startInlineCostPercentParameterAdjustment(Number(cell.dataset.costRecordId), cell.dataset.costParameter, text);
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
        const grid = target.closest('[data-pricing-cost-grid]');
        const row = target.closest('tr.pricing-cost-row');
        if (!grid || !row || active?.saving || openingDetails) return;
        const cell = target.closest('td[data-cost-parameter]');
        if (cell?.dataset.costEditable === 'true') {
            event.preventDefault(); event.stopPropagation(); startEditor(cell, grid); return;
        }
        if (!row.classList.contains('pricing-cost-details-enabled')) return;
        const key = Array.from(row.classList).find(name => /^pricing-cost-row-id-\d+$/.test(name));
        const wire = wireFor(grid);
        if (!key || !wire) return;
        event.preventDefault(); event.stopPropagation();
        openingDetails = true;
        try { await wire.openCostDetails(Number(key.replace('pricing-cost-row-id-', ''))); }
        catch (_) { showError(grid); }
        finally { openingDetails = false; }
    }

    document.addEventListener('dblclick', activate);
    document.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === 'F2') activate(event);
    });
    function initializeRows() {
        document.querySelectorAll('[data-pricing-cost-grid] tr.pricing-cost-details-enabled').forEach(row => {
            row.tabIndex = 0;
            row.setAttribute('aria-label', 'Kalkuláció részletei: dupla kattintás vagy Enter');
        });
    }
    document.addEventListener('DOMContentLoaded', initializeRows);
    document.addEventListener('livewire:navigated', () => { active?.cancel(); initializeRows(); });
    let scheduled = false;
    function scheduleInitialize() {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(() => { scheduled = false; initializeRows(); });
    }
    if (window.Livewire) window.Livewire.hook('morph.updated', scheduleInitialize);
    else document.addEventListener('livewire:init', () => window.Livewire.hook('morph.updated', scheduleInitialize), { once: true });
    initializeRows();
})();
</script>

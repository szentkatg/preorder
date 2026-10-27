// Dependency-free tests of the shared client algorithm, using DOM/canvas stubs.
// This checks behavior, not native browser/font rendering.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

export async function runPricingColumnAutofitTests() {
    const source = readFileSync(new URL('../../resources/views/filament/pricing/resizable-columns-scripts.blade.php', import.meta.url), 'utf8')
        .replace(/<\/?script>/g, '')
        .replace('window.__pricingCostColumnResizingInit = initialisePricingColumnResizing;',
            'window.testFit = fitWidthsForUser; window.__pricingCostColumnResizingInit = initialisePricingColumnResizing;');
    function setup(onContent = () => undefined) {
        const state = { tableFilters: {}, tableSearch: '', tableColumnSearches: {}, tableColumns: [] };
        const saves = [];
        const status = { textContent: '' };
        const style = { fontStyle: 'normal', fontWeight: '400', fontSize: '14px', fontFamily: 'Test', letterSpacing: 'normal',
            textTransform: 'none', paddingLeft: '0', paddingRight: '0', borderLeftWidth: '0', borderRightWidth: '0' };
        const header = { classList: new Set(['fi-ta-header-cell', 'fi-ta-header-cell-name']), dataset: {}, textContent: 'Név',
            parentElement: null, querySelector: () => null,
            cloneNode: () => ({ textContent: 'Név', querySelectorAll: () => [] }) };
        const cell = { innerText: 'Rövid', parentElement: null, querySelector: () => null };
        const table = { querySelectorAll: () => [header], querySelector: () => ({ cells: [cell] }) };
        const container = { dataset: { pricingStorageKey: 'test', pricingServerWidths: '{"hidden":123}', pricingFitQuery: 'original' },
            isConnected: true, closest: () => ({ getAttribute: () => 'component' }), querySelectorAll: () => [table] };
        // classList.contains is the DOM spelling, while iteration uses the Set.
        header.classList.contains = header.classList.has.bind(header.classList);
        const component = { get: name => state[name], call: async (method, data) => {
            if (method === 'pricingColumnFitContent') {
                await onContent(state, container);
                return { name: { texts: ['WWWWWWWWWW', 'iiiiiiiiiiiiiiiiiiii'], icon: false, badge: false } };
            }
            assert.equal(method, 'savePricingColumnWidths');
            saves.push(data);
        } };
        const button = { disabled: false, closest: () => ({ querySelector: () => status }) };
        const document = { querySelector: () => container, querySelectorAll: () => [], addEventListener() {},
            createElement: () => ({ getContext: () => ({ font: '', measureText: text => ({ width: [...text]
                .reduce((sum, char) => sum + (char === 'W' ? 14 : char === 'i' ? 3 : 7), 0) }) }) }), fonts: { ready: Promise.resolve() } };
        const window = { Livewire: { find: () => component, hook() {} }, localStorage: { getItem: () => null, setItem() {} },
            clearTimeout() {}, setTimeout() {}, dispatchEvent() {} };
        const sandbox = { window, document, getComputedStyle: () => style, console: { error() {} }, Event: class {},
            MutationObserver: class { observe() {} disconnect() {} } };
        vm.runInNewContext(source, sandbox);
        return { button, status, saves, fit: () => window.testFit(button) };
    }
    const normal = setup();
    await normal.fit();
    assert.equal(normal.saves.length, 1);
    assert.equal(normal.saves[0].name, 172); // Widest in pixels, not highest character count.
    assert.equal(normal.saves[0].hidden, 123); // Hidden column widths are not thrown away.
    assert.equal(normal.button.disabled, false);
    assert.match(normal.status.textContent, /mentve/);

    const changedFilter = setup(state => { state.tableFilters = { status: 'approved' }; });
    await changedFilter.fit();
    assert.equal(changedFilter.saves.length, 0);
    assert.match(changedFilter.status.textContent, /megváltozott/);

    const changedProject = setup((state, container) => { container.dataset.pricingFitQuery = 'different-project'; });
    await changedProject.fit();
    assert.equal(changedProject.saves.length, 0);

    const failure = setup(() => { throw new Error('Network failure'); });
    await failure.fit();
    assert.equal(failure.saves.length, 0);
    assert.equal(failure.button.disabled, false);
    assert.match(failure.status.textContent, /nem sikerült/);
    return { cases: 4, status: 'passed', verification: 'DOM/canvas stubs, not a live browser' };
}

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

// Execute the real inline script, with controlled mutation delivery and a clock
// that makes a wide table take longer than the former 150 ms suppression window.
function fixture(source, columns = 80, bodyRows = 100) {
    let now = 0;
    let spanChecks = 0;
    let active = true;
    let nextTimer = 0;
    const timers = new Map();
    const observers = [];
    const calls = [];
    const storage = new Map();
    const hooks = {};
    const events = {};
    const mutations = (node) => {
        observers.filter((observer) => observer.target).forEach((observer) => {
            observer.records.push({ addedNodes: [node], removedNodes: [] });
        });
    };
    function element(tag, classes = []) {
        const handlers = {};
        const node = {
            nodeType: 1, tagName: tag.toUpperCase(), dataset: {}, children: [],
            classList: new Set(classes), innerText: tag, handlers,
            style: new Proxy({}, { set(target, key, value) { now += 0.02; target[key] = value; return true; } }),
            get colSpan() { spanChecks++; return this.span || 1; },
            getBoundingClientRect() { return { width: parseFloat(this.style.width) || 140 }; },
            addEventListener(name, handler) { (handlers[name] ??= []).push(handler); },
            removeEventListener(name, handler) { handlers[name] = handlers[name]?.filter((h) => h !== handler); },
            emit(name, data = {}) { handlers[name]?.slice().forEach((h) => h({ preventDefault() {}, stopPropagation() {}, ...data })); },
            setAttribute() {}, getAttribute() { return 'component'; },
            closest() { return node; },
            setPointerCapture() {}, hasPointerCapture() { return true; }, releasePointerCapture() {},
            matches(selector) { return selector.split(',').some((s) => s.trim().toUpperCase() === this.tagName); },
            querySelector(selector) { return this.querySelectorAll(selector)[0] || null; },
            querySelectorAll(selector) {
                return this.children.flatMap((child) => [child, ...child.querySelectorAll(selector)])
                    .filter((child) => selector === '[data-pricing-column-resizer]'
                        ? child.dataset.pricingColumnResizer
                        : child.matches(selector));
            },
            append(child) { child.parentElement = this; this.children.push(child); mutations(child); },
            remove() {
                this.parentElement.children = this.parentElement.children.filter((child) => child !== this);
                mutations(this);
            },
        };
        node.classList.contains = node.classList.has.bind(node.classList);
        node.classList.remove = node.classList.delete.bind(node.classList);
        return node;
    }
    const headers = Array.from({ length: columns }, (_, i) => element('th', ['fi-ta-header-cell', `fi-ta-header-cell-${i === 0 ? 'customs-percent' : `column-${i}`}`]));
    const rows = [{ cells: headers }, ...Array.from({ length: bodyRows }, () => ({ cells: Array.from({ length: columns }, () => element('td')) }))];
    const group = element('td');
    group.span = columns;
    rows.push({ cells: [group] });
    const table = element('table');
    table.rows = rows;
    table.querySelectorAll = (selector) => {
        if (selector === 'thead th') return headers;
        if (selector === 'tr') return rows.map((row) => ({ children: row.cells }));
        if (selector === 'th, td') return rows.flatMap((row) => row.cells);
        if (selector === '[data-pricing-column-resizer]') return headers.flatMap((h) => h.children);
        return [];
    };
    const container = element('div');
    container.dataset = { pricingStorageKey: 'test-user-cost', pricingServerWidths: '{"column-1":123}' };
    const reset = element('button');
    const document = {
        body: element('body'), documentElement: element('html'), createElement: element,
        querySelector() { return active ? container : null; },
        querySelectorAll(selector) { return active ? (selector.includes('.fi-ta-table') ? [table] : [reset]) : []; },
        addEventListener(name, callback) { (events[name] ??= []).push(callback); },
    };
    class Observer {
        constructor(callback) { this.callback = callback; this.records = []; observers.push(this); }
        observe(target) { this.target = target; }
        disconnect() { this.target = null; this.records = []; }
    }
    const window = {
        localStorage: { getItem: (k) => storage.get(k), setItem: (k, v) => storage.set(k, v), removeItem: (k) => storage.delete(k) },
        setTimeout(callback) { const id = ++nextTimer; timers.set(id, callback); return id; },
        clearTimeout(id) { timers.delete(id); }, dispatchEvent() {},
        Livewire: { hook(name, callback) { hooks[name] = callback; }, find() { return { call(...args) { calls.push(args); } }; } },
    };
    runInNewContext(source, { window, document, MutationObserver: Observer, Event: class {}, Date: { now: () => now }, getComputedStyle: () => ({ fontSize: '16px' }) });
    function flush(limit = 8) {
        for (let i = 0; i < limit; i++) {
            observers.forEach((observer) => {
                if (observer.records.length) observer.callback(observer.records.splice(0));
            });
            if (!timers.size) return;
            const batch = [...timers.values()]; timers.clear(); batch.forEach((callback) => callback());
        }
        throw new Error('Resizer did not settle: self-triggering mutation loop');
    }
    return { window, headers, rows, group, table, container, reset, calls, storage, hooks, events, element, mutations, flush,
        now: () => now, spanChecks: () => spanChecks, pending: () => timers.size, setActive: (value) => { active = value; } };
}

export function runPricingColumnResizingTests(view = readFileSync(new URL('../../resources/views/filament/pages/pricing-cost-calculations.blade.php', import.meta.url), 'utf8'), collectFailures = false) {
    const source = view.match(/<script>\s*([\s\S]*?)<\/script>\s*<\/x-filament-panels::page>/)[1];
    const results = [];
    function test(name, callback) {
        try { callback(); results.push({ name, passed: true }); }
        catch (error) { results.push({ name, passed: false, error: error.message }); if (!collectFailures) throw error; }
    }
    test('Slow wide table settles after repeated Livewire morphs', () => {
        const f = fixture(source); assert.ok(f.now() > 150);
        f.hooks['morph.updated'](); f.flush();
        f.headers.forEach((h) => assert.equal(h.children.length, 1));
    });
    test('Span checks grow linearly rather than once per column', () => {
        const f = fixture(source); assert.ok(f.spanChecks() <= 80 * 102);
        assert.equal(f.group.style.width, ''); assert.equal(f.headers[1].style.width, '123px');
    });
    test('Text and icon mutations do not rebuild the table', () => {
        const f = fixture(source); f.flush(); const original = f.headers[0].children[0];
        f.mutations(f.element('span')); f.mutations({ nodeType: 3 }); f.flush();
        assert.equal(f.headers[0].children[0], original);
    });
    test('Column insertion and reordering retain widths by stable key', () => {
        const f = fixture(source); const h = f.element('th', ['fi-ta-header-cell', 'fi-ta-header-cell-new']);
        f.headers.unshift(h); f.rows.slice(1, -1).forEach((row) => row.cells.unshift(f.element('td')));
        f.mutations(h); f.flush(); assert.equal(h.children.length, 1); assert.equal(f.headers[2].style.width, '123px');
        f.headers.reverse(); f.rows.slice(1, -1).forEach((row) => row.cells.reverse());
        f.events['apply-table-column-manager'].forEach((h) => h()); f.flush();
        assert.equal(f.headers.find((h) => h.classList.has('fi-ta-header-cell-column-1')).style.width, '123px');
    });
    test('Paging refreshes cached body cells', () => {
        const f = fixture(source); const row = { cells: Array.from({ length: 80 }, () => f.element('td')) };
        f.rows[1] = row; f.mutations(f.element('tr')); f.flush(); assert.equal(row.cells[1].style.width, '123px');
    });
    test('Dragging persists once on release and preserves percent minimum', () => {
        const f = fixture(source); assert.equal(f.calls.length, 0);
        const handle = f.headers[0].children[0]; handle.emit('pointerdown', { clientX: 100, pointerId: 1 });
        handle.emit('pointermove', { clientX: -100, pointerId: 1 }); assert.equal(f.headers[0].style.width, '100px');
        handle.emit('pointerup', { pointerId: 1 });
        assert.equal(f.calls.length, 1); assert.equal(f.calls[0][0], 'savePricingColumnWidths'); f.flush();
    });
    test('Reset is observer-safe and restores natural widths', () => {
        const f = fixture(source); f.hooks['morph.updated'](); f.flush();
        f.reset.emit('click'); f.flush(); assert.equal(f.headers[1].style.width, '140px');
        assert.equal(f.calls.filter((c) => c[0] === 'resetPricingColumnWidths').length, 1);
    });
    test('Navigation disconnects old observer and switches saved layout context', () => {
        const f = fixture(source); f.setActive(false); f.window.__pricingCostColumnResizingInit();
        f.mutations(f.element('tr')); assert.equal(f.pending(), 0);
        f.container.dataset.pricingStorageKey = 'test-user-retail';
        f.container.dataset.pricingServerWidths = '{"column-1":188}'; f.setActive(true);
        f.window.__pricingCostColumnResizingInit(); f.flush(); assert.equal(f.headers[1].style.width, '188px');
    });
    return { passed: results.filter((r) => r.passed).length, failed: results.filter((r) => !r.passed).length, results };
}

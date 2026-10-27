// Tests the production scroll enhancer with deterministic DOM geometry.
// This is not a native browser or screenshot test.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

export function runAdminTableScrollTests() {
    const source = readFileSync(new URL('../../public/js/admin-table-scroll.js', import.meta.url), 'utf8');
    const css = readFileSync(new URL('../../public/css/admin-table-scroll.css', import.meta.url), 'utf8');
    const frames = [];
    const resizeCallbacks = [];
    const mutationCallbacks = [];
    const listeners = new Map();
    let containers = [];
    const window = { innerHeight: 800, visualViewport: { height: 800, addEventListener() {} },
        Livewire: { hook(name, callback) { listeners.set(name, callback); } },
        requestAnimationFrame(callback) { frames.push(callback); return frames.length; },
        addEventListener(name, callback) { listeners.set(name, callback); } };
    function flush() {
        let count = 0;
        while (frames.length) {
            assert.ok(++count < 20, 'The height adjustment must settle, not loop/shrink forever');
            frames.shift()();
        }
    }
    function style(onChange = () => undefined) {
        const values = new Map();
        return { getPropertyValue: key => values.get(key) || '', setProperty: (key, value) => { values.set(key, value); onChange(key); } };
    }
    function element() {
        const classes = new Set();
        const events = new Map();
        const el = { children: [], parentElement: null, dataset: {}, nodeType: 1, style: style(),
            setAttribute() {}, addEventListener: (name, callback) => events.set(name, callback), emit: name => events.get(name)?.(),
            classList: { add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name),
                toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name) },
            append(child) { child.remove(); this.children.push(child); child.parentElement = this; },
            remove() { if (this.parentElement) this.parentElement.children = this.parentElement.children.filter(child => child !== this); this.parentElement = null; },
            matches(selector) { return selector === '.' + this.className; },
            closest(selector) { for (let parent = this; parent; parent = parent.parentElement) if (parent.matches(selector)) return parent; return null; },
            get lastElementChild() { return this.children.at(-1); } };
        return el;
    }
    const body = element();
    const document = { body, createElement: element, querySelectorAll: () => containers,
        addEventListener(name, callback) { listeners.set(name, callback); } };
    function table(top = 200) {
        const host = element(); host.className = 'fi-ta-main'; body.append(host);
        const container = element(); container.top = top; container.naturalHeight = 2000;
        container.scrollWidth = 2400; container.clientWidth = 783; container.scrollLeft = 0;
        container.style = style(() => resizeCallbacks.forEach(callback => callback()));
        container.getBoundingClientRect = () => ({ top: container.top, bottom: container.top + container.clientHeight, height: container.clientHeight });
        Object.defineProperty(container, 'clientHeight', { get: () => Math.min(container.naturalHeight,
            parseInt(container.style.getPropertyValue('--admin-table-content-max-height')) || 640) });
        container.getClientRects = () => container.hidden ? [] : [container.getBoundingClientRect()];
        const head = { getBoundingClientRect: () => ({ height: 40 }) };
        container.querySelector = selector => selector.includes('thead') ? head : element();
        host.append(container);
        const footer = element(); footer.className = 'fi-pagination'; host.append(footer);
        host.getBoundingClientRect = () => ({ bottom: container.getBoundingClientRect().bottom + 60 +
            (host.lastElementChild?.classList.contains('is-visible') ? 20 : 0) });
        const oldAppend = host.append;
        host.append = function (bar) {
            oldAppend.call(this, bar);
            if (bar.className !== 'admin-table-scrollbar') return;
            bar.getBoundingClientRect = () => ({ top: container.getBoundingClientRect().bottom + 60,
                bottom: container.getBoundingClientRect().bottom + 80, height: 20 });
            bar.clientWidth = 800;
            Object.defineProperty(bar, 'scrollWidth', { configurable: true, get: () => parseInt(bar.children[0].style.getPropertyValue('width')) || 800 });
        };
        return { host, container, footer, bar: () => host.children.find(child => child.className === 'admin-table-scrollbar') };
    }
    const first = table(); containers = [first.container];
    vm.runInNewContext(source, { window, document,
        ResizeObserver: class { constructor(callback) { resizeCallbacks.push(callback); } observe() {} disconnect() {} },
        MutationObserver: class { constructor(callback) { mutationCallbacks.push(callback); } observe() {} } });
    flush();
    assert.equal(first.host.lastElementChild, first.bar());
    assert.equal(first.container.clientHeight, 512); // 800 - top200 - footer60 - bar20 - gap8.
    assert.ok(first.bar().getBoundingClientRect().bottom <= 800);
    assert.equal(first.bar().scrollWidth - first.bar().clientWidth, first.container.scrollWidth - first.container.clientWidth);
    first.bar().scrollLeft = 1200; first.bar().emit('scroll');
    assert.equal(first.container.scrollLeft, 1200);
    first.container.scrollLeft = 400; first.container.emit('scroll');
    assert.equal(first.bar().scrollLeft, 400);

    const initialHeight = first.container.clientHeight;
    const initialBarTop = first.bar().getBoundingClientRect().top;
    // At the inner list boundary the browser starts scrolling the outer page.
    // The old capture listener resized the list on each tick, cancelling movement.
    first.container.top -= 60;
    listeners.get('scroll')?.({ target: document });
    flush();
    assert.equal(first.container.clientHeight, initialHeight, 'Outer scrolling must not grow the inner list');
    assert.equal(first.bar().getBoundingClientRect().top, initialBarTop - 60, 'The table/footer/bar must follow the full outer scroll delta');
    first.container.top += 60;
    first.container.emit('scroll'); flush();
    assert.equal(first.container.clientHeight, initialHeight, 'Inner scrolling must not resize the list either');

    first.container.naturalHeight = 100; resizeCallbacks.forEach(callback => callback()); flush();
    assert.ok(first.bar().classList.contains('is-visible'), 'A short list must keep its bar, even when the native bottom is in view');
    assert.equal(first.bar().getBoundingClientRect().top, first.container.getBoundingClientRect().bottom + 60);
    first.container.naturalHeight = 2000;
    window.visualViewport.height = 600; listeners.get('resize')(); flush();
    assert.equal(first.container.clientHeight, 312);
    assert.ok(first.bar().getBoundingClientRect().bottom <= 600);

    const oldBar = first.bar();
    first.host.append(first.footer); // A Livewire redraw adds pagination after the injected bar.
    mutationCallbacks[0]([{ target: first.host, addedNodes: [first.footer], removedNodes: [] }]); flush();
    assert.equal(first.host.lastElementChild, oldBar);
    assert.equal(first.host.children.filter(child => child.className === 'admin-table-scrollbar').length, 1);
    delete first.container.dataset.adminTableScrollEnhanced;
    listeners.get('morphed')(); flush();
    assert.equal(first.container.dataset.adminTableScrollEnhanced, 'true');
    oldBar.remove();
    listeners.get('livewire:navigated')(); flush();
    assert.equal(first.bar(), oldBar, 'A morphed-away bar is restored, not duplicated');

    const second = table(150); containers.push(second.container);
    listeners.get('livewire:navigated')(); flush();
    second.bar().scrollLeft = 900; second.bar().emit('scroll');
    assert.equal(second.container.scrollLeft, 900);
    assert.equal(first.container.scrollLeft, 400);
    containers = [second.container]; listeners.get('livewire:navigated')(); flush();
    assert.equal(oldBar.parentElement, null, 'Removed tables must not leave orphan scrollbars');
    second.container.scrollWidth = 700; resizeCallbacks.forEach(callback => callback()); flush();
    assert.equal(second.bar().classList.contains('is-visible'), false);
    second.container.scrollWidth = 2800;
    second.container.clientWidth = 760;
    resizeCallbacks.forEach(callback => callback()); flush();
    assert.equal(second.bar().scrollWidth - second.bar().clientWidth, 2040);
    const modal = element(); modal.className = 'fi-modal-content';
    modal.getBoundingClientRect = () => ({ top: 100, bottom: 550 });
    body.append(modal); modal.append(second.host);
    resizeCallbacks.forEach(callback => callback()); flush();
    assert.ok(second.bar().getBoundingClientRect().bottom <= 550, 'Modal tables fit their scrolling viewport, not only the whole screen');
    second.container.hidden = true; resizeCallbacks.forEach(callback => callback()); flush();
    assert.equal(second.bar().classList.contains('is-visible'), false);
    second.container.hidden = false; resizeCallbacks.forEach(callback => callback()); flush();
    assert.equal(second.bar().classList.contains('is-visible'), true);
    assert.match(css, /\.admin-table-scrollbar\s*\{\s*position: relative;/);
    assert.match(css, /overflow-x: scroll;/);
    assert.match(css, /overscroll-behavior-y: auto;/);
    assert.doesNotMatch(source, /(?:document|window)\.addEventListener\(['"](?:scroll|wheel)['"]/,
        'Scroll handlers may synchronize the two bars but must not be installed on the outer document/window');
    assert.match(css, /\.fi-ta-content-ctn\[data-admin-table-scroll-enhanced="true"\]:has\(\.fi-ta-table > thead\)/,
        'Adaptive max-height must have higher specificity than the original sticky-table rule');
    assert.doesNotMatch(source, /nativeScrollbarIsVisible|position: fixed|floatingScrollbar/);
    return { status: 'passed', cases: 14, verification: 'deterministic DOM geometry, not a browser screenshot' };
}

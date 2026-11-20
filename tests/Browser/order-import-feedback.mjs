// Deterministic JavaScript handler checks; not a native-browser test.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const template = readFileSync(new URL('../../resources/views/livewire/partner/partials/order-import-feedback.blade.php', import.meta.url), 'utf8');
const handler = template.match(/x-on:order-import-feedback\.window="([^"]+)"/)[1];
for (const [componentId, reducedMotion, expectedBehavior] of [
    ['current', false, 'smooth'], ['current', true, 'auto'], ['other', false, null],
]) {
    const ticks = [];
    const frames = [];
    const scrolls = [];
    const context = {
        $event: { detail: { componentId } },
        $wire: { $id: 'current' },
        $nextTick: callback => ticks.push(callback),
        requestAnimationFrame: callback => frames.push(callback),
        window: { matchMedia: () => ({ matches: reducedMotion }) },
        $el: { scrollIntoView: options => scrolls.push({ ...options }) },
    };
    vm.runInNewContext(handler, context);
    assert.equal(scrolls.length, 0, 'Wait for Livewire/Alpine DOM updates before scrolling');
    ticks.forEach(callback => callback());
    assert.equal(scrolls.length, 0, 'Wait for the next animation frame');
    frames.forEach(callback => callback());
    assert.deepEqual(scrolls, expectedBehavior === null ? [] : [{ behavior: expectedBehavior, block: 'end' }]);
}
console.log('Order import feedback: component isolation, deferred scrolling and reduced motion passed.');

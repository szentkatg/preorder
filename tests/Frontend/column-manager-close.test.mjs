import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const root = new URL('../../', import.meta.url);
const configuration = await readFile(new URL('app/Filament/Support/AdminResourceTable.php', root), 'utf8');
const handler = configuration.match(/alpineClickHandler\('([^']+)'\)/)?.[1];
assert.ok(handler, 'The shared column manager must define an Apply handler.');
assert.equal(handler, 'await applyTableColumnManager(); if (! error) close()');
const { default: createManager } = await import(new URL('vendor/filament/tables/resources/js/components/column-manager.js', root).href);
const { default: createDropdown } = await import(new URL('vendor/filament/support/resources/js/components/dropdown.js', root).href);
// Match the verified handler without eval. Use the installed Filament manager
// and parent dropdown implementations, mocking only the server and DOM panel.
async function clickApply(scope) {
    await scope.applyTableColumnManager();
    if (!scope.error) scope.close();
}
const log = [];
const savedColumns = [];
const manager = createManager({ columns: [{ type: 'column', name: 'final_price', isToggled: true }], isLive: false });
manager.$watch = () => {};
const dropdown = createDropdown();
dropdown.$refs = { panel: { close: () => log.push('close') } };
Object.setPrototypeOf(manager, dropdown);
manager.init();
manager.deferredColumns[0].isToggled = false;
manager.hasReordered = true;
let finishSave;
manager.$wire = {
    call: async (method, columns, wasReordered) => {
        assert.equal(method, 'applyTableColumnManager');
        assert.equal(wasReordered, true);
        log.push('saving');
        await new Promise((resolve) => { finishSave = resolve; });
        savedColumns.push(structuredClone(columns));
        log.push('saved');
    },
};
const pendingApply = clickApply(manager);
assert.deepEqual(log, ['saving'], 'The dropdown must stay open while saving.');
finishSave();
await pendingApply;
assert.deepEqual(log, ['saving', 'saved', 'close']);
assert.equal(savedColumns[0][0].isToggled, false);
assert.equal(manager.hasReordered, false);

log.length = 0;
manager.$wire.call = async () => { log.push('saving'); throw new Error('Expected test failure'); };
const previousConsoleError = console.error;
try {
    console.error = () => {};
    await clickApply(manager);
} finally {
    console.error = previousConsoleError;
}
assert.deepEqual(log, ['saving'], 'The dropdown must stay open after a failed save.');
assert.equal(manager.error, 'Failed to update column visibility');
assert.equal(manager.isLoading, false);

log.length = 0;
manager.$wire.call = async () => { log.push('saved'); };
await clickApply(manager);
assert.deepEqual(log, ['saved', 'close'], 'A successful retry must close the dropdown.');
assert.equal(manager.error, undefined);
console.log('PASS: Apply waits for success, stays open on failure, and closes on successful retry.');

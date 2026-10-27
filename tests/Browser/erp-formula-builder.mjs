// Exercises the exact Alpine object in the Blade view, with a stubbed entanglement.
// This is a client-logic test, not a visual/live-browser test.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

export function runErpFormulaBuilderTests() {
    const view = readFileSync(new URL('../../resources/views/filament/pricing/erp-formula-builder.blade.php', import.meta.url), 'utf8');
    const attribute = view.match(/x-data="([\s\S]*?)">/)[1]
        .replace('@js($getStatePath())', JSON.stringify('mountedActions.0.data.formulas.czk_distributor'))
        .replace('@js($formulaLists)', JSON.stringify({ 1: 'DISTRIBUTOR_EUR (EUR)' }))
        .replace('@js($formulaCurrencies)', JSON.stringify({ 2: '1 CZK = 16 HUF' }))
        .replaceAll('&amp;', '&').replaceAll('&lt;', '<');
    let entangledPath;
    const state = vm.runInNewContext(`(${attribute})`, { $wire: { $entangle: path => { entangledPath = path; return null; } } });
    state.init();
    assert.equal(entangledPath, 'mountedActions.0.data.formulas.czk_distributor');
    assert.equal(state.tokens.length, 0);
    assert.equal(state.canAdd(), false);
    state.addValue();
    assert.equal(state.tokens.length, 0);
    state.list = '1';
    state.addValue();
    assert.equal(state.tokens[0].type, 'price_list');
    assert.equal(state.tokens[0].value, '1');
    assert.equal(state.label(state.tokens[0]), 'DISTRIBUTOR_EUR (EUR)');
    state.append('/');
    state.kind = 'exchange_rate'; state.currency = '2'; state.addValue();
    assert.equal(state.tokens[2].type, 'exchange_rate');
    assert.match(state.label(state.tokens[2]), /CZK/);
    assert.equal(state.label(state.tokens[1]), '÷');
    state.move(2, -1);
    assert.equal(state.tokens[1].type, 'exchange_rate');
    state.move(0, -1); state.move(2, 1);
    assert.equal(state.tokens[0].type, 'price_list');
    state.remove(1);
    assert.equal(state.tokens.length, 2);
    state.kind = 'number';
    for (const number of ['25', '1,27', '0.25', '-2', '.5', ' 12.00 ']) {
        state.number = number;
        assert.equal(state.canAdd(), true, number);
    }
    for (const number of ['', '1e4', 'Infinity', '1 000', '1,2.3', 'alert(1)', '1234567890123', '1.123456789']) {
        state.number = number;
        assert.equal(state.canAdd(), false, number);
    }
    state.number = ' 25 '; state.addValue();
    assert.equal(state.tokens[2].value, '25');
    for (const type of ['+', '-', '*', '/', '(', ')']) {
        state.append(type);
        assert.equal(state.tokens.at(-1).type, type);
    }
    while (state.tokens.length < 128) state.append('+');
    state.append('-');
    assert.equal(state.tokens.length, 128);
    assert.equal(state.canAdd(), false);
    assert.equal(view.includes('x-html'), false, 'Labels must never be treated as HTML');
    assert.equal(view.includes('$entangle(@js($getStatePath())).live'), false, 'Building tokens must not cause a server round-trip per click');
    return { cases: 8, status: 'passed', verification: 'Exact Alpine object; no visual browser verification' };
}

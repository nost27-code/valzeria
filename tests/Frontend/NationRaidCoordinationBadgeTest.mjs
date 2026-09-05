import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

// Bladeの実際のAlpine式を実行し、UI側に別の補正計算モデルを作らない。
const blade = readFileSync(new URL('../../resources/views/nation-raid/partials/coordination-badge.blade.php', import.meta.url), 'utf8');
const expression = blade.match(/x-data="([\s\S]*?)"\s+x-show/)[1];

test('badge follows server steps, expires without polling, and releases its timer', () => {
    const steps = [
        { after_ms: 0, active: true, label: '3人共闘・+6%連携ボーナス中！' },
        { after_ms: 60000, active: true, label: '2人共闘・+3%連携ボーナス中！' },
        { after_ms: 120000, active: false, label: '' },
    ];
    let clock = 500;
    let tick;
    const cleared = [];
    const state = runInNewContext(`(${expression.replace("@js($coordination['steps'])", JSON.stringify(steps))})`, {
        performance: { now: () => clock },
        setInterval: (callback, delay) => { assert.equal(delay, 1000); tick = callback; return 7; },
        clearInterval: id => cleared.push(id),
    });
    state.init();
    assert.equal(state.current.label, steps[0].label);
    clock += 59999;
    tick();
    assert.equal(state.current.label, steps[0].label);
    clock += 1;
    tick();
    assert.equal(state.current.label, steps[1].label);
    // A sleeping/backgrounded page must use elapsed time, not interval invocation count.
    clock += 600000;
    tick();
    assert.equal(state.current.active, false);
    assert.equal(state.current.label, '');
    assert.deepEqual(cleared, [7]);
    state.destroy();
    assert.deepEqual(cleared, [7, 7]);
});

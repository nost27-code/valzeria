import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const view = readFileSync(new URL('../../resources/views/battle/result.blade.php', import.meta.url), 'utf8');
const start = view.indexOf('async function submitExploreAgain(form)');
const end = view.indexOf('window.__valzeriaInitBattleResultTimers =', start);
const source = view.slice(start, end).replace("@json(route('battle.result'))", '"https://ffa.test/battle/result"');

function harness(fetchResponse, action = 'https://ffa.test/battle/areas/1/explore') {
    const requests = [];
    const navigations = [];
    const messages = [];
    const form = {
        action,
        dataset: {},
        querySelector: () => null,
        elements: { namedItem: () => ({ value: 'b5df98e0-5eab-4542-ab88-c6b948e3eae2' }) },
    };
    const context = vm.createContext({
        URL,
        FormData: class { constructor(value) { this.form = value; } },
        fetch: async (...args) => { requests.push(args); return fetchResponse(); },
        window: {
            location: { href: 'https://ffa.test/battle/result', assign: url => navigations.push(url) },
            setTimeout: callback => callback(),
        },
        batchExploreHpBlocked: () => false,
        batchExploreStaminaBlocked: () => false,
        exploreStaminaBlocked: () => false,
        setExploreFormStaminaState: () => {},
        showBattleToast: message => messages.push(message),
    });
    vm.runInContext(source, context);
    return { form, requests, navigations, messages, submit: () => context.submitExploreAgain(form) };
}

test('lost exploration response navigates to the same operation result without reposting', async () => {
    const app = harness(() => { throw new TypeError('Network failed'); });
    await app.submit();
    await app.submit();
    assert.equal(app.requests.length, 1);
    assert.equal(app.requests[0][1].method, 'POST');
    assert.deepEqual(app.navigations, ['https://ffa.test/battle/result?result=b5df98e0-5eab-4542-ab88-c6b948e3eae2']);
    assert.equal(app.messages.length, 0);
});

test('busy response unlocks the same form but never automatically posts again', async () => {
    const app = harness(() => ({ status: 409, redirected: false, headers: { get: key => key === 'X-Explore-Busy' ? '1' : '2' } }));
    await app.submit();
    assert.equal(app.requests.length, 1);
    assert.equal(app.form.dataset.submitted, '0');
    assert.equal(app.navigations.length, 0);
});

test('other exploration routes retain their existing recovery warning', async () => {
    const app = harness(() => { throw new TypeError('Network failed'); }, 'https://ffa.test/battle/sub-area-entries/1/explore');
    await app.submit();
    assert.equal(app.requests.length, 1);
    assert.equal(app.navigations.length, 0);
    assert.match(app.messages[0], /再実行せず/);
});

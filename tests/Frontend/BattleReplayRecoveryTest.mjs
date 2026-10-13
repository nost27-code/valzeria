import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/battle-replay.js', import.meta.url), 'utf8');
// A small DOM/timer harness exercises the shipped script without a browser or HTTP.
class Element {
    dataset = {}; children = []; text = ''; disabled = false; isConnected = true; selectors = new Map(); events = new Map();
    style = {setProperty() {}};
    classList = {values: new Set(), add(...names) {names.forEach(n => this.values.add(n));}, remove(...names) {names.forEach(n => this.values.delete(n));}};
    get childNodes() {return this.children;}
    get textContent() {return this.text + this.children.map(n => n.textContent).join('');}
    set textContent(value) {this.text = String(value); this.children = [];}
    append(...nodes) {this.children.push(...nodes);}
    replaceChildren(...nodes) {this.text = ''; this.children = nodes;}
    setAttribute() {}
    remove() {}
    querySelector(selector) {return this.selectors.get(selector) ?? null;}
    querySelectorAll() {return [];}
    addEventListener(type, callback, options = {}) {this.events.set(type, [...(this.events.get(type) ?? []), {callback, signal: options.signal}]);}
    fire(type = 'click') {for (const event of this.events.get(type) ?? []) if (!event.signal?.aborted) event.callback();}
}
const actor = hp => ({name: '確認用', hp, maxHp: 100, sp: 10, maxSp: 10, resources: [], badges: []});
const snapshot = hp => ({player: actor(hp), enemy: actor(100)});
const replay = () => ({version: 1, initial: snapshot(100), frames: [90, 60, 40].map(hp => ({turn: 1, actors: snapshot(hp), logs: [], action: {side: 'player', name: '通常攻撃', hits: []}}))});
function root(data = replay()) {
    const result = new Element(); result.scrolls = 0; result.scrollIntoView = () => result.scrolls++;
    const page = new Element(); page.selectors.set('[data-compact-accordion="result"]', result);
    const root = new Element(); root.matches = selector => selector === '[data-battle-replay]'; root.closest = () => page;
    const payload = new Element(); payload.textContent = typeof data === 'string' ? data : JSON.stringify(data);
    root.selectors.set('[data-replay-data]', payload);
    for (const name of ['play', 'previous', 'step', 'speed', 'result', 'effects', 'log', 'turn']) root.selectors.set(`[data-replay-${name}]`, new Element());
    root.querySelector('[data-replay-effects]').checked = true;
    for (const side of ['player', 'enemy']) {
        const fighter = new Element();
        for (const key of ['hp', 'sp']) {
            fighter.selectors.set(`[data-value="${key}"]`, new Element());
            fighter.selectors.set(`[data-meter="${key}"] span`, new Element());
        }
        fighter.selectors.set('[data-replay-resources]', new Element());
        fighter.selectors.set('[data-replay-badges]', new Element());
        root.selectors.set(`[data-side="${side}"]`, fighter);
    }
    root.querySelectorAll = selector => selector.startsWith('.br-controls')
        ? ['play', 'previous', 'step', 'speed', 'result', 'effects'].map(name => root.querySelector(`[data-replay-${name}]`)) : [];
    return {root, result, control: name => root.querySelector(`[data-replay-${name}]`)};
}
function harness(roots, {reducedMotion = true} = {}) {
    const timers = new Map(); let timerId = 0;
    const document = new Element(); document.hidden = false;
    document.querySelectorAll = () => roots.map(app => app.root);
    document.createElement = () => new Element(); document.createDocumentFragment = () => new Element();
    const motion = {matches: reducedMotion, addEventListener() {}, removeEventListener() {}};
    const context = vm.createContext({document, window: {}, AbortController, matchMedia: () => motion,
        localStorage: {getItem: () => '1.5', setItem() {}},
        setTimeout(callback, delay) {const id = ++timerId; timers.set(id, {callback, delay}); return id;},
        clearTimeout(id) {timers.delete(id);},
    });
    vm.runInContext(source, context);
    return {context, timers, runNext() {
        const [id, timer] = [...timers].sort((a, b) => a[1].delay - b[1].delay)[0]; timers.delete(id); timer.callback();
    }};
}

test('result button skips from the start, opens rewards, and rewind hides them again', () => {
    const app = root(); const env = harness([app]);
    assert.equal(app.root.dataset.replayComplete, '0');
    app.control('result').fire();
    assert.equal(app.root.dataset.replayCursor, 3);
    assert.equal(app.root.dataset.replayComplete, '1');
    assert.equal(app.root.querySelector('[data-side="player"]').querySelector('[data-value="hp"]').textContent, '40/100');
    assert.equal(app.result.open, true); assert.equal(app.result.scrolls, 1); assert.equal(env.timers.size, 0);
    app.control('previous').fire();
    assert.equal(app.root.dataset.replayCursor, 2); assert.equal(app.root.dataset.replayComplete, '0');
    assert.equal(app.control('result').disabled, false);
    assert.equal(app.control('speed').textContent, '1.5×');
});

test('skipping during playback cancels the next automatic step', () => {
    const app = root(); const env = harness([app]); app.control('play').fire();
    assert.equal(env.timers.size, 1);
    app.control('result').fire();
    assert.equal(env.timers.size, 0); assert.equal(app.root.dataset.replayComplete, '1');
    app.control('play').fire();
    assert.equal(app.root.dataset.replayCursor, 1); assert.equal(app.root.dataset.replayComplete, '0');
});

test('a long replay reaches the final snapshot directly without scheduling intermediate frames', () => {
    const data = replay(); data.frames = Array.from({length: 168}, () => data.frames[1]);
    const app = root(data); const env = harness([app]); app.control('result').fire();
    assert.equal(app.root.dataset.replayCursor, 168); assert.equal(app.root.dataset.replayComplete, '1');
    assert.equal(env.timers.size, 0);
    assert.equal(app.root.querySelector('[data-side="player"]').querySelector('[data-value="hp"]').textContent, '60/100');
});

test('bad JSON reveals the existing outcome without breaking another replay', () => {
    const broken = root('{'); const healthy = root(); harness([broken, healthy]);
    assert.equal(broken.root.dataset.replayUnavailable, '1');
    assert.match(broken.control('log').textContent, /戦闘ログと結果/);
    assert.equal(broken.control('play').disabled, true);
    healthy.control('step').fire(); assert.equal(healthy.root.dataset.replayCursor, 1);
});

test('an initialization DOM error aborts partial listeners and reveals the result', () => {
    const app = root(); app.root.querySelector('[data-side="player"]').selectors.delete('[data-value="hp"]');
    const env = harness([app]);
    assert.equal(app.root.dataset.replayUnavailable, '1'); assert.equal(app.root.dataset.replayReady, undefined);
    assert.equal(env.timers.size, 0); app.control('play').fire(); assert.equal(env.timers.size, 0);
});

test('a later frame error cancels playback and all registered controls', () => {
    const data = replay(); data.frames[1].actors.player = null;
    const app = root(data); const env = harness([app]); app.control('play').fire(); env.runNext();
    assert.equal(app.root.dataset.replayUnavailable, '1'); assert.equal(app.root.dataset.replayComplete, '1');
    assert.equal(env.timers.size, 0); assert.match(app.control('log').textContent, /再生に失敗/);
    app.control('previous').fire(); assert.equal(app.root.dataset.replayUnavailable, '1');
});

test('an effect timer error also clears pending effects and the playback timer', () => {
    const data = replay(); data.frames[0].action.hits = [{target: 'missing', kind: 'miss'}];
    const app = root(data); const env = harness([app], {reducedMotion: false}); app.control('play').fire(); env.runNext();
    assert.equal(app.root.dataset.replayUnavailable, '1'); assert.equal(env.timers.size, 0);
    assert.equal(app.root.querySelector('[data-side="player"]').classList.values.has('br-attacking'), false);
});

test('replacing a result destroys its timers and does not duplicate new listeners', () => {
    const old = root(); const apps = [old]; const env = harness(apps); old.control('play').fire();
    const next = root(); old.root.isConnected = false; apps.splice(0, 1, next);
    env.context.window.valzeriaBattleReplayBoot(next.root); env.context.window.valzeriaBattleReplayBoot(next.root);
    assert.equal(env.timers.size, 0); next.control('step').fire();
    assert.equal(next.root.dataset.replayCursor, 1); assert.equal(old.root.dataset.replayReady, undefined);
});

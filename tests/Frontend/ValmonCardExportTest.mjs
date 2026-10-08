import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8')
    .split('const submitLockButtonSelector')[0];

async function exportCard(badges) {
    const captions = [], images = [];
    let slots = 0;
    const context = new Proxy({}, { get: (_, name) => {
        if (name === 'fillText') return text => captions.push(text);
        if (name === 'createLinearGradient') return () => ({ addColorStop() {} });
        if (name === 'arc') return () => slots++;
        return () => {};
    } });
    const scope = vm.createContext({
        window: {},
        Image: class {
            width = 300;
            height = 300;
            set src(value) { images.push(value); this.onload(); }
        },
        document: { createElement: () => ({ getContext: () => context, toBlob: callback => callback('png') }) },
    });
    vm.runInContext(source, scope);
    const result = await scope.window.adventurerCardToBlob({ querySelector: () => null }, { valmon_badges: badges });
    return { result, captions, images, slots };
}

test('saved card shows the full owned count and includes a new rare partner beyond the first 14', async () => {
    const badges = Array.from({ length: 35 }, (_, no) => ({ owned: true, image: `valmon_${no + 1}.webp`, is_partner: no === 34 }));
    const before = badges.map(badge => badge.image);
    const result = await exportCard(badges);
    assert.equal(result.result, 'png');
    assert.ok(result.captions.includes('ヴァルモン  35/35'));
    assert.equal(result.slots, 14);
    assert.equal(result.images[0], 'valmon_35.webp');
    assert.equal(result.images.length, 14);
    assert.deepEqual(badges.map(badge => badge.image), before);
});

test('saved card handles undiscovered new species without loading their images', async () => {
    const result = await exportCard(Array.from({ length: 35 }, () => ({ owned: false, image: null })));
    assert.ok(result.captions.includes('ヴァルモン  0/35'));
    assert.equal(result.images.length, 0);
    assert.equal(result.slots, 0);
});

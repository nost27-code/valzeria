import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const html = readFileSync(new URL('../../public/tools/sprite-splitter.html', import.meta.url), 'utf8');
const start = html.indexOf('/* Whole-image conversion never');
const source = html.slice(start, html.indexOf('async function canvasToBlob(', start));

function harness(blob = { type: 'image/webp', size: 20 }) {
    const elements = new Map();
    const revoked = [];
    const drawCalls = [];
    const encodes = [];
    const context = { drawImage: (...args) => drawCalls.push(args) };
    const classNames = new Set();
    const canvas = {
        getContext: () => context,
        toBlob: (callback, mime, quality) => { encodes.push({ mime, quality }); callback(blob); },
    };
    const document = {
        createElement: () => canvas,
        getElementById(id) {
            if (!elements.has(id)) elements.set(id, {
                value: ({
                    'convert-scale': '100',
                    'convert-quality': '90',
                    'converter-prefix': 'image',
                    'converter-startnum': '1',
                })[id] || '',
                classList: { toggle(name, active) { active ? classNames.add(name) : classNames.delete(name); } },
                removeAttribute(name) { delete this[name]; },
                setAttribute(name, value) { this[name] = value; },
            });
            return elements.get(id);
        },
    };
    const api = runInNewContext(`${source}; ({ wholeImageDimensions, converterOutputName, encodeWholeImage, invalidateConversion, convertWholeImage,
        onConverterDragEnter, onConverterDragOver, onConverterDragLeave, onConverterDrop,
        setSource(value) { converterSource = value; converterSourceUrl = 'blob:source'; }
    })`, { document, URL: { createObjectURL: () => 'blob:output', revokeObjectURL: url => revoked.push(url) } });
    return { api, document, canvas, encodes, drawCalls, revoked, classNames };
}

test('the full converter screen accepts dropped image files with visible drag feedback', () => {
    assert.match(html, /converterDropSurface\.addEventListener\('dragenter', onConverterDragEnter\)/);
    assert.match(html, /converterDropSurface\.addEventListener\('drop', onConverterDrop\)/);
    assert.match(html, /この画面のどこへでもドロップ/);

    const { api, document, classNames } = harness();
    let prevented = 0;
    const dataTransfer = { types: ['Files'], files: [], dropEffect: 'none' };
    const event = { dataTransfer, preventDefault: () => prevented++ };

    api.onConverterDragEnter(event);
    assert.equal(classNames.has('is-dragging'), true);
    assert.equal(document.getElementById('convert-drop-message').textContent, 'ここに画像をドロップして追加（最大20枚）');

    api.onConverterDragOver(event);
    assert.equal(dataTransfer.dropEffect, 'copy');

    api.onConverterDrop(event);
    assert.equal(classNames.has('is-dragging'), false);
    assert.equal(document.getElementById('convert-info').textContent, 'PNG・JPEG・WebP画像をドロップしてください。');
    assert.equal(prevented, 3);
});

test('batch conversion accepts up to 20 files and names them from the requested number', () => {
    assert.match(html, /const CONVERTER_MAX_FILES = 20/);
    assert.match(html, /id="convert-file"[^>]*multiple/);
    assert.match(html, /id="converter-prefix"/);
    assert.match(html, /id="converter-startnum"/);
    assert.match(html, /function saveConverterBatchToDirectory\(/);
    assert.match(html, /findNextFreeName\(converterSaveDirHandle, config\.prefix, cursor\)/);

    const { api } = harness();
    const config = { prefix: 'enemy', startNum: 491 };
    assert.equal(api.converterOutputName(0, config), 'enemy_491.webp');
    assert.equal(api.converterOutputName(19, config), 'enemy_510.webp');
});

test('wide and tall images keep their full dimensions with proportional resizing', () => {
    const { api } = harness();
    for (const [width, height, percent, expectedWidth, expectedHeight] of [
        [1896, 829, 100, 1896, 829], [1896, 829, 50, 948, 415],
        [829, 1896, 25, 207, 474], [1, 10, 1, 1, 1],
    ]) {
        const size = api.wholeImageDimensions(width, height, percent);
        assert.equal(size.width, expectedWidth);
        assert.equal(size.height, expectedHeight);
    }
    for (const value of [0, -1, 101, NaN, Infinity]) {
        assert.throws(() => api.wholeImageDimensions(100, 50, value), /1〜100%/);
    }
});

test('encoding draws the full image edge to edge and passes the selected WebP quality', async () => {
    const { api, canvas, drawCalls, encodes } = harness();
    const img = { naturalWidth: 1896, naturalHeight: 829 };
    const output = await api.encodeWholeImage(img, 50, 72);
    assert.equal(canvas.width, 948);
    assert.equal(canvas.height, 415);
    assert.deepEqual(drawCalls, [[img, 0, 0, 948, 415]]);
    assert.deepEqual(encodes, [{ mime: 'image/webp', quality: 0.72 }]);
    assert.equal(output.blob.type, 'image/webp');
});

test('unsupported WebP encoding and null output cannot be saved as mislabeled PNG', async () => {
    for (const blob of [null, { type: 'image/png' }]) {
        const { api } = harness(blob);
        await assert.rejects(api.encodeWholeImage({ naturalWidth: 80, naturalHeight: 40 }, 100, 90), /WebPに変換できません/);
    }
});

test('changing a setting removes the previous download and invalid sizes disable conversion', async () => {
    const { api, document, revoked } = harness();
    api.setSource({ img: { naturalWidth: 80, naturalHeight: 40 }, file: { name: 'forest.scene.png', size: 100 } });
    await api.convertWholeImage();
    const save = document.getElementById('convert-save');
    assert.equal(save.download, 'image_001.webp');
    assert.equal(save.hidden, false);
    document.getElementById('convert-scale').value = '';
    api.invalidateConversion();
    assert.equal(save.hidden, true);
    assert.equal(save.href, undefined);
    assert.equal(document.getElementById('convert-run').disabled, true);
    assert.deepEqual(revoked, ['blob:output']);
});

test('a conversion finishing after a setting change cannot publish stale output', async () => {
    const { api, canvas, document } = harness();
    let finish;
    canvas.toBlob = callback => { finish = callback; };
    api.setSource({ img: { naturalWidth: 80, naturalHeight: 40 }, file: { name: 'a.png', size: 100 } });
    const pending = api.convertWholeImage();
    document.getElementById('convert-scale').value = '50';
    api.invalidateConversion();
    finish({ type: 'image/webp', size: 20 });
    await pending;
    assert.equal(document.getElementById('convert-save').hidden, true);
    assert.equal(document.getElementById('convert-result').textContent, '出力予定：40 × 20 px');
});

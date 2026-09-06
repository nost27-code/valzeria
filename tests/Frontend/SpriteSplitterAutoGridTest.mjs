import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const html = readFileSync(new URL('../../public/tools/sprite-splitter.html', import.meta.url), 'utf8');

function extractFunction(name) {
    const start = html.indexOf(`function ${name}(`);
    assert.notEqual(start, -1, `${name} must exist in sprite-splitter.html`);

    const bodyStart = html.indexOf('{', start);
    let depth = 0;
    for (let index = bodyStart; index < html.length; index++) {
        if (html[index] === '{') depth++;
        if (html[index] === '}') depth--;
        if (depth === 0) return html.slice(start, index + 1);
    }

    throw new Error(`${name} has an unterminated function body`);
}

const alphaConstant = html.match(/const AUTO_DETECT_ALPHA_MIN\s*=\s*\d+;/)?.[0];
assert.ok(alphaConstant, 'AUTO_DETECT_ALPHA_MIN must exist in sprite-splitter.html');

const detectorSource = alphaConstant + '\n' + [
    'isAutoDetectForeground',
    'spriteMedian',
    'clusterSpriteAxis',
    'gridPrimaryCandidates',
    'nearestSpriteAxisGroup',
    'inferSpriteGrid',
    'spriteGridRanges',
    'detectGridSprites',
    'detectAutoSprites',
    'mergeRects',
    'rectDist',
    'unionRect',
    'sortRects',
].map(extractFunction).join('\n');

function paintRect(data, width, x, y, rectWidth, rectHeight, alpha = 255) {
    for (let py = y; py < y + rectHeight; py++) {
        for (let px = x; px < x + rectWidth; px++) {
            const offset = (py * width + px) * 4;
            data[offset] = 24;
            data[offset + 1] = 24;
            data[offset + 2] = 24;
            data[offset + 3] = alpha;
        }
    }
}

test('one-click detection keeps a dense transparent 5 by 4 sheet as 20 sprites', () => {
    const width = 500;
    const height = 400;
    const data = new Uint8ClampedArray(width * height * 4);

    for (let row = 0; row < 4; row++) {
        for (let column = 0; column < 5; column++) {
            paintRect(data, width, column * 100 + 5, row * 100 + 5, 90, 90);
        }
    }

    // ChatGPT透過画像に残る、ごく薄い半透明の接続画素を再現する。
    for (let row = 0; row < 4; row++) {
        for (let column = 0; column < 4; column++) {
            paintRect(data, width, column * 100 + 95, row * 100 + 45, 10, 1, 8);
        }
    }
    for (let row = 0; row < 3; row++) {
        paintRect(data, width, 45, row * 100 + 95, 1, 10, 8);
    }

    // 本体から離れた小さな装飾も同じセルへ含める。
    paintRect(data, width, 1, 1, 2, 2);

    const canvas = {
        width,
        height,
        getContext: () => ({ getImageData: () => ({ data }) }),
    };
    const cfg = { white: 245, area: 50, merge: 12, pad: 0, size: 96 };
    const rects = runInNewContext(`${detectorSource}; detectAutoSprites(canvas, cfg)`, { canvas, cfg });

    assert.equal(rects.length, 20);
    assert.equal(rects[0].x, 1);
});

test('irregular nearby parts retain the existing proximity merge fallback', () => {
    const width = 120;
    const height = 80;
    const data = new Uint8ClampedArray(width * height * 4);
    paintRect(data, width, 10, 10, 25, 40);
    paintRect(data, width, 40, 20, 10, 10);

    const canvas = {
        width,
        height,
        getContext: () => ({ getImageData: () => ({ data }) }),
    };
    const cfg = { white: 245, area: 20, merge: 12, pad: 0, size: 96 };
    const rects = runInNewContext(`${detectorSource}; detectAutoSprites(canvas, cfg)`, { canvas, cfg });

    assert.equal(rects.length, 1);
});

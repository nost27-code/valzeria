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

function normalize(bounds, cfg) {
    const document = {
        createElement() {
            const drawCalls = [];
            return {
                width: 0,
                height: 0,
                drawCalls,
                getContext: () => ({
                    clearRect() {},
                    drawImage: (...args) => drawCalls.push(args),
                    imageSmoothingEnabled: true,
                }),
            };
        },
    };
    const source = { width: 400, height: 400 };
    const rect = { x: 0, y: 0, w: 300, h: 300 };
    return runInNewContext(
        `${extractFunction('normalizeSprite')}; normalizeSprite(source, rect, cfg)`,
        {
            document,
            source,
            rect,
            cfg,
            removeBackground() {},
            nonTransparentBounds: () => bounds,
        },
    );
}

test('output size selector offers a height-only automatic-width mode', () => {
    assert.match(html, /<option value="auto">高さのみ指定（横幅不問）<\/option>/);
    assert.match(html, /id="s-auto-height"[^>]+min="1"[^>]+max="2000"/);
});

test('automatic-width mode gives wide and narrow sprites the same requested height', () => {
    const wide = normalize(
        { minX: 0, minY: 0, maxX: 199, maxY: 99 },
        { size: 100, baseline: 0, autoWidth: true, scaleUp: false },
    );
    const narrow = normalize(
        { minX: 0, minY: 0, maxX: 49, maxY: 99 },
        { size: 100, baseline: 0, autoWidth: true, scaleUp: false },
    );
    const enlarged = normalize(
        { minX: 0, minY: 0, maxX: 24, maxY: 49 },
        { size: 100, baseline: 0, autoWidth: true, scaleUp: false },
    );

    assert.deepEqual([wide.width, wide.height], [200, 100]);
    assert.deepEqual([narrow.width, narrow.height], [50, 100]);
    assert.deepEqual([enlarged.width, enlarged.height], [50, 100]);
});

test('square mode keeps the existing fit-within-square behavior', () => {
    const result = normalize(
        { minX: 0, minY: 0, maxX: 199, maxY: 99 },
        { size: 100, baseline: 0, autoWidth: false, scaleUp: false },
    );

    assert.deepEqual([result.width, result.height], [100, 100]);
    assert.deepEqual(result.drawCalls.at(-1).slice(-4), [0, 50, 100, 50]);
});

test('preview and sheet layout preserve variable sprite widths', () => {
    const previewDimensions = runInNewContext(`(${extractFunction('previewDimensions')})`);
    const spriteSheetLayout = runInNewContext(`(${extractFunction('spriteSheetLayout')})`);

    assert.deepEqual(
        { ...previewDimensions({ width: 250, height: 500 }) },
        { width: 48, height: 96 },
    );
    assert.deepEqual(
        { ...spriteSheetLayout([{ width: 80 }, { width: 140 }, { width: 100 }], { autoWidth: true, size: 96, cols: '2' }) },
        { cols: 2, rows: 2, cellWidth: 140, cellHeight: 96 },
    );
});

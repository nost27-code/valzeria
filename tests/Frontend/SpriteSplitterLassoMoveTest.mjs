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

test('lasso controls and editable source canvas are wired into the tool', () => {
    assert.match(html, /id="btn-lasso-mode"/);
    assert.match(html, /id="btn-lasso-undo"/);
    assert.match(html, /@media \(max-width: 768px\)/);
    assert.match(html, /workingCanvas:\s*createWorkingCanvas\(img\)/);
    assert.match(extractFunction('sourceCanvasForSlot'), /slot\.workingCanvas/);
    assert.match(extractFunction('buildLassoSelection'), /maskContext\.fill\('evenodd'\)/);
    assert.match(extractFunction('buildLassoSelection'), /maskData\.data\[alphaOffset\] >= 128 \? 255 : 0/);
    assert.match(extractFunction('buildLassoSelection'), /globalCompositeOperation = 'destination-in'/);
    assert.match(extractFunction('commitLassoMove'), /workingContext\.drawImage\(selection\.contentCanvas, targetX, targetY\)/);
    assert.match(extractFunction('commitLassoMove'), /slot\.separatorStrokes = \[\]/);
    assert.match(extractFunction('undoLassoMove'), /history\.patches\.forEach/);
    assert.match(extractFunction('undoLassoMove'), /slot\.separatorStrokes = history\.separatorStrokes/);
    assert.match(html, /addEventListener\('pointermove', onLassoPointerMove\)/);
    assert.match(html, /addEventListener\('lostpointercapture'/);
});

test('the complete inline script remains valid JavaScript', () => {
    const scriptStart = html.lastIndexOf('<script>');
    const scriptEnd = html.indexOf('</script>', scriptStart);
    assert.notEqual(scriptStart, -1);
    assert.notEqual(scriptEnd, -1);
    assert.doesNotThrow(() => new Function(html.slice(scriptStart + '<script>'.length, scriptEnd)));
});

test('freehand polygon includes its interior and excludes nearby characters', () => {
    const source = extractFunction('pointInPolygon');
    const polygon = [{ x: 10, y: 10 }, { x: 40, y: 10 }, { x: 35, y: 45 }, { x: 8, y: 35 }];

    assert.equal(runInNewContext(`${source}; pointInPolygon({ x: 20, y: 20 }, polygon)`, { polygon }), true);
    assert.equal(runInNewContext(`${source}; pointInPolygon({ x: 45, y: 20 }, polygon)`, { polygon }), false);
    assert.equal(runInNewContext(`${source}; pointInPolygon({ x: 10, y: 10 }, polygon)`, { polygon }), true);
});

test('lasso bounds and movement stay inside the source image', () => {
    const source = [extractFunction('lassoBoundingRect'), extractFunction('clampLassoOffset')].join('\n');
    const points = [{ x: -4, y: 5 }, { x: 24, y: 5 }, { x: 24, y: 38 }, { x: -4, y: 38 }];
    const bounds = runInNewContext(`${source}; lassoBoundingRect(points, 100, 80)`, { points });

    assert.equal(bounds.x, 0);
    assert.equal(bounds.y, 5);
    assert.equal(bounds.w, 25);
    assert.equal(bounds.h, 34);

    const offset = runInNewContext(`${source}; clampLassoOffset(bounds, 200, -20, 100, 80)`, { bounds });
    assert.equal(offset.dx, 75);
    assert.equal(offset.dy, -5);
});

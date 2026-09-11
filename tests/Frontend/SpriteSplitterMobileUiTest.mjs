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

test('mobile layout uses dedicated editor, settings, and output views', () => {
    assert.match(html, /<body data-mobile-view="editor">/);
    assert.match(html, /id="mobile-tabbar"[^>]*aria-label="作業画面"/);
    assert.match(html, /data-mobile-view="editor"[^>]*aria-controls="left-panel"/);
    assert.match(html, /data-mobile-view="settings"[^>]*aria-controls="right-panel"/);
    assert.match(html, /data-mobile-view="output"[^>]*aria-controls="bottom-panel"/);
    assert.match(html, /body\[data-mobile-view="editor"\] #left-panel \{ display: flex; \}/);
    assert.match(html, /body\[data-mobile-view="settings"\] #right-panel \{ display: block; \}/);
    assert.match(html, /body\[data-mobile-view="output"\] #bottom-panel \{ display: flex; \}/);
});

test('mobile controls prioritize touch operation and safe viewport space', () => {
    assert.match(html, /viewport-fit=cover/);
    assert.match(html, /height: calc\(100dvh - var\(--mobile-header-height\) - var\(--mobile-tabbar-height\)\)/);
    assert.match(html, /env\(safe-area-inset-bottom\)/);
    assert.match(html, /#toolbar \{[\s\S]*?overflow-x: auto;/);
    assert.match(html, /\.btn \{ min-height: 44px; \}/);
    assert.match(html, /#mobile-export-actions \.btn \{ width: 100%; min-height: 48px; \}/);
    assert.match(html, /#toolbar #btn-zip,[\s\S]*?#toolbar #btn-sheet \{ display: none; \}/);
});

test('mobile view state updates the visible label and selected tab', () => {
    const tabs = [
        { dataset: { mobileView: 'editor' }, selected: null, classList: { contains: () => true }, setAttribute: (_, value) => { tabs[0].selected = value; } },
        { dataset: { mobileView: 'settings' }, selected: null, classList: { contains: () => true }, setAttribute: (_, value) => { tabs[1].selected = value; } },
        { dataset: { mobileView: 'output' }, selected: null, classList: { contains: () => true }, setAttribute: (_, value) => { tabs[2].selected = value; } },
    ];
    const label = { textContent: '' };
    const document = {
        body: { dataset: {} },
        getElementById: id => id === 'mobile-view-label' ? label : null,
        querySelectorAll: () => tabs,
    };
    const context = { document, mobileView: 'editor' };

    runInNewContext(`${extractFunction('setMobileView')}; setMobileView('settings')`, context);

    assert.equal(context.mobileView, 'settings');
    assert.equal(document.body.dataset.mobileView, 'settings');
    assert.equal(label.textContent, '設定');
    assert.deepEqual(tabs.map(tab => tab.selected), ['false', 'true', 'false']);
});

test('preview completion moves mobile users to the output view', () => {
    const source = extractFunction('runPreviewAll');
    assert.match(source, /if \(total > 0 && isMobileLayout\(\)\) setMobileView\('output'\)/);
    assert.match(extractFunction('syncMobileActionButtons'), /\['btn-savedir', 'mobile-btn-savedir'\]/);
    assert.match(extractFunction('updateExportButtons'), /syncMobileActionButtons\(\)/);
});

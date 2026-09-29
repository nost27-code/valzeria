import test from 'node:test';
import assert from 'node:assert/strict';
import { drawAdventurer } from '../../public/js/field/sprites.js';
import { FieldRenderer } from '../../public/js/field/renderer.js';
import { FieldAmbient } from '../../public/js/field/ambient.js';

function fixture() {
    const draws = [], labels = [], shadows = [], stack = [];
    const ctx = new Proxy({
        imageSmoothingEnabled: true,
        globalAlpha: 1,
        drawImage: (...args) => draws.push(args),
        fillText: (...args) => labels.push({ args, color: ctx.fillStyle }),
        ellipse: (...args) => shadows.push(args),
        save: () => stack.push({ smoothing: ctx.imageSmoothingEnabled, alpha: ctx.globalAlpha }),
        restore: () => { const state = stack.pop(); ctx.imageSmoothingEnabled = state.smoothing; ctx.globalAlpha = state.alpha; },
    }, { get: (target, key) => target[key] ?? (() => {}) });
    return { ctx, draws, labels, shadows, canvas: { width: 640, height: 480, getContext: () => ctx } };
}
const images = new Map([0, 1].map(id => [`adventurer_base_${id}`, { id, width: 192, height: 256 }]));

test('selected icon takes priority for self and others, bobs only while moving and retains horizontal facing', () => {
    for (const self of [true,false]) {
        const {ctx,draws}=fixture(), transforms=[], flips=[];
        ctx.translate=(...v)=>transforms.push(v);
        ctx.scale=(...v)=>flips.push(v);
        const icon={width:160,height:240};
        const assets=new Map([...images,['player-icon:/chosen.webp',icon]]);
        const p={id:1,icon:'/chosen.webp',name:'旅人',x:100,y:200,facing:2,moving:true};
        drawAdventurer(ctx,p,Math.PI/20,self,assets);
        assert.equal(draws[0][0],icon);
        assert.deepEqual(transforms[0],[100,198]);
        assert.deepEqual(flips,[[-1,1]]);
        p.facing=3;p.moving=false;
        drawAdventurer(ctx,p,Math.PI/20,self,assets);
        assert.deepEqual(transforms[1],[100,200]);
        assert.equal(flips.length,2);
        p.facing=1;
        drawAdventurer(ctx,p,0,self,assets);
        assert.equal(flips.length,2);
        assert.equal(draws[0][4],56);
        assert.equal(p.y,200);
    }
});

test('icon load failure retains the existing adventurer fallback', () => {
    const {ctx,draws}=fixture();
    drawAdventurer(ctx,{id:0,icon:'/missing.webp',x:0,y:0,facing:0},0,true,images);
    assert.equal(draws[0][0],images.get('adventurer_base_0'));
});

test('every direction uses its row, stands on the existing foot coordinate and stops on the middle frame', () => {
    for (const id of [0, 1, 18, 19]) for (let facing = 0; facing < 4; facing++) {
        const { ctx, draws, labels, shadows } = fixture();
        const p = { id, facing, x: 101.2, y: 220.4, moving: false, name: '旅人' };
        const before = { ...p };
        drawAdventurer(ctx, p, 20, id % 2 === 0, images);
        assert.deepEqual(draws, [[images.get(`adventurer_base_${id % 2}`), 64, facing * 64, 64, 64, 77, 173.5, 48, 48]]);
        assert.equal(draws[0][6] + 62 * 48 / 64, 220);
        assert.deepEqual(labels, [{ args: ['旅人', 101, 171], color: id % 2 === 0 ? '#fff4a0' : '#ffffff' }]);
        assert.equal(shadows.length, 1);
        assert.equal(ctx.imageSmoothingEnabled, true);
        assert.deepEqual(p, before);
    }
});

test('NPC roles choose dedicated rows and frames, retain proximity labels and do not overlay the old guard equipment', () => {
    const ambient = new FieldAmbient(null, null);
    const assets = new Map([...images, ...['guard', 'merchant', 'villager'].map(role => [`npc_${role}`, { role, width: 192, height: 256 }])]);
    for (const role of ['guard', 'merchant', 'villager']) for (let facing = 0; facing < 4; facing++) for (const near of [false, true]) {
        const { ctx, draws, labels } = fixture();
        let rects = 0;
        ctx.fillRect = () => rects++;
        const p = { id: 19, x: 50, y: 100, role, name: { guard: '衛兵', merchant: '商人', villager: '町の人' }[role], facing, moving: true };
        const before = { ...p };
        ambient.drawPerson(ctx, p, 2.1 / 9, near, assets);
        assert.equal(draws.length, 1);
        assert.deepEqual(draws[0].slice(0, 5), [assets.get(`npc_${role}`), 128, facing * 64, 64, 64]);
        assert.equal(rects, 0);
        assert.equal(labels[0].args[0], near ? p.name : '');
        assert.equal(labels[0].color, '#ffffff');
        assert.deepEqual(p, before);
    }
});

test('missing or malformed NPC sheet preserves guard equipment and never borrows an adventurer sheet', () => {
    const ambient = new FieldAmbient(null, null);
    for (const sheet of [undefined, { width: 191, height: 256 }]) {
        const { ctx, draws } = fixture();
        const rects = [];
        ctx.fillRect = (...args) => rects.push(args);
        ambient.drawPerson(ctx, { id: 0, x: 50, y: 100, role: 'guard', name: '衛兵', facing: 0 }, 0, true, new Map([...images, ['npc_guard', sheet]]));
        assert.equal(draws.length, 0);
        assert.deepEqual(rects.slice(-2), [[42, 56, 16, 6], [62, 50, 2, 50]]);
    }
});

test('unmade ghost role keeps translucency and restores the surrounding canvas opacity', () => {
    const ambient = new FieldAmbient(null, null);
    const { ctx, draws } = fixture();
    const opacities = [];
    ctx.globalAlpha = 0.8;
    ctx.fillRect = () => opacities.push(ctx.globalAlpha);
    ambient.drawPerson(ctx, { id: 3, x: 0, y: 0, role: 'ghost', ghost: true, name: '???' }, 0, true, images);
    assert.equal(draws.length, 0);
    assert.ok(opacities.length > 0 && opacities.every(alpha => alpha === 0.6));
    assert.equal(ctx.globalAlpha, 0.8);
});

test('renderer passes loaded NPC images through ambient sprites while a stationary guard still turns toward the player', () => {
    const { canvas, draws } = fixture();
    const ambient = new FieldAmbient(null, null);
    const guard = { id: 0, role: 'guard', name: '衛兵', x: 50, y: 50, still: true, moving: false, facing: 0 };
    const player = { id: 1, x: 80, y: 50, facing: 0, name: '冒険者', plane: 'surface' };
    ambient.townsfolk.set(1, [guard]);
    ambient.walk(guard, 0.1, player);
    assert.equal(guard.facing, 2);
    assert.equal(guard.moving, false);
    const renderer = new FieldRenderer(canvas, { objectsInRect: () => [] });
    renderer.block = () => ({});
    const sheet = { width: 192, height: 256 };
    renderer.images.set('npc_guard', sheet);
    renderer.render({ x: 0, y: 0 }, player, [], 10, { sprites: ambient.sprites(player, 10) });
    assert.deepEqual(draws.find(draw => draw[0] === sheet).slice(1, 5), [64, 128, 64, 64]);
    assert.equal(ambient.talkable(player), guard);
});

test('walking alternates both contact frames with idle and stops immediately on release', () => {
    const { ctx, draws } = fixture();
    const p = { id: 0, x: 0, y: 0, facing: 2, moving: true, name: '' };
    for (let phase = 0; phase < 8; phase++) drawAdventurer(ctx, p, (phase + 0.1) / 9, true, images);
    assert.deepEqual(draws.map(draw => draw[1]), [0, 64, 128, 64, 0, 64, 128, 64]);
    drawAdventurer(ctx, { ...p, moving: false }, 0, true, images);
    assert.equal(draws.at(-1)[1], 64);
});

test('missing or malformed sheets keep procedural bodies and labels, including NPC callers', () => {
    for (const assets of [undefined, new Map(), new Map([['adventurer_base_0', { width: 192, height: 255 }]]), new Map([['adventurer_base_1', { width: 192, height: 256 }]])]) {
        const { ctx, draws, labels } = fixture();
        let bodyRects = 0;
        ctx.fillRect = () => bodyRects++;
        drawAdventurer(ctx, { id: 0, x: 20, y: 50, facing: 3, name: '衛兵' }, 0, false, assets);
        assert.equal(draws.length, 0);
        assert.ok(bodyRects >= 7);
        assert.deepEqual(labels, [{ args: ['衛兵', 20, 6], color: '#ffffff' }]);
    }
});

test('renderer supplies sheets to self and remote players in the existing depth order', () => {
    const { canvas, ctx, draws } = fixture();
    const world = { objectsInRect: () => [] };
    const renderer = new FieldRenderer(canvas, world);
    renderer.block = () => ({});
    renderer.images = images;
    const player = { id: 1, x: 20, y: 50, facing: 3, moving: false, name: '自分' };
    const remote = { id: 0, x: 20, y: 40, facing: 1, moving: true, name: '相手' };
    let extraDrawn = false;
    renderer.render({ x: 0, y: 0 }, player, [remote], 0, { sprites: [{ y: 30, draw: context => { assert.equal(context, ctx); extraDrawn = true; } }] });
    assert.deepEqual(draws.filter(draw => draw.length === 9).map(draw => [draw[0].id, draw[1], draw[2]]), [[0, 0, 64], [1, 64, 192]]);
    assert.equal(extraDrawn, true);
});

test('renderer does not draw a hidden spectator while still drawing field players', () => {
    const { canvas, draws } = fixture();
    const renderer = new FieldRenderer(canvas, { objectsInRect: () => [] });
    renderer.block = () => ({});
    renderer.images = images;
    const spectator = { id: 0, x: 20, y: 50, facing: 0, moving: false, name: '管理者観察', hidden: true };
    const remote = { id: 1, x: 20, y: 40, facing: 1, moving: false, name: '冒険者' };

    renderer.render({ x: 0, y: 0 }, spectator, [remote], 0, { sprites: [] });

    assert.deepEqual(draws.filter(draw => draw.length === 9).map(draw => draw[0].id), [1]);
});

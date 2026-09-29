// フィールドの描画：地形（16x16 マスの塊をキャッシュ）→ 平らな物 → 奥行き順の物と冒険者。
// ミニマップと世界地図もここで描く。

import { BIOME, BLOCK, TILE, TILES, isSolidTile } from './constants.js';
import { mapFrame } from './map-camera.js';
import { drawElevation, drawTerrainTile } from './tiles-draw.js';
import { createTerrainOverlays } from './terrain-art.js';
import { assetKeysFor, drawAdventurer, drawObject } from './sprites.js';
import { inBuildingApproach } from './object-footprint.js';
import { preparePlayerIcon } from './player-icon.js';

const BLOCK_CACHE_MIN = 64; // 描いておく塊の数（引いた視点では画面に映る数の2倍まで増やす）
const FAR_SCALE = 0.4; // 1マスが画面でこれより小さく（約13px 未満に）なったら簡易描画
const FAR_PX = 8;
const FAR_TREES = new Set(['T', 'U', 't', 'I', 'P', 'Z', 'C']);

function drawFarTile(ctx, world, tx, ty, px, py) {
    const tile = world.tileAt(tx, ty);
    const level = world.levelAt(tx, ty);
    ctx.fillStyle = TILES[tile]?.color ?? '#000';
    ctx.fillRect(px, py, FAR_PX, FAR_PX);
    if (FAR_TREES.has(tile)) {
        ctx.fillStyle = 'rgba(10,40,10,0.45)';
        ctx.fillRect(px + 2, py + 1, FAR_PX - 3, FAR_PX - 3);
    } else if (isSolidTile(tile) && tile !== 'O' && tile !== 'W' && tile !== 'z') {
        ctx.fillStyle = 'rgba(0,0,0,0.18)';
        ctx.fillRect(px, py + FAR_PX - 3, FAR_PX, 3);
    }
    if (level > 0 && tile !== 'O' && tile !== 'W') {
        ctx.fillStyle = `rgba(255,255,240,${Math.min(0.14, level * 0.018)})`;
        ctx.fillRect(px, py, FAR_PX, FAR_PX);
        if (world.levelAt(tx, ty + 1) < level) {
            ctx.fillStyle = 'rgba(60,40,20,0.6)';
            ctx.fillRect(px, py + FAR_PX - 3, FAR_PX, 3);
        }
    }
}

// 最初から読む素材：歩行グラフィック（小さい）と、縦長の名所（上部のはみ出しを測るため）
const EAGER_ASSETS = /^(adventurer_|npc_|castle|demon_castle|world_tree|magic_tower|cathedral|lighthouse|sky_tower|sky_temple|watchtower|windmill|furnace|academy_hall|gate_tower|wall_tower)/;

// 画像を、最初に get された時に読み込む（Map と同じく get / has / size を持つ）
class LazyImages {
    constructor(onLoad) {
        this.onLoad = onLoad;
        this.loaded = new Map();
        this.requested = new Set();
        this.available = new Set();
        this.baseUrl = '';
        this.versions = {};
    }

    setAvailable(keys, baseUrl, versions = {}) {
        this.available = new Set(keys);
        this.baseUrl = baseUrl;
        this.versions = versions;
    }

    // 読み込み済みの画像を直接置く（テストや先読み用）
    set(key, img) {
        this.available.add(key);
        this.requested.add(key);
        this.loaded.set(key, img);
        return this;
    }

    // 素材があるか（読み込み前でも true）
    has(key) {
        return this.available.has(key);
    }

    get size() {
        return this.available.size;
    }

    get(key) {
        const img = this.loaded.get(key);
        if (key.startsWith('player-icon:')) {
            if (img || this.requested.has(key)) return img;
            this.requested.add(key);
            const image = new Image();
            image.decoding = 'async';
            image.onload = () => this.loaded.set(key, preparePlayerIcon(image, key.slice('player-icon:'.length)));
            image.src = key.slice('player-icon:'.length);
            return undefined;
        }
        if (img || !this.available.has(key) || this.requested.has(key)) return img;
        this.requested.add(key);
        const image = new Image();
        image.decoding = 'async';
        image.onload = () => {
            this.loaded.set(key, image);
            this.onLoad(key, image);
        };
        const version = this.versions[key];
        image.src = `${this.baseUrl}/${key}.webp${version === undefined ? '' : `?v=${encodeURIComponent(version)}`}`;
        return undefined;
    }
}

const FLAT = new Set(['magic_circle', 'flowerbed', 'teleporter', 'fountain']);

export class FieldRenderer {
    constructor(canvas, world) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.world = world;
        this.blocks = new Map();
        this.images = new LazyImages((key, img) => this.measureOverhang(key, img));
        this.terrainAtlases = new Map();
        this.spriteOverhangTiles = 14;
        this.zoom = 1;
        this.dpr = 1;
        this.viewW = 640;
        this.viewH = 480;
    }

    // 短い辺に tilesShort マスが収まる大きさで描く
    resize(cssW, cssH, tilesShort = 13) {
        this.dpr = Math.min(2, window.devicePixelRatio || 1);
        this.canvas.width = Math.round(cssW * this.dpr);
        this.canvas.height = Math.round(cssH * this.dpr);
        this.canvas.style.width = `${cssW}px`;
        this.canvas.style.height = `${cssH}px`;
        this.zoom = Math.min(cssW, cssH) / (tilesShort * TILE);
        this.viewW = cssW / this.zoom;
        this.viewH = cssH / this.zoom;
    }

    // 画像素材（public/images/field/*.webp）。全部で数十MBあるので、民家・入口などは画面に映った物の分だけ読む。
    // 無い物・読み込み中の物は仮の絵のまま
    loadImages(keys, baseUrl, versions = {}) {
        this.images.setAvailable(keys, baseUrl, versions);
        // 縦長の名所は、足元が画面外でも上部が映るので、高さを知るために最初から読む
        for (const key of keys) if (EAGER_ASSETS.test(key)) this.images.get(key);
    }

    loadTerrainAtlases(urls = {}) {
        for (const [group, url] of Object.entries(urls)) {
            const image = new Image();
            image.onload = () => {
                this.terrainAtlases.set(group, image);
                for (const [tile, overlay] of createTerrainOverlays(group, image)) {
                    this.terrainAtlases.set(`overlay:${tile}`, overlay);
                }
                this.blocks.clear(); // 読み込み前に描いた仮タイルを作り直す
            };
            image.src = url;
        }
    }

    // 塔などの縦長画像は、足元が画面外でも上部が見える。
    // 当たり判定の範囲は変えず、描画時に探す範囲だけ広げる。
    measureOverhang(key, img) {
        for (const o of this.world.objects) {
            if (!assetKeysFor(o).includes(key)) continue;
            const overhang = img.height / img.width * o.tw - o.th;
            this.spriteOverhangTiles = Math.max(this.spriteOverhangTiles, Math.ceil(overhang) + 1);
        }
    }

    invalidateChunk(cx, cy, chunkTiles) {
        const per = chunkTiles / BLOCK;
        for (let by = cy * per - 1; by <= (cy + 1) * per; by++) {
            for (let bx = cx * per - 1; bx <= (cx + 1) * per; bx++) this.blocks.delete(`${bx},${by}`);
        }
    }

    // far: 引いた視点用の簡易描画（1マス FAR_PX ピクセル。色と段差の影だけ）
    block(bx, by, far = false) {
        const key = `${far ? 'f' : 'n'}${bx},${by}`;
        let c = this.blocks.get(key);
        if (c) {
            this.blocks.delete(key);
            this.blocks.set(key, c);
            return c;
        }
        const px = far ? FAR_PX : TILE;
        c = document.createElement('canvas');
        c.width = BLOCK * px;
        c.height = BLOCK * px;
        const ctx = c.getContext('2d');
        for (let y = 0; y < BLOCK; y++) {
            for (let x = 0; x < BLOCK; x++) {
                const tx = bx * BLOCK + x;
                const ty = by * BLOCK + y;
                if (far) {
                    drawFarTile(ctx, this.world, tx, ty, x * px, y * px);
                } else {
                    drawTerrainTile(ctx, this.world, tx, ty, x * TILE, y * TILE, this.terrainAtlases);
                    drawElevation(ctx, this.world, tx, ty, x * TILE, y * TILE, this.terrainAtlases);
                }
            }
        }
        this.blocks.set(key, c);
        const visible = (Math.ceil(this.viewW / (BLOCK * TILE)) + 1) * (Math.ceil(this.viewH / (BLOCK * TILE)) + 1);
        if (this.blocks.size > Math.max(BLOCK_CACHE_MIN, visible * 2)) this.blocks.delete(this.blocks.keys().next().value);
        return c;
    }

    // extras: { sprites: [{ y, draw(ctx) }], overlay(ctx, t) } … 魔物・宝箱・住人・空気感など
    render(camera, player, others, t, extras = {}) {
        const { ctx } = this;
        const scale = this.zoom * this.dpr;
        const camX = Math.round(camera.x * scale) / scale;
        const camY = Math.round(camera.y * scale) / scale;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.fillStyle = player.plane === 'sky' ? '#9fd0f4' : '#2b5a9e';
        ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
        ctx.setTransform(scale, 0, 0, scale, -camX * scale, -camY * scale);
        ctx.imageSmoothingEnabled = false;

        const size = BLOCK * TILE;
        const far = scale < FAR_SCALE;
        const bx0 = Math.floor(camX / size);
        const by0 = Math.floor(camY / size);
        const bx1 = Math.floor((camX + this.viewW) / size);
        const by1 = Math.floor((camY + this.viewH) / size);
        for (let by = by0; by <= by1; by++) {
            for (let bx = bx0; bx <= bx1; bx++) ctx.drawImage(this.block(bx, by, far), bx * size, by * size, size, size);
        }
        ctx.imageSmoothingEnabled = true;

        // 物（背の高い物は上へはみ出すので、少し下まで探す）
        const tx0 = Math.floor(camX / TILE) - 6;
        const ty0 = Math.floor(camY / TILE) - 4;
        const tx1 = Math.ceil((camX + this.viewW) / TILE) + 6;
        const ty1 = Math.ceil((camY + this.viewH) / TILE) + this.spriteOverhangTiles;
        const objects = this.world.objectsInRect(tx0, ty0, tx1, ty1);
        for (const o of objects) if (FLAT.has(o.type)) drawObject(ctx, o, t, this.images);

        const sprites = [];
        for (const o of objects) if (!FLAT.has(o.type)) sprites.push({ y: (o.ty + o.th) * TILE, o });
        // 通行できる入口は建物画像の内側。座標は動かさず描画順だけ手前へ。
        const actorDepth = p => objects.reduce((depth, o) => inBuildingApproach(o, p.x / TILE, p.y / TILE)
            ? Math.max(depth, (o.ty + o.th) * TILE + 0.01) : depth, p.y);
        for (const p of others) sprites.push({ y: actorDepth(p), p });
        if (!player.hidden) sprites.push({ y: actorDepth(player), p: player, self: true });
        for (const e of extras.sprites ?? []) sprites.push(e);
        sprites.sort((a, b) => a.y - b.y);
        for (const s of sprites) {
            if (s.draw) s.draw(ctx, this.images);
            else if (s.o) drawObject(ctx, s.o, t, this.images, player);
            else drawAdventurer(ctx, s.p, t, !!s.self, this.images);
        }
        extras.overlay?.(ctx, t);
    }
}

// ---- ミニマップ -------------------------------------------------------------------------------

const MINI_COLORS = Object.fromEntries(Object.entries(TILES).map(([k, v]) => [k, v.color]));

export function drawMinimap(canvas, gen, player, step = 4) {
    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;
    const ptx = Math.floor(player.x / TILE);
    const pty = Math.floor(player.y / TILE);
    const img = ctx.createImageData(w, h);
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            const tx = ptx + (x - w / 2) * step;
            const ty = pty + (y - h / 2) * step;
            const c = gen.cell(tx, ty);
            const hex = MINI_COLORS[c.tile] ?? '#000000';
            const n = parseInt(hex.slice(1), 16);
            const shade = 0.85 + c.level * 0.035;
            const i = (y * w + x) * 4;
            img.data[i] = Math.min(255, (n >> 16) * shade);
            img.data[i + 1] = Math.min(255, ((n >> 8) & 255) * shade);
            img.data[i + 2] = Math.min(255, (n & 255) * shade);
            img.data[i + 3] = 255;
        }
    }
    ctx.putImageData(img, 0, 0);
    drawHereMarker(ctx, w / 2, h / 2, player.facing ?? 0, performance.now() / 1000, 0.7);
}

// 現在地の印（向きの矢印と、広がる輪）。facing: 0 下 / 1 左 / 2 右 / 3 上
export function drawHereMarker(ctx, x, y, facing, t, scale = 1) {
    const pulse = (t * 1.2) % 1;
    ctx.strokeStyle = `rgba(255,60,70,${1 - pulse})`;
    ctx.lineWidth = 3 * scale;
    ctx.beginPath();
    ctx.arc(x, y, (8 + pulse * 18) * scale, 0, Math.PI * 2);
    ctx.stroke();
    const angle = [Math.PI / 2, Math.PI, 0, -Math.PI / 2][facing] ?? Math.PI / 2;
    ctx.save();
    ctx.translate(x, y);
    ctx.rotate(angle);
    ctx.fillStyle = '#ff2a3a';
    ctx.strokeStyle = '#fff';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(11 * scale, 0);
    ctx.lineTo(-7 * scale, -8 * scale);
    ctx.lineTo(-3 * scale, 0);
    ctx.lineTo(-7 * scale, 8 * scale);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();
    ctx.restore();
}

// ---- 世界地図 ---------------------------------------------------------------------------------

const BIOME_COLORS = {
    [BIOME.OCEAN]: [52, 98, 176], [BIOME.GRASS]: [126, 176, 78], [BIOME.FOREST]: [70, 128, 58],
    [BIOME.DEEP_FOREST]: [40, 96, 52], [BIOME.HILLS]: [170, 160, 110], [BIOME.MOUNTAIN]: [120, 108, 96],
    [BIOME.SNOW]: [236, 240, 246], [BIOME.SNOW_MOUNTAIN]: [200, 208, 222], [BIOME.VOLCANO]: [96, 70, 62],
    [BIOME.DESERT]: [226, 200, 132], [BIOME.BLIGHT]: [98, 74, 120], [BIOME.SAVANNA]: [190, 180, 104],
};

// 本土の地図の下地（マクロ地図から1回だけ作る）
export function buildLandMapImage(macro) {
    const canvas = document.createElement('canvas');
    canvas.width = macro.width;
    canvas.height = macro.height;
    const ctx = canvas.getContext('2d');
    const img = ctx.createImageData(macro.width, macro.height);
    for (let i = 0; i < macro.width * macro.height; i++) {
        const land = macro.coast[i] > 0;
        const [r, g, b] = land ? BIOME_COLORS[macro.biome[i]] ?? [126, 176, 78] : BIOME_COLORS[BIOME.OCEAN];
        const shade = land ? 0.8 + macro.heightField[i] * 0.04 : 1 + Math.max(-0.4, macro.coast[i] * 0.02);
        img.data[i * 4] = Math.min(255, r * shade);
        img.data[i * 4 + 1] = Math.min(255, g * shade);
        img.data[i * 4 + 2] = Math.min(255, b * shade);
        img.data[i * 4 + 3] = 255;
    }
    ctx.putImageData(img, 0, 0);
    return canvas;
}

// 浮遊島の地図の下地
export function buildSkyMapImage(gen) {
    const step = 16;
    const w = Math.floor(gen.skyW / step);
    const h = Math.floor(gen.skyH / step);
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    const img = ctx.createImageData(w, h);
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            const c = gen.skyCell(gen.skyX0 + x * step, gen.skyY0 + y * step);
            const hex = MINI_COLORS[c.tile] ?? '#9fd0f4';
            const n = parseInt(hex.slice(1), 16);
            const i = (y * w + x) * 4;
            img.data[i] = n >> 16;
            img.data[i + 1] = (n >> 8) & 255;
            img.data[i + 2] = n & 255;
            img.data[i + 3] = 255;
        }
    }
    ctx.putImageData(img, 0, 0);
    return canvas;
}

/**
 * 世界地図を描く。戻り値は都市の当たり（クリックで転移するため）。
 * view: { plane, base, tilesPerPx, originTx, originTy }
 */
export function drawWorldMap(canvas, gen, world, view, player, t) {
    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;
    ctx.fillStyle = view.plane === 'sky' ? '#9fd0f4' : '#24508e';
    ctx.fillRect(0, 0, w, h);
    const [bw, bh] = view.baseSize ?? [view.base.width, view.base.height];
    const { fit, ox, oy } = mapFrame(view.camera ?? { zoom: 1 }, { width: w, height: h }, { width: bw, height: bh });
    ctx.imageSmoothingEnabled = true;
    ctx.drawImage(view.base, ox, oy, bw * fit, bh * fit);
    const toScreen = (tx, ty) => [ox + ((tx - view.originTx) / view.tilesPerPx) * fit, oy + ((ty - view.originTy) / view.tilesPerPx) * fit];

    // 川・街道
    if (view.plane === 'land') {
        ctx.strokeStyle = 'rgba(80,150,230,0.9)';
        ctx.lineWidth = 2;
        for (const r of gen.rivers) polyline(ctx, r.points, toScreen);
    }
    for (const r of gen.roads) {
        if (r.plane !== view.plane || (view.illustrated && r.kind === 'spur')) continue;
        ctx.strokeStyle = r.kind === 'spur' ? 'rgba(120,90,50,0.55)' : 'rgba(250,228,160,0.95)';
        ctx.lineWidth = r.kind === 'spur' ? 1 : 2.2;
        polyline(ctx, r.points, toScreen);
    }
    const hits = [];
    // 入口
    for (const e of gen.entrances) {
        if (e.plane !== view.plane) continue;
        const [x, y] = toScreen(e.doorTx, e.doorTy);
        ctx.fillStyle = world.enterableAreas.has(e.area_id) ? '#ff6a4a' : 'rgba(90,80,90,0.8)';
        ctx.fillRect(x - 2, y - 2, 4, 4);
        hits.push({ x, y, destinationId: `area:${e.area_id}`, unlocked: world.enterableAreas.has(e.area_id), name: e.name });
    }
    // 転移陣・結界
    for (const tp of gen.teleporters) {
        if (tp.plane !== view.plane) continue;
        const [x, y] = toScreen(tp.centerTx, tp.centerTy);
        ctx.strokeStyle = '#8af0ff';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(x, y, 6, 0, Math.PI * 2);
        ctx.stroke();
    }
    // 街道沿いの寄り道：祈った旅の祠は水色のひし形で区別する。
    const kindColor = { hamlet: '#f4d67a', inn: '#ffb86a', rest_stop: '#e8a860', windmill: '#f0f0d8', watchtower: '#c8a070', shrine: '#d8c8f8', ruins: '#a8a098' };
    ctx.font = 'bold 11px "Hiragino Kaku Gothic ProN", "Noto Sans JP", sans-serif';
    ctx.textAlign = 'center';
    for (const w of view.waysides ?? []) {
        if (w.plane !== view.plane) continue;
        const [x, y] = toScreen(w.tx + w.w / 2, w.ty + w.h / 2);
        const known = view.waystones?.has(w.city.id);
        if (known) {
            ctx.fillStyle = '#7ad8ff';
            ctx.strokeStyle = '#fff';
            ctx.lineWidth = 1.5;
            ctx.beginPath();
            ctx.moveTo(x, y - 6);
            ctx.lineTo(x + 5, y);
            ctx.lineTo(x, y + 6);
            ctx.lineTo(x - 5, y);
            ctx.closePath();
            ctx.fill();
            ctx.stroke();
        } else {
            ctx.fillStyle = kindColor[w.city.kind] ?? '#fff';
            ctx.strokeStyle = 'rgba(0,0,0,0.6)';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.arc(x, y, w.city.kind === 'inn' ? 3.5 : 2.5, 0, Math.PI * 2);
            ctx.fill();
            ctx.stroke();
        }
        hits.push({ x, y, destinationId: `wayside:${w.city.id}`, unlocked: true, name: w.city.name });
        if (known || w.city.kind === 'inn') {
            ctx.lineWidth = 3;
            ctx.strokeStyle = 'rgba(0,0,0,0.75)';
            ctx.strokeText(w.city.name, x, y - 8);
            ctx.fillStyle = known ? '#bfeaff' : '#ffe0b0';
            ctx.fillText(w.city.name, x, y - 8);
        }
    }
    // 都市
    ctx.font = 'bold 13px "Hiragino Kaku Gothic ProN", "Noto Sans JP", sans-serif';
    ctx.textAlign = 'center';
    for (const c of gen.cities) {
        if (c.plane !== view.plane || c.city.minor) continue;
        const [x, y] = toScreen(c.tx + c.w / 2, c.ty + c.h / 2);
        const unlocked = world.unlockedCities.has(c.city.id);
        ctx.fillStyle = unlocked ? '#fff4c8' : '#9a9aa0';
        ctx.strokeStyle = '#2a1a10';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.rect(x - 6, y - 6, 12, 12);
        ctx.fill();
        ctx.stroke();
        const half = ctx.measureText(c.city.name).width / 2 + 4;
        const lx = Math.max(half, Math.min(w - half, x));
        ctx.lineWidth = 4;
        ctx.strokeStyle = 'rgba(0,0,0,0.75)';
        ctx.strokeText(c.city.name, lx, y - 10);
        ctx.fillStyle = unlocked ? '#fff' : '#b8b8c0';
        ctx.fillText(c.city.name, lx, y - 10);
        hits.push({ x, y, destinationId: `city:${c.city.id}`, unlocked, name: c.city.name });
    }
    // 本土の地図には、東の空に浮かぶ浮遊大陸（転移陣からしか行けない）を描いておく
    if (view.plane === 'land' && view.skyHint) {
        const [x, y] = toScreen(view.skyHint[0], view.skyHint[1]);
        if (!view.illustrated) {
            ctx.fillStyle = 'rgba(255,255,255,0.8)';
            for (const [dx, dy, r] of [[-12, 2, 9], [0, -4, 11], [12, 2, 9], [0, 6, 10]]) {
                ctx.beginPath();
                ctx.arc(x + dx, y + dy, r, 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.fillStyle = '#e8c860';
            ctx.fillRect(x - 5, y - 8, 10, 8);
        }
        const text = '天空神殿セレスティア（浮遊大陸）';
        const half = ctx.measureText(text).width / 2 + 4;
        const lx = Math.max(half, Math.min(w - half, x));
        ctx.lineWidth = 4;
        ctx.strokeStyle = 'rgba(0,0,0,0.75)';
        ctx.strokeText(text, lx, y - 16);
        ctx.fillStyle = world.unlockedCities.has(9) ? '#fff' : '#b8b8c0';
        ctx.fillText(text, lx, y - 16);
    }
    const selected = hits.find((hit) => hit.destinationId === view.selectedDestinationId);
    if (selected) {
        ctx.strokeStyle = '#ffe27a';
        ctx.lineWidth = 3;
        ctx.beginPath();
        ctx.arc(selected.x, selected.y, 11, 0, Math.PI * 2);
        ctx.stroke();
    }
    // 現在地：広がる輪・向きの矢印・「現在地」の札
    if (player.plane === view.plane) {
        const [x, y] = toScreen(player.x / TILE, player.y / TILE);
        drawHereMarker(ctx, x, y, player.facing ?? 0, t, 1);
        const text = '現在地';
        ctx.font = 'bold 13px "Hiragino Kaku Gothic ProN", "Noto Sans JP", sans-serif';
        const tw = ctx.measureText(text).width + 12;
        const lx = Math.max(tw / 2 + 2, Math.min(w - tw / 2 - 2, x));
        const ly = y > 40 ? y - 30 : y + 34;
        ctx.fillStyle = '#d8283a';
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.roundRect?.(lx - tw / 2, ly - 11, tw, 20, 6);
        if (!ctx.roundRect) ctx.rect(lx - tw / 2, ly - 11, tw, 20);
        ctx.fill();
        ctx.stroke();
        ctx.fillStyle = '#fff';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(text, lx, ly);
        ctx.textBaseline = 'alphabetic';
    }
    return hits;
}

function polyline(ctx, points, toScreen) {
    ctx.beginPath();
    points.forEach(([x, y], i) => {
        const [sx, sy] = toScreen(x, y);
        if (i === 0) ctx.moveTo(sx, sy);
        else ctx.lineTo(sx, sy);
    });
    ctx.stroke();
}

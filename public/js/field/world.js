// フィールドの読み込み済みの世界：チャンクのキャッシュ、当たり判定、物（建物・入口など）の検索。

import { TILE, isRoadTile, isSolidTile, isStairsTile } from './constants.js';
import { buildNation, nationAt } from './nations.js';
import { objectBlocksTile, objectBlocksRect } from './object-footprint.js';

const CHUNK_CACHE = 72;
const OBJECT_CELL = 32; // 物を探すためのバケツ（マス）

export class FieldWorld {
    constructor(gen, def, player) {
        this.gen = gen;
        this.def = def;
        this.chunkSize = def.chunk_tiles;
        this.chunks = new Map();
        this.pending = [];
        this.unlockedCities = new Set(player.unlocked_city_ids);
        this.enterableAreas = new Set(player.enterable_area_ids);
        this.buckets = new Map();
        this.objects = [];
        this.nations = [];
        this.onChunkLoaded = () => {};

        for (const city of gen.cities) for (const o of city.objects) this.addObject({ ...o, plane: city.plane });
        for (const e of gen.entrances) this.addObject({ ...e, enterable: this.enterableAreas.has(e.area_id) });
        for (const t of gen.teleporters) {
            this.addObject({ ...t, unlocked: this.unlockedCities.has(t.unlock_city_id) });
            if (t.plane === 'land') {
                this.addObject({ type: 'sky_tower', plane: 'land', label: t.name, tx: t.centerTx - 3, ty: t.centerTy - 12, tw: 6, th: 6, solid: true });
            }
        }
        for (const b of gen.barriers) {
            const unlocked = this.unlockedCities.has(b.unlock_city_id);
            this.addObject({ ...b, plane: 'land', unlocked, solid: !unlocked });
        }
        // まだ入れない都市の門には衛兵が立つ
        for (const city of gen.cities) {
            if (city.city.minor || this.unlockedCities.has(city.city.id)) continue;
            for (const g of city.gates) {
                const vertical = g.dir === 'n' || g.dir === 's';
                const tx = vertical ? g.tx - 3 : g.dir === 'w' ? g.tx : g.tx - 3;
                const ty = vertical ? (g.dir === 'n' ? g.ty : g.ty - 3) : g.ty - 3;
                this.addObject({ type: 'guard_gate', plane: city.plane, cityId: city.city.id, label: `${city.city.name}（未踏）`, tx, ty, tw: vertical ? 6 : 4, th: vertical ? 4 : 6, solid: true, dir: g.dir });
            }
        }
    }

    addObject(o) {
        this.objects.push(o);
        const x0 = Math.floor(o.tx / OBJECT_CELL);
        const x1 = Math.floor((o.tx + o.tw - 1) / OBJECT_CELL);
        const y0 = Math.floor(o.ty / OBJECT_CELL);
        const y1 = Math.floor((o.ty + o.th - 1) / OBJECT_CELL);
        for (let y = y0; y <= y1; y++) {
            for (let x = x0; x <= x1; x++) {
                const key = `${x},${y}`;
                let list = this.buckets.get(key);
                if (!list) this.buckets.set(key, (list = []));
                list.push(o);
            }
        }
    }

    setNations(settlements, catalog) {
        const base = this.objects.filter(o => o.nationId === undefined);
        this.objects = [];
        this.buckets.clear();
        this.nations = settlements.map(s => buildNation(s, catalog, this.gen));
        for (const o of base) this.addObject(o);
        for (const s of this.nations) for (const o of s.objects) this.addObject(o);
    }

    nationTile(tx, ty) {
        const s = nationAt(this.nations, tx, ty, 3);
        if (!s) return null;
        const inner = nationAt([s], tx, ty);
        if (!inner) {
            const level = this.gen.levelAt(tx, ty);
            // An approach shares the natural level; stairs bridge the edge of the flat foundation.
            return [[tx - 1, ty], [tx + 1, ty], [tx, ty - 1], [tx, ty + 1]].some(([x, y]) => this.levelAt(x, y) !== level) ? '=' : 's';
        }
        return s.grid[(ty - s.ty) * s.size + tx - s.tx];
    }

    // ---- チャンク ------------------------------------------------------------------------

    chunkKey(cx, cy) {
        return `${cx},${cy}`;
    }

    chunk(cx, cy) {
        const key = this.chunkKey(cx, cy);
        let c = this.chunks.get(key);
        if (c) {
            this.chunks.delete(key);
            this.chunks.set(key, c);
            return c;
        }
        c = this.gen.generateChunk(cx, cy, this.chunkSize);
        this.chunks.set(key, c);
        if (this.chunks.size > CHUNK_CACHE) this.chunks.delete(this.chunks.keys().next().value);
        this.onChunkLoaded(cx, cy);
        return c;
    }

    hasChunk(cx, cy) {
        return this.chunks.has(this.chunkKey(cx, cy));
    }

    // 周りのチャンクを先に作っておく（1フレームに少しずつ）
    prefetch(tx, ty, radius = 2, budget = 2) {
        const s = this.chunkSize;
        const pcx = Math.floor(tx / s);
        const pcy = Math.floor(ty / s);
        let made = 0;
        for (let r = 0; r <= radius && made < budget; r++) {
            for (let dy = -r; dy <= r && made < budget; dy++) {
                for (let dx = -r; dx <= r && made < budget; dx++) {
                    if (Math.max(Math.abs(dx), Math.abs(dy)) !== r) continue;
                    if (this.hasChunk(pcx + dx, pcy + dy)) continue;
                    this.chunk(pcx + dx, pcy + dy);
                    made++;
                }
            }
        }
        return made;
    }

    tileAt(tx, ty) {
        const nationTile = this.nationTile(tx, ty);
        if (nationTile !== null) return nationTile;
        const s = this.chunkSize;
        const cx = Math.floor(tx / s);
        const cy = Math.floor(ty / s);
        const c = this.chunk(cx, cy);
        return c.tiles[(ty - cy * s) * s + (tx - cx * s)];
    }

    levelAt(tx, ty) {
        const settlement = nationAt(this.nations, tx, ty);
        if (settlement) return settlement.level;
        const s = this.chunkSize;
        const cx = Math.floor(tx / s);
        const cy = Math.floor(ty / s);
        const c = this.chunk(cx, cy);
        return c.levels[(ty - cy * s) * s + (tx - cx * s)];
    }

    // ---- 当たり判定 -----------------------------------------------------------------------

    objectsAtTile(tx, ty) {
        return this.buckets.get(`${Math.floor(tx / OBJECT_CELL)},${Math.floor(ty / OBJECT_CELL)}`) ?? [];
    }

    isBlocked(tx, ty) {
        if (!this.gen.inBounds(tx, ty)) return true;
        const tile = this.tileAt(tx, ty);
        if (isSolidTile(tile)) return true;
        // city.solid は建物の配置範囲全体を含む。歩行では屋根側を塞がず、
        // 都市・街道・国家すべての物を同じ接地判定で調べる。
        // 木・水・城壁などの地形の障害は上の tile 判定で維持する。
        for (const o of this.objectsAtTile(tx, ty)) {
            if (!o.solid) continue;
            if (objectBlocksTile(o, tx, ty)) return true;
        }
        return false;
    }

    /**
     * (x, y) px を足元の中心として、体の四角（幅 w・奥行き h）が置けるか。
     * 段の違うマスへは、階段を通る時だけ入れる。
     */
    canOccupy(x, y, fromX, fromY, w = 18, h = 12) {
        const ctx = Math.floor(fromX / TILE);
        const cty = Math.floor(fromY / TILE);
        const fromLevel = this.levelAt(ctx, cty);
        const fromStairs = isStairsTile(this.tileAt(ctx, cty));
        const tx0 = Math.floor((x - w / 2) / TILE);
        const tx1 = Math.floor((x + w / 2) / TILE);
        const ty0 = Math.floor((y - h / 2) / TILE);
        const ty1 = Math.floor((y + h / 2) / TILE);
        for (let ty = ty0; ty <= ty1; ty++) {
            for (let tx = tx0; tx <= tx1; tx++) {
                if (!this.gen.inBounds(tx,ty) || isSolidTile(this.tileAt(tx,ty))) return false;
                for(const o of this.objectsAtTile(tx,ty)) {
                    if(o.solid && objectBlocksRect(o,(x-w/2)/TILE,(y-h/2)/TILE,(x+w/2)/TILE,(y+h/2)/TILE))return false;
                }
                const level = this.levelAt(tx, ty);
                if (level === fromLevel) continue;
                const tile = this.tileAt(tx, ty);
                if (isStairsTile(tile)) continue;
                if (fromStairs && isRoadTile(tile)) continue;
                return false;
            }
        }
        return true;
    }

    // 近くの空いている場所（出現した所が塞がっていた時）
    nearestOpen(x, y, maxRadius = 40) {
        if (this.canOccupy(x, y, x, y)) return [x, y];
        for (let r = 1; r < maxRadius; r++) {
            for (let dy = -r; dy <= r; dy++) {
                for (let dx = -r; dx <= r; dx++) {
                    if (Math.max(Math.abs(dx), Math.abs(dy)) !== r) continue;
                    const px = x + dx * TILE;
                    const py = y + dy * TILE;
                    if (this.canOccupy(px, py, px, py)) return [px, py];
                }
            }
        }
        return [x, y];
    }

    // ---- 物 ------------------------------------------------------------------------------

    objectsInRect(tx0, ty0, tx1, ty1) {
        const seen = new Set();
        const out = [];
        for (let y = Math.floor(ty0 / OBJECT_CELL); y <= Math.floor(ty1 / OBJECT_CELL); y++) {
            for (let x = Math.floor(tx0 / OBJECT_CELL); x <= Math.floor(tx1 / OBJECT_CELL); x++) {
                for (const o of this.buckets.get(`${x},${y}`) ?? []) {
                    if (seen.has(o)) continue;
                    seen.add(o);
                    if (o.tx + o.tw < tx0 || o.ty + o.th < ty0 || o.tx > tx1 || o.ty > ty1) continue;
                    out.push(o);
                }
            }
        }
        return out;
    }

    // 調べられる物：施設の扉の前・入口の前・転移陣の上・名所
    interactableNear(x, y) {
        const tx = x / TILE;
        const ty = y / TILE;
        let best = null;
        for (const o of this.objectsInRect(tx - 4, ty - 6, tx + 4, ty + 4)) {
            let spot = null;
            let reach = 1.6;
            if (o.type === 'facility' || o.type === 'board') spot = [o.tx + Math.floor(o.tw / 2) + 0.5, o.ty + o.th + 0.5];
            else if (o.type === 'entrance') spot = [o.doorTx + 0.5, o.doorTy + 0.5];
            else if (o.type === 'teleporter') [spot, reach] = [[o.centerTx + 0.5, o.centerTy + 0.5], 2.2];
            else if (o.type === 'barrier' || o.type === 'guard_gate') [spot, reach] = [[o.tx + o.tw / 2, o.ty + o.th / 2], Math.max(o.tw, o.th) / 2 + 1.2];
            else if (['waystone', 'signpost', 'well', 'lodge', 'windmill', 'watchtower', 'shrine', 'stable'].includes(o.type)) [spot, reach] = [[o.tx + o.tw / 2, o.ty + o.th + 0.5], Math.max(1.8, o.tw / 2 + 0.8)];
            else if (o.label && ['castle', 'world_tree', 'furnace', 'cathedral', 'magic_tower', 'sky_temple', 'demon_castle', 'lighthouse', 'oasis_shrine', 'mausoleum', 'mine_gate', 'academy_hall', 'ship', 'sky_tower'].includes(o.type)) {
                spot = [o.tx + o.tw / 2, o.ty + o.th + 0.5];
                reach = Math.max(2, o.tw / 2);
            }
            if (!spot) continue;
            const d = Math.hypot(spot[0] - tx, spot[1] - ty);
            if (d <= reach && (!best || d < best.d)) best = { object: o, d };
        }
        return best?.object ?? null;
    }

    // いまいる所の名前（都市の中なら都市の名前）
    placeName(tx, ty) {
        const settlement = nationAt(this.nations, tx, ty);
        if (settlement) return settlement.name;
        const plane = this.gen.planeOfTile(tx);
        const city = this.gen.cityAt(tx, ty, 6);
        if (city && city.plane === plane) return city.city.name;
        let best = null;
        for (const r of this.def.regions) {
            if (r.plane !== plane) continue;
            const d = Math.hypot(r.tx - tx, r.ty - ty);
            if (!best || d < best.d) best = { r, d };
        }
        return best?.r.name ?? '';
    }
}

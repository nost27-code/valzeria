// ヴァルゼリア大陸の地形生成。
// マクロ地図（地形帯・標高・海岸）と、サーバーの定義（都市・街道・川・入口・転移陣）から、
// どのマスも毎回同じ形に作る（保存しない）。重ねる順番：
//   都市 > 港 > 入口などの広場 > 街道（橋・溶岩の上の石道・階段） > 川 > 自然の地形
//
// 段差：マスごとに「段」（0〜9）がある。段の違うマスへは、階段（街道が段をまたぐ所に自動でできる）を通らないと行けない。

import { BIOME, isSolidTile } from './constants.js';
import { SegmentIndex, cumulativeLengths, meander } from './geometry.js';
import { fbm, hash2i } from './noise.js';
import { buildCity } from './towns.js';
import { planWaysides } from './wayside.js';

// 魔王の火山（scripts/field/build_valzeria_macro.py の VOLCANO / VOLCANO_PEAK と同じ。単位はセル）
const VOLCANO = { cx: 320, cy: 100, rx: 110, ry: 90 };
const VOLCANO_PEAK = { cx: 322, cy: 58 };
const MOAT = [0.948, 0.957];   // 麓の溶岩の堀（楕円の正規化距離）
const LAVA_CHANNELS = 7;

const ROAD_HALF = 1.5;
const RIVER_BANK = 3;   // 川の両岸の砂地の幅
const CLEARING_RADIUS = { entrance: 6, teleporter: 8 };

const LOWLAND = new Set([BIOME.GRASS, BIOME.FOREST, BIOME.SAVANNA, BIOME.HILLS, BIOME.DESERT, BIOME.BLIGHT]);

export class FieldGenerator {
    constructor(def, macro) {
        this.def = def;
        this.macro = macro;
        this.seed = def.seed;
        this.scale = def.map_scale;
        [this.worldW, this.worldH] = def.world_tiles;
        this.skyX0 = def.sky.origin[0];
        this.skyY0 = def.sky.origin[1];
        [this.skyW, this.skyH] = def.sky.size;
        this.skyCenter = [def.sky.center[0] - this.skyX0, def.sky.center[1] - this.skyY0];
        this.skyRadius = def.sky.radius;

        this.cities = def.cities.map((c) => buildCity(c));
        for (const layout of this.cities) {
            const [ccx, ccy] = [layout.tx + Math.floor(layout.w / 2), layout.ty + Math.floor(layout.h / 2)];
            layout.level = Math.max(1, this.naturalCell(ccx, ccy).level);
            layout.plane = layout.city.plane;
        }
        this.harbors = this.cities.filter((c) => c.harbor === 'west').map((c) => this.buildHarbor(c));

        this.roads = [];
        this.roadIndex = new SegmentIndex(512);
        this.riverIndex = new SegmentIndex(512);
        this.clearings = [];
        this.buildRoads();
        this.buildRivers();
        this.buildWaysides();
        this.buildEntrances();
        this.buildTeleporters();
        this.barriers = this.buildBarriers();
    }

    // ---- 層 ------------------------------------------------------------------------------

    planeOfTile(tx) {
        return tx >= this.skyX0 ? 'sky' : 'land';
    }

    inBounds(tx, ty) {
        if (tx >= this.skyX0) return tx < this.skyX0 + this.skyW && ty >= this.skyY0 && ty < this.skyY0 + this.skyH;
        return tx >= 0 && ty >= 0 && tx < this.worldW && ty < this.worldH;
    }

    // ---- 自然の地形 --------------------------------------------------------------------------

    // { tile, level, biome }
    naturalCell(tx, ty) {
        if (tx >= this.skyX0) return this.skyCell(tx, ty);
        if (tx < 0 || ty < 0 || tx >= this.worldW || ty >= this.worldH) return { tile: 'O', level: 0, biome: BIOME.OCEAN };
        const s = this.seed;
        const mx = tx / this.scale;
        const my = ty / this.scale;
        const warpX = 2.2 * fbm(tx / 2600, ty / 2600, s + 10, 2) + 1.1 * fbm(tx / 900, ty / 900, s + 11, 2) + 0.35 * fbm(tx / 220, ty / 220, s + 12, 2);
        const warpY = 2.2 * fbm(tx / 2600, ty / 2600, s + 9, 2) + 1.1 * fbm(tx / 900, ty / 900, s + 13, 2) + 0.35 * fbm(tx / 220, ty / 220, s + 14, 2);
        const wx = mx + warpX;
        const wy = my + warpY;

        const coastCells = this.macro.coastAt(mx + warpX * 0.4, my + warpY * 0.4);
        const sd = coastCells * this.scale + 18 * fbm(tx / 60, ty / 60, s + 15, 2) + 5 * fbm(tx / 14, ty / 14, s + 16, 2);
        if (sd < 0) return { tile: sd < -14 ? 'O' : 'W', level: 0, biome: BIOME.OCEAN };

        let biome = this.macro.biomeAt(wx, wy);
        if (biome === BIOME.OCEAN) biome = this.macro.biomeAt(mx, my);
        if (biome === BIOME.OCEAN) biome = BIOME.GRASS;

        let height = this.macro.heightAt(mx + warpX * 0.6, my + warpY * 0.6) + 0.22 * fbm(tx / 70, ty / 70, s + 21, 2);
        // 平地のところどころにある台地（都市のまわりは平らなまま）
        if (LOWLAND.has(biome) && height < 1.8 && fbm(tx / 420, ty / 420, s + 23, 3) > 0.36 && !this.cityAt(tx, ty, 220)) height += 1;
        const level = Math.max(0, Math.min(9, Math.floor(height)));

        const hh = hash2i(tx, ty, s + 3);

        // 海岸：崖か浜
        const beach = 5 + 4 * fbm(tx / 200, ty / 200, s + 17, 2);
        if (sd < beach) {
            const cliffy = fbm(tx / 300, ty / 300, s + 41, 2) > 0.1;
            if (sd < 2.2 && cliffy && biome !== BIOME.DESERT && biome !== BIOME.SNOW) return { tile: 'R', level, biome };
            const shore = { [BIOME.DESERT]: 'D', [BIOME.SNOW]: 'N', [BIOME.SNOW_MOUNTAIN]: 'N', [BIOME.VOLCANO]: 'v', [BIOME.BLIGHT]: 'K' }[biome] ?? 'A';
            return { tile: shore, level, biome };
        }

        return { tile: this.biomeTile(biome, tx, ty, wx, wy, hh), level, biome };
    }

    biomeTile(biome, tx, ty, wx, wy, hh) {
        const s = this.seed;
        switch (biome) {
            case BIOME.GRASS: {
                if (fbm(tx / 160, ty / 160, s + 51, 3) > 0.32) return this.forestTile(tx, ty, hh, 0.2);
                if (fbm(tx / 45, ty / 45, s + 52, 2) > 0.45) return hh < 0.6 ? 'L' : 'G';
                if (hh < 0.012) return 'T';
                return hh < 0.1 ? 'g' : 'G';
            }
            case BIOME.FOREST:
                return this.forestTile(tx, ty, hh, 0.2 + 0.1 * Math.max(0, fbm(tx / 90, ty / 90, s + 53, 2)));
            case BIOME.DEEP_FOREST: {
                if (fbm(tx / 70, ty / 70, s + 54, 2) > 0.42) return hh < 0.3 ? 'L' : 'F'; // 精霊の光る花の空き地
                return this.forestTile(tx, ty, hh, 0.3);
            }
            case BIOME.HILLS: {
                if (fbm(tx / 50, ty / 50, s + 55, 2) > 0.45) return hh < 0.5 ? 'R' : 'H';
                if (hh < 0.04) return 'R';
                if (hh < 0.065) return 'T';
                return hh < 0.12 ? 'g' : 'H';
            }
            case BIOME.MOUNTAIN: {
                if (fbm(tx / 45, ty / 45, s + 61, 3) > -0.05) return 'M';
                return hh < 0.08 ? 'R' : 'H';
            }
            case BIOME.SNOW: {
                if (fbm(tx / 80, ty / 80, s + 71, 2) > 0.25 && hh < 0.26) return 'I';
                if (hh < 0.01) return 'J';
                return hh < 0.08 ? 'n' : 'N';
            }
            case BIOME.SNOW_MOUNTAIN:
                return fbm(tx / 45, ty / 45, s + 72, 3) > -0.05 ? 'J' : hh < 0.1 ? 'I' : 'N';
            case BIOME.VOLCANO:
                return this.volcanoTile(tx, ty, wx, wy, hh);
            case BIOME.DESERT: {
                if (hh < 0.006) return 'C';
                if (hh < 0.01) return 'R';
                const ripple = Math.sin((tx * 0.8 + ty * 0.6) / 7 + 3 * fbm(tx / 60, ty / 60, s + 75, 2));
                return ripple > 0.6 ? 'd' : 'D';
            }
            case BIOME.BLIGHT:
                if (hh < 0.05) return 'Z';
                if (hh < 0.065) return 'R';
                return 'K';
            case BIOME.SAVANNA:
                if (hh < 0.012) return 'T';
                return hh < 0.08 ? 'g' : 'S';
            default:
                return 'G';
        }
    }

    forestTile(tx, ty, hh, density) {
        if (fbm(tx / 70, ty / 70, this.seed + 56, 2) < -0.38) return hh < 0.15 ? 'g' : 'G'; // 森の中の空き地
        if (hh < density) return 'U';
        return hh < density + 0.08 ? 'g' : 'F';
    }

    // 火山：麓の溶岩の堀、山頂から流れ下る溶岩、灰と黒曜岩
    volcanoTile(tx, ty, wx, wy, hh) {
        const ev = Math.hypot((wx - VOLCANO.cx) / VOLCANO.rx, (wy - VOLCANO.cy) / VOLCANO.ry);
        if (ev > MOAT[0] && ev < MOAT[1]) return 'X';
        if (ev < 0.9 && ev > 0.12) {
            const dx = tx - VOLCANO_PEAK.cx * this.scale;
            const dy = ty - VOLCANO_PEAK.cy * this.scale;
            const r = Math.hypot(dx, dy);
            const theta = Math.atan2(dy, dx);
            for (let k = 0; k < LAVA_CHANNELS; k++) {
                const base = (k / LAVA_CHANNELS) * Math.PI * 2 + 0.3;
                const wobble = 0.35 * fbm(r / 420, k * 7.3, this.seed + 81, 2);
                let diff = theta - base - wobble;
                diff = Math.atan2(Math.sin(diff), Math.cos(diff));
                const width = 4 + 2.5 * fbm(r / 90, k * 3.1, this.seed + 83, 2);
                if (Math.abs(diff) * r < width) return 'X';
            }
        }
        if (hh < 0.04) return 'Y';
        return hh < 0.22 ? 'v' : 'V';
    }

    // 浮遊島：雲の海に浮かぶ草原。雲の池・古い大理石の遺構
    skyCell(tx, ty) {
        const lx = tx - this.skyX0;
        const ly = ty - this.skyY0;
        if (lx < 0 || ly < 0 || lx >= this.skyW || ly >= this.skyH) return { tile: 'z', level: 0, biome: BIOME.OCEAN };
        const s = this.seed;
        const e = Math.hypot((lx - this.skyCenter[0]) / this.skyRadius[0], (ly - this.skyCenter[1]) / this.skyRadius[1])
            + 0.12 * fbm(lx / 150, ly / 150, s + 91, 2) + 0.03 * fbm(lx / 30, ly / 30, s + 92, 2);
        if (e > 1) return { tile: 'z', level: 0, biome: BIOME.OCEAN };
        const level = 1 + (fbm(lx / 300, ly / 300, s + 97, 3) > 0.35 ? 1 : 0);
        const hh = hash2i(tx, ty, s + 3);
        let tile = hh < 0.015 ? 'T' : hh < 0.09 ? 'g' : 'G';
        if (e < 0.9 && fbm(lx / 90, ly / 90, s + 93, 2) > 0.5) tile = 'z';
        else if (fbm(lx / 60, ly / 60, s + 95, 2) > 0.45) tile = hh < 0.05 ? 'Q' : 'e';
        else if (fbm(lx / 45, ly / 45, s + 96, 2) > 0.4) tile = hh < 0.6 ? 'L' : 'G';
        return { tile, level, biome: BIOME.GRASS };
    }

    // 広場・入口の前に敷く、その土地の地面
    groundFor(biome, plane) {
        if (plane === 'sky') return 'G';
        return {
            [BIOME.OCEAN]: 'A', [BIOME.GRASS]: 'G', [BIOME.FOREST]: 'F', [BIOME.DEEP_FOREST]: 'F', [BIOME.HILLS]: 'H',
            [BIOME.MOUNTAIN]: 'H', [BIOME.SNOW]: 'N', [BIOME.SNOW_MOUNTAIN]: 'N', [BIOME.VOLCANO]: 'V', [BIOME.DESERT]: 'D',
            [BIOME.BLIGHT]: 'K', [BIOME.SAVANNA]: 'S',
        }[biome] ?? 'G';
    }

    // ---- 都市・港 ------------------------------------------------------------------------------

    cityAt(tx, ty, margin = 0) {
        for (const c of this.cities) {
            if (tx >= c.tx - margin && ty >= c.ty - margin && tx < c.tx + c.w + margin && ty < c.ty + c.h + margin) return c;
        }
        return null;
    }

    // 都市の平らにならす範囲（城壁の形 + margin マス）
    cityFlatAt(tx, ty, margin = 3) {
        const c = this.cityAt(tx, ty, margin);
        if (!c || c.city.shape !== 'round') return c;
        const dx = (tx + 0.5 - (c.tx + c.w / 2)) / (c.w / 2 + margin);
        const dy = (ty + 0.5 - (c.ty + c.h / 2)) / (c.h / 2 + margin);
        return dx * dx + dy * dy <= 1 ? c : null;
    }

    // 港：城壁の西から、海に出るまでの水路
    buildHarbor(layout) {
        const y = layout.ty + Math.floor(layout.h / 2);
        let x = layout.tx - 1;
        let run = 0;
        for (let i = 0; i < 1200; i++, x--) {
            const t = this.naturalCell(x, y).tile;
            run = t === 'O' || t === 'W' ? run + 1 : 0;
            if (run >= 6) break;
        }
        const mid = layout.ty + Math.floor(layout.h / 2);
        return { x0: x, x1: layout.tx - 1, mid, half: Math.min(34, Math.floor(layout.h / 2) - 3) };
    }

    // 港の水路：城壁から離れるほど少し広がり、岸はゆるく波打つ
    inHarbor(hb, tx, ty) {
        if (tx < hb.x0 || tx > hb.x1) return false;
        const away = (hb.x1 - tx) / Math.max(1, hb.x1 - hb.x0);
        const half = hb.half * (1 + 0.6 * away) + 6 * fbm(tx / 40, 3.3, this.seed + 151, 2);
        return Math.abs(ty - hb.mid) <= half;
    }

    // ---- 街道 ------------------------------------------------------------------------------

    waypoint(key) {
        return this.def.waypoints[key] ?? null;
    }

    // 街道の端：都市なら向かう先にいちばん近い門（門の内側 → 門の外）、中継点ならその点
    endpoint(key, toward) {
        const layout = this.cities.find((c) => c.city.key === key);
        if (layout) {
            let best = null;
            for (const g of layout.gates) {
                const d = Math.hypot(g.outTx - toward[0], g.outTy - toward[1]);
                if (!best || d < best.d) best = { g, d };
            }
            const g = best.g;
            return { points: [[g.tx, g.ty], [g.outTx, g.outTy]], plane: layout.plane };
        }
        const wp = this.waypoint(key);
        return wp ? { points: [[wp.tx, wp.ty]], plane: wp.plane } : null;
    }

    centerOf(key) {
        const layout = this.cities.find((c) => c.city.key === key);
        if (layout) return [layout.tx + layout.w / 2, layout.ty + layout.h / 2];
        const wp = this.waypoint(key);
        return wp ? [wp.tx, wp.ty] : [0, 0];
    }

    addRoad(road, points, kind = 'road') {
        const r = { ...road, points, kind };
        this.roads.push(r);
        for (let i = 0; i + 1 < points.length; i++) {
            const [ax, ay] = points[i];
            const [bx, by] = points[i + 1];
            this.roadIndex.add(ax, ay, bx, by, ROAD_HALF + 0.5, r);
        }
        return r;
    }

    buildRoads() {
        for (const road of this.def.roads) {
            const via = road.via ?? [];
            const fromToward = via[0] ?? this.centerOf(road.to);
            const toToward = via[via.length - 1] ?? this.centerOf(road.from);
            const from = this.endpoint(road.from, fromToward);
            const to = this.endpoint(road.to, toToward);
            if (!from || !to) continue;
            const middle = [from.points[from.points.length - 1], ...via, to.points[to.points.length - 1]];
            const curvy = meander(middle, this.seed + this.roads.length * 17, road.restricted ? 2 : 3, road.restricted ? 0.05 : 0.12, 40);
            const points = [...from.points.slice(0, -1), ...curvy, ...to.points.slice(0, -1).reverse()];
            this.addRoad(road, points);
        }
    }

    // ---- 街道沿いの寄り道（村・休み処・宿場町など） -----------------------------------------------

    buildWaysides() {
        this.waysides = planWaysides(this);
        for (const layout of this.waysides) {
            layout.level = Math.max(1, this.naturalCell(Math.round(layout.cx), Math.round(layout.cy)).level);
            this.cities.push(layout);
            const g = layout.gates[0];
            this.addRoad({ key: `${layout.city.key}_path`, plane: layout.plane, restricted: false, paved: false }, [[g.tx, g.ty], [g.outTx, g.outTy], layout.roadPoint], 'spur');
        }
    }

    // ---- 川 --------------------------------------------------------------------------------

    buildRivers() {
        this.rivers = [];
        for (const river of this.def.rivers ?? []) {
            const points = meander(river.points, this.seed + 700 + this.rivers.length * 13, 4, 0.18, 30);
            const lengths = cumulativeLengths(points);
            const total = lengths[lengths.length - 1] || 1;
            const [w0, w1] = river.width;
            const r = { ...river, points };
            this.rivers.push(r);
            for (let i = 0; i + 1 < points.length; i++) {
                const [ax, ay] = points[i];
                const [bx, by] = points[i + 1];
                const width0 = w0 + (w1 - w0) * (lengths[i] / total);
                const width1 = w0 + (w1 - w0) * (lengths[i + 1] / total);
                this.riverIndex.add(ax, ay, bx, by, Math.max(width0, width1) * 0.62 + RIVER_BANK + 2, { river: r, width0, width1 });
            }
        }
    }

    // ---- 入口・転移陣・結界 -----------------------------------------------------------------

    addClearing(tx, ty, r, plane) {
        const natural = this.naturalCell(tx, ty);
        const level = natural.biome === BIOME.OCEAN ? 1 : Math.max(1, natural.level);
        const clearing = { tx, ty, r, plane, level, ground: this.groundFor(natural.biome, plane) };
        this.clearings.push(clearing);
        return clearing;
    }

    buildEntrances() {
        this.entrances = [];
        for (const e of this.def.entrances) {
            const clearing = this.addClearing(e.tx, e.ty, CLEARING_RADIUS.entrance, e.plane);
            const entrance = {
                ...e,
                type: 'entrance',
                tx: e.tx - 2,
                ty: e.ty - 3,
                tw: 5,
                th: 4,
                doorTx: e.tx,
                doorTy: e.ty + 1,
                groundLevel: clearing.level,
                solid: true,
            };
            this.entrances.push(entrance);
            // 入口の前から、近くの街道（登山道などの指定があればその道）へ小道をつなぐ
            const start = [e.tx, e.ty + 3];
            const near = this.roadIndex.nearest(start[0], start[1], (seg) => {
                const road = seg.data;
                if (road.plane !== e.plane) return false;
                return e.road ? road.key === e.road : !road.restricted;
            });
            if (near && near.d > 2) {
                const points = meander([start, [start[0], start[1] + 3], [near.x, near.y]], this.seed + e.area_id * 41, 2, 0.1, 30);
                this.addRoad({ key: `spur_${e.area_id}`, plane: e.plane, restricted: !!e.road, paved: false }, points, 'spur');
            }
        }
    }

    buildTeleporters() {
        this.teleporters = (this.def.teleporters ?? []).map((t) => {
            const clearing = this.addClearing(t.tx, t.ty, CLEARING_RADIUS.teleporter, t.plane);
            return { ...t, type: 'teleporter', tx: t.tx - 2, ty: t.ty - 2, tw: 5, th: 5, centerTx: t.tx, centerTy: t.ty, groundLevel: clearing.level, solid: false };
        });
    }

    // 結界：登山道が麓の溶岩の堀に差しかかる所
    buildBarriers() {
        return (this.def.barriers ?? []).map((b) => {
            const road = this.roads.find((r) => r.key === b.road);
            let at = [b.tx, b.ty];
            if (road) {
                let walked = 0;
                outer: for (let i = 0; i + 1 < road.points.length; i++) {
                    const [ax, ay] = road.points[i];
                    const [bx, by] = road.points[i + 1];
                    const len = Math.hypot(bx - ax, by - ay);
                    for (let d = 0; d < len; d += 1) {
                        const x = Math.round(ax + ((bx - ax) * d) / len);
                        const y = Math.round(ay + ((by - ay) * d) / len);
                        walked++;
                        if (walked > 4 && this.naturalCell(x, y).tile === 'X') {
                            at = [x, y];
                            break outer;
                        }
                    }
                }
            }
            return { ...b, type: 'barrier', tx: at[0] - 3, ty: at[1] - 3, tw: 7, th: 7, centerTx: at[0], centerTy: at[1] };
        });
    }

    // ---- 重ねた後のマス ------------------------------------------------------------------------

    clearingAt(tx, ty, plane) {
        for (const c of this.clearings) {
            if (c.plane !== plane) continue;
            if (Math.abs(tx - c.tx) > c.r + 1 || Math.abs(ty - c.ty) > c.r + 1) continue;
            const wobble = 0.8 * (hash2i(tx, ty, this.seed + 131) - 0.5);
            if (Math.hypot(tx - c.tx, ty - c.ty) <= c.r + wobble) return c;
        }
        return null;
    }

    // 街道の上か（都市の中は見ない）。戻り値は一番近い道
    roadAt(tx, ty, plane) {
        let best = null;
        for (const hit of this.roadIndex.near(tx + 0.5, ty + 0.5)) {
            if (hit.seg.data.plane !== plane || hit.d > ROAD_HALF) continue;
            if (!best || hit.d < best.d) best = hit;
        }
        return best;
    }

    // 川の水面か。bank: true なら岸辺の砂地（水面のすぐ外）も含める
    riverAt(tx, ty, bank = false) {
        for (const hit of this.riverIndex.near(tx + 0.5, ty + 0.5)) {
            const { width0, width1 } = hit.seg.data;
            const base = width0 + (width1 - width0) * hit.t;
            // 岸の線は川幅に合わせてゆるく出入りさせる
            const width = base * (1 + 0.22 * fbm(tx / 60, ty / 60, this.seed + 141, 2)) + 1.5 * fbm(tx / 12, ty / 12, this.seed + 143, 2);
            if (hit.d <= width / 2 + (bank ? RIVER_BANK : 0)) return hit;
        }
        return null;
    }

    // 段（都市・広場は平らにならす）
    levelAt(tx, ty) {
        const plane = this.planeOfTile(tx);
        const city = this.cityFlatAt(tx, ty);
        if (city && city.plane === plane) return city.level;
        const clearing = this.clearingAt(tx, ty, plane);
        if (clearing) return clearing.level;
        return this.naturalCell(tx, ty).level;
    }

    // 都市を除いた、街道の見た目（橋・石道・石畳）。null なら街道でない
    roadTileAt(tx, ty, plane, natural) {
        const hit = this.roadAt(tx, ty, plane);
        if (!hit) return null;
        const road = hit.seg.data;
        if (natural.tile === 'O' || natural.tile === 'W' || this.riverAt(tx, ty)) return 'b';
        if (natural.tile === 'X') return 'c';
        return road.paved || this.cityAt(tx, ty, 160) ? 's' : 'r';
    }

    /**
     * 1マス分の { tile, level, cityLayout? }。
     */
    cell(tx, ty) {
        const plane = this.planeOfTile(tx);
        if (!this.inBounds(tx, ty)) return { tile: plane === 'sky' ? 'z' : 'O', level: 0 };

        const city = this.cityFlatAt(tx, ty);
        if (city && city.plane === plane) {
            const lx = tx - city.tx;
            const ly = ty - city.ty;
            const inside = lx >= 0 && ly >= 0 && lx < city.w && ly < city.h;
            const g = inside ? city.grid[ly * city.w + lx] : null;
            if (g) return { tile: g, level: city.level, city };
        }
        const levelOverride = city && city.plane === plane ? city.level : null;

        if (plane === 'land') {
            for (const hb of this.harbors) {
                if (this.inHarbor(hb, tx, ty)) return { tile: 'W', level: 0 };
            }
        }

        const natural = this.naturalCell(tx, ty);
        const clearing = this.clearingAt(tx, ty, plane);
        const level = levelOverride ?? clearing?.level ?? natural.level;

        const road = this.roadTileAt(tx, ty, plane, natural);
        if (road) {
            return { tile: this.isStairs(tx, ty, plane, level) ? '=' : road, level };
        }
        if (clearing) return { tile: clearing.ground, level };
        if (plane === 'land' && natural.tile !== 'O' && natural.tile !== 'W' && this.riverAt(tx, ty, true)) {
            if (this.riverAt(tx, ty)) return { tile: 'W', level };
            // 岸辺：木や岩のない砂地（雪原・砂漠・火山はその地面のまま）
            if (!'NnIJDdPCVvXYK'.includes(natural.tile)) return { tile: 'A', level };
        }
        if (levelOverride !== null && isSolidTile(natural.tile) && natural.tile !== 'O' && natural.tile !== 'W') {
            return { tile: this.groundFor(natural.biome, plane), level }; // 城壁のすぐ外は回り込めるように
        }
        return { tile: natural.tile, level };
    }

    // 街道の上で、隣の街道のマスと段が違えば階段
    isStairs(tx, ty, plane, level) {
        for (const [dx, dy] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
            const nx = tx + dx;
            const ny = ty + dy;
            const neighborCity = this.cityAt(nx, ny, 0);
            if (neighborCity && neighborCity.plane === plane) {
                const g = neighborCity.grid[(ny - neighborCity.ty) * neighborCity.w + (nx - neighborCity.tx)];
                if (g && neighborCity.level !== level) return true;
                if (g) continue;
            }
            if (!this.roadAt(nx, ny, plane)) continue;
            if (this.levelAt(nx, ny) !== level) return true;
        }
        return false;
    }

    // ---- チャンク ----------------------------------------------------------------------------

    generateChunk(cx, cy, size) {
        const tiles = new Array(size * size);
        const levels = new Int8Array(size * size);
        const x0 = cx * size;
        const y0 = cy * size;
        for (let y = 0; y < size; y++) {
            for (let x = 0; x < size; x++) {
                const c = this.cell(x0 + x, y0 + y);
                tiles[y * size + x] = c.tile;
                levels[y * size + x] = c.level;
            }
        }
        return { cx, cy, size, tiles, levels };
    }
}

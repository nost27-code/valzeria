// 街道沿いの寄り道：村・旅人の休み処・宿場町・風車小屋・見張り塔・道の祠・古い砦跡。
// 街道を一定の間隔でたどり、道の脇に小さな集落を毎回同じ形で置く（保存しない）。
// 置いた集落は都市と同じ形（layout）で FieldGenerator.cities に加える（minor: true）。
//   ・中は平らにならし、門から街道へ小道をつなぐ
//   ・住人が歩き、旅の祠（地図から飛べる）と道しるべ（両端の街までの方角と距離）がある
// 報酬には関わらない。

import { hashStr, mulberry } from './noise.js';
import { cityStyle } from './towns.js';

const SPACING = 1300;        // 街道に沿った間隔（マス）
const CITY_CLEARANCE = 260;  // 都市からこれより近くには置かない
const ENTRANCE_CLEARANCE = 70;
const PLACE_CLEARANCE = 480;

// 並べる順（街道ごとに少しずらす）。長い街道のまん中は宿場町
const CYCLE = ['rest_stop', 'hamlet', 'shrine', 'windmill', 'watchtower', 'hamlet', 'ruins', 'rest_stop', 'hamlet'];

const SIZES = {
    hamlet: [36, 28],
    rest_stop: [26, 20],
    inn: [48, 36],
    windmill: [28, 22],
    watchtower: [22, 22],
    shrine: [18, 18],
    ruins: [30, 24],
};

const NAMES = [
    'ミルトン', 'ハーヴェル', 'リンデ', 'オルム', 'カスティ', 'セルナ', 'ブラン', 'ヨルク', 'フィオネ', 'ドーラ',
    'ロッコ', 'ティルダ', 'マーシュ', 'エルム', 'クレア', 'ソレイユ', 'バルド', 'ノエラ', 'ガルム', 'ペルシェ',
    'アッシュ', 'リーヴァ', 'トーマ', 'ウィロウ', 'カノン', 'メイベル', 'オリバ', 'シエナ', 'フロウ', 'ダリア',
    'グレン', 'ルーナ', 'ヘイゼル', 'コルト', 'サリエ', 'ナディア', 'ベルク', 'イリス', 'モーゼル', 'ラウラ',
    'ホルン', 'エステル', 'ザック', 'ミレイ', 'ロウェナ', 'タリス', 'ギルダ', 'パロマ', 'ヴィオラ', 'クロード',
    'セージ', 'アルマ', 'フェン', 'ニコラ', 'オーレン', 'リタ', 'デュラン', 'カミラ', 'ユーリ', 'ハンナ',
];

const LABEL = {
    hamlet: (n) => `${n}村`,
    rest_stop: (n) => `${n}の休み処`,
    inn: (n) => `宿場町${n}`,
    windmill: (n) => `${n}の風車小屋`,
    watchtower: (n) => `${n}の見張り塔`,
    shrine: (n) => `${n}の祠`,
    ruins: (n) => `${n}砦跡`,
};

// 旅の祠がある場所（地図から飛べるようになる）
export const WAYSTONE_KINDS = new Set(['hamlet', 'rest_stop', 'inn', 'shrine']);

export function planWaysides(gen) {
    const places = [];
    const mainCities = gen.cities.filter((c) => !c.city.minor);
    const rand = mulberry(hashStr(`wayside:${gen.seed}`));
    let serial = 0;

    const tooClose = (plane, x, y) => {
        for (const c of mainCities) {
            if (c.plane !== plane) continue;
            if (x > c.tx - CITY_CLEARANCE && x < c.tx + c.w + CITY_CLEARANCE && y > c.ty - CITY_CLEARANCE && y < c.ty + c.h + CITY_CLEARANCE) return true;
        }
        for (const e of gen.def.entrances) {
            if (e.plane === plane && Math.hypot(e.tx - x, e.ty - y) < ENTRANCE_CLEARANCE) return true;
        }
        for (const p of places) {
            if (p.plane === plane && Math.hypot(p.cx - x, p.cy - y) < PLACE_CLEARANCE) return true;
        }
        return false;
    };

    // 足元が海・溶岩・岩山でないか（中心と四隅）
    const landOk = (x, y, w, h) => {
        for (const [dx, dy] of [[0, 0], [-w / 2, -h / 2], [w / 2, -h / 2], [-w / 2, h / 2], [w / 2, h / 2]]) {
            const t = gen.naturalCell(Math.round(x + dx), Math.round(y + dy)).tile;
            if ('OWXzM'.includes(t) || gen.riverAt(Math.round(x + dx), Math.round(y + dy), true)) return false;
        }
        return true;
    };

    for (const road of gen.roads) {
        if (road.kind !== 'road' || road.restricted) continue;
        const pts = road.points;
        const cum = [0];
        for (let i = 1; i < pts.length; i++) cum.push(cum[i - 1] + Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]));
        const length = cum[cum.length - 1];
        if (length < SPACING) continue;
        const roadHash = hashStr(road.key);
        const count = Math.floor(length / SPACING);
        const middle = Math.floor(count / 2);
        for (let k = 0; k < count; k++) {
            const base = (k + 0.5) * (length / count) + (rand() - 0.5) * 240;
            // 置けなければ、道に沿って前後へずらして探し直す
            for (const shift of [0, 160, -160, 320, -320, 480, -480]) {
                const s = Math.max(200, Math.min(length - 200, base + shift));
                const at = pointAt(pts, cum, s);
                let kind = k === middle && length > SPACING * 4 ? 'inn' : CYCLE[(roadHash + k) % CYCLE.length];
                const biome = gen.naturalCell(Math.round(at.x), Math.round(at.y)).biome;
                if (kind === 'windmill' && (biome === 9 || biome === 6 || biome === 8)) kind = 'rest_stop'; // 砂漠・雪原・火山に風車はない
                const [w, h] = SIZES[kind];
                let placed = null;
                for (const side of [(k % 2) * 2 - 1, 1 - (k % 2) * 2]) {
                    const offset = Math.max(w, h) / 2 + 7;
                    const cx = at.x + at.nx * side * offset;
                    const cy = at.y + at.ny * side * offset;
                    if (tooClose(road.plane, cx, cy) || !landOk(cx, cy, w, h) || crossesRoad(gen, road.plane, cx, cy, w, h)) continue;
                    placed = { cx, cy, side };
                    break;
                }
                if (!placed) continue;
                const name = NAMES[(serial * 37 + 11) % NAMES.length]; // 60個を重ならない順で使う
                serial++;
                const style = nearestStyle(mainCities, road.plane, placed.cx, placed.cy);
                // 街道がある向き（集落から見て）の門
                const toRoadX = -at.nx * placed.side;
                const toRoadY = -at.ny * placed.side;
                const dir = Math.abs(toRoadX) > Math.abs(toRoadY) ? (toRoadX < 0 ? 'w' : 'e') : toRoadY < 0 ? 'n' : 's';
                const layout = buildWayside({
                    id: `way_${serial}`,
                    kind,
                    name: LABEL[kind](name),
                    style,
                    plane: road.plane,
                    tx: Math.round(placed.cx - w / 2),
                    ty: Math.round(placed.cy - h / 2),
                    w,
                    h,
                    dir,
                    sign: signFor(gen, road, at, s, length),
                });
                layout.cx = placed.cx;
                layout.cy = placed.cy;
                layout.roadPoint = [at.x, at.y];
                places.push(layout);
                break;
            }
        }
    }
    return places;
}

// 集落の敷地（と2マスの余白）に街道がかかっていないか
function crossesRoad(gen, plane, cx, cy, w, h) {
    for (let y = cy - h / 2 - 2; y <= cy + h / 2 + 2; y += 3) {
        for (let x = cx - w / 2 - 2; x <= cx + w / 2 + 2; x += 3) {
            if (gen.roadAt(Math.round(x), Math.round(y), plane)) return true;
        }
    }
    return false;
}

function pointAt(pts, cum, s) {
    let i = 1;
    while (i < cum.length - 1 && cum[i] < s) i++;
    const [ax, ay] = pts[i - 1];
    const [bx, by] = pts[i];
    const seg = cum[i] - cum[i - 1] || 1;
    const k = Math.max(0, Math.min(1, (s - cum[i - 1]) / seg));
    const dx = (bx - ax) / seg;
    const dy = (by - ay) / seg;
    return { x: ax + (bx - ax) * k, y: ay + (by - ay) * k, nx: -dy, ny: dx };
}

function nearestStyle(cities, plane, x, y) {
    let best = null;
    for (const c of cities) {
        if (c.plane !== plane) continue;
        const d = Math.hypot(c.tx + c.w / 2 - x, c.ty + c.h / 2 - y);
        if (!best || d < best.d) best = { d, style: c.city.style };
    }
    const style = best?.style ?? 'royal_capital';
    // 魔王城・天空神殿のそばでも、街道沿いの集落はふつうの家並みにする
    return style === 'demon_castle' ? 'forge' : style;
}

// 道しるべ：この街道の両端の街（中継点）までの方角と、道のりのマス数
function signFor(gen, road, at, s, length) {
    const place = (key) => {
        const city = gen.cities.find((c) => c.city.key === key);
        if (city) return { name: city.city.name, x: city.tx + city.w / 2, y: city.ty + city.h / 2 };
        const wp = gen.def.waypoints[key];
        const names = { volcano_hub: '火山の麓（魔王城への道）', sky_tower: '天空の転移塔', sky_arrival: '天空の転移陣' };
        return wp ? { name: names[key] ?? key, x: wp.tx, y: wp.ty } : null;
    };
    const ends = [];
    const from = place(road.from);
    const to = place(road.to);
    if (from) ends.push({ name: from.name, dir: compass(from.x - at.x, from.y - at.y), distance: Math.round(s) });
    if (to) ends.push({ name: to.name, dir: compass(to.x - at.x, to.y - at.y), distance: Math.round(length - s) });
    return ends;
}

function compass(dx, dy) {
    const names = ['東', '南東', '南', '南西', '西', '北西', '北', '北東'];
    const a = Math.atan2(dy, dx);
    return names[(Math.round(a / (Math.PI / 4)) + 8) % 8];
}

// ---- 集落の間取り ---------------------------------------------------------------------------

export function buildWayside(p) {
    const { w, h, kind } = p;
    const palette = cityStyle(p.style);
    const rand = mulberry(hashStr(p.id));
    const grid = new Array(w * h).fill(null);
    const solid = new Uint8Array(w * h);
    const objects = [];
    const idx = (x, y) => y * w + x;
    const inBox = (x, y) => x >= 0 && y >= 0 && x < w && y < h;
    const set = (x, y, t) => {
        if (inBox(x, y)) grid[idx(x, y)] = t;
    };
    const fill = (x0, y0, rw, rh, t) => {
        for (let y = y0; y < y0 + rh; y++) for (let x = x0; x < x0 + rw; x++) set(x, y, t);
    };
    const add = (o) => {
        const obj = { solid: true, style: p.style, ...o };
        objects.push(obj);
        if (obj.solid) for (let y = obj.y; y < obj.y + obj.h; y++) for (let x = obj.x; x < obj.x + obj.w; x++) if (inBox(x, y)) solid[idx(x, y)] = 1;
        return obj;
    };
    const house = (x, y, hw = 7, hh = 5) => add({ type: 'house', x, y, w: hw, h: hh, variant: Math.floor(rand() * 1000), roof: palette.roofs[Math.floor(rand() * palette.roofs.length)], wall: palette.walls });
    const cx = Math.floor(w / 2);
    const cy = Math.floor(h / 2);
    const ground = palette.ground === 'N' || palette.ground === 'D' || palette.ground === 'K' ? palette.ground : 'G';

    // 門（街道の側）と、門から中央への道
    const gate = gateAt(p.dir, w, h, cx, cy);
    const walled = kind === 'hamlet' || kind === 'inn' || kind === 'watchtower' || kind === 'windmill';
    const wallTile = kind === 'inn' ? 'w' : 'f';

    fill(0, 0, w, h, ground);
    if (walled) {
        for (let x = 0; x < w; x++) {
            set(x, 0, wallTile);
            set(x, h - 1, wallTile);
        }
        for (let y = 0; y < h; y++) {
            set(0, y, wallTile);
            set(w - 1, y, wallTile);
        }
    }
    const path = (x0, y0, x1, y1, t = 'o') => {
        const steps = Math.max(Math.abs(x1 - x0), Math.abs(y1 - y0)) || 1;
        for (let i = 0; i <= steps; i++) {
            const x = Math.round(x0 + ((x1 - x0) * i) / steps);
            const y = Math.round(y0 + ((y1 - y0) * i) / steps);
            for (let d = -1; d <= 1; d++) {
                if (x0 === x1) set(x + d, y, t);
                else set(x, y + d, t);
            }
        }
    };
    path(gate.x, gate.y, cx, cy);
    for (let d = -2; d <= 2; d++) {
        if (p.dir === 'n' || p.dir === 's') set(gate.x + d, gate.y, 'o');
        else set(gate.x, gate.y + d, 'o');
    }

    const waystone = WAYSTONE_KINDS.has(kind);
    // 道しるべは門の外、街道へ出る小道の脇に立てる
    const outward = { n: [0, -2], s: [0, 2], w: [-2, 0], e: [2, 0] }[p.dir];
    const beside = p.dir === 'n' || p.dir === 's' ? [3, 0] : [0, 3];
    const signX = gate.x + outward[0] + beside[0];
    const signY = gate.y + outward[1] + beside[1];

    switch (kind) {
        case 'hamlet': {
            add({ type: 'well', x: cx - 1, y: cy - 1, w: 2, h: 2 });
            const spots = [[3, 3], [w - 11, 3], [3, h - 9], [w - 11, h - 9], [cx - 3, 3]];
            spots.slice(0, 4 + Math.floor(rand() * 2)).forEach(([x, y]) => house(x, y));
            fill(cx + 4, cy + 2, 7, 4, 'm');
            break;
        }
        case 'inn': {
            fill(2, cy - 2, w - 4, 4, 's');
            fill(cx - 2, 2, 4, h - 4, 's');
            add({ type: 'lodge', label: '宿場の宿屋', x: cx + 4, y: 3, w: 14, h: 9, variant: 7 });
            add({ type: 'stable', label: '馬屋', x: 3, y: 3, w: 10, h: 6 });
            house(3, cy + 4);
            house(w - 12, cy + 4);
            house(cx + 4, h - 9);
            for (let i = 0; i < 3; i++) add({ type: 'stall', x: 4 + i * 6, y: cy - 6, w: 3, h: 2, variant: i });
            add({ type: 'well', x: cx - 5, y: cy + 3, w: 2, h: 2 });
            break;
        }
        case 'rest_stop': {
            fill(cx - 7, cy - 5, 14, 10, 'o');
            add({ type: 'bonfire', x: cx - 1, y: cy - 1, w: 2, h: 2 });
            add({ type: 'tent', x: cx - 9, y: cy - 7, w: 4, h: 3, variant: 0 });
            add({ type: 'tent', x: cx + 5, y: cy - 7, w: 4, h: 3, variant: 2 });
            add({ type: 'cart', x: cx + 4, y: cy + 3, w: 3, h: 2 });
            add({ type: 'stall', label: '旅の行商人', x: cx - 8, y: cy + 3, w: 3, h: 2, variant: 1 });
            add({ type: 'bench', x: cx - 3, y: cy + 3, w: 2, h: 1 });
            break;
        }
        case 'windmill': {
            add({ type: 'windmill', label: p.name, x: cx - 3, y: 3, w: 6, h: 6 });
            fill(3, cy + 1, 8, h - cy - 3, 'm');
            fill(w - 11, cy + 1, 8, h - cy - 3, 'm');
            house(w - 10, 3, 7, 5);
            for (let i = 0; i < 3; i++) add({ type: 'haystack', x: 4 + i * 3, y: 4 + (i % 2), w: 2, h: 2 });
            break;
        }
        case 'watchtower': {
            add({ type: 'watchtower', label: p.name, x: cx - 3, y: 3, w: 6, h: 6 });
            add({ type: 'tent', x: 3, y: cy + 1, w: 4, h: 3, variant: 3 });
            add({ type: 'tent', x: w - 7, y: cy + 1, w: 4, h: 3, variant: 3 });
            add({ type: 'bonfire', x: cx - 1, y: cy + 3, w: 2, h: 2 });
            break;
        }
        case 'shrine': {
            for (let y = 0; y < h; y++) {
                for (let x = 0; x < w; x++) {
                    const d = Math.hypot(x + 0.5 - w / 2, y + 0.5 - h / 2);
                    if (d < 5) set(x, y, 'p');
                    else if (d < 8) set(x, y, rand() < 0.5 ? 'L' : ground);
                }
            }
            add({ type: 'shrine', label: p.name, x: cx - 2, y: cy - 4, w: 4, h: 3 });
            break;
        }
        case 'ruins': {
            for (let y = 1; y < h - 1; y++) {
                for (let x = 1; x < w - 1; x++) {
                    const edge = x === 1 || y === 1 || x === w - 2 || y === h - 2;
                    if (edge && rand() < 0.55) set(x, y, 'Q');
                    else if (rand() < 0.5) set(x, y, 'e');
                }
            }
            add({ type: 'statue', label: '崩れた英雄像', x: cx, y: cy - 2, w: 1, h: 2 });
            path(gate.x, gate.y, cx, cy, 'e');
            break;
        }
        default:
            break;
    }
    if (waystone) add({ type: 'waystone', label: '旅の祠', placeId: p.id, placeName: p.name, x: cx - 6, y: cy - 2, w: 2, h: 2 });
    add({ type: 'signpost', label: '道しるべ', sign: p.sign, placeName: p.name, x: signX, y: signY, w: 1, h: 1 });

    const out = { n: [0, -5], s: [0, 5], w: [-5, 0], e: [5, 0] }[p.dir];
    const layout = {
        city: { id: p.id, key: p.id, name: p.name, style: p.style, shape: 'rect', plane: p.plane, minor: true, kind },
        style: palette,
        w,
        h,
        tx: p.tx,
        ty: p.ty,
        grid,
        solid,
        objects: objects.map((o) => ({ ...o, tx: p.tx + o.x, ty: p.ty + o.y, tw: o.w, th: o.h, placeId: p.id })),
        gates: [{ dir: p.dir, x: gate.x, y: gate.y, tx: p.tx + gate.x, ty: p.ty + gate.y, outTx: p.tx + gate.x + out[0], outTy: p.ty + gate.y + out[1] }],
        harbor: null,
        plane: p.plane,
    };
    return layout;
}

function gateAt(dir, w, h, cx, cy) {
    if (dir === 'n') return { x: cx, y: 0 };
    if (dir === 's') return { x: cx, y: h - 1 };
    if (dir === 'w') return { x: 0, y: cy };
    return { x: w - 1, y: cy };
}

// 都市の街並み。都市の定義（サーバーの cities）から、外周・門・通り・広場・名所・施設・家並みを毎回同じ形に作る。
// 座標はすべて都市の左上からのマス（ローカル）で作り、最後に絶対座標の物（objects）へ直す。
//
// 画像素材が届いたら renderer.js の描画を差し替える（物の type / style / variant で見分けられるようにしてある）。

import { hashStr, mulberry } from './noise.js';

// 区画の使い道
const FREE = 0;
const STREET = 1;
const WALL = 2;
const RESERVED = 3; // 名所など
const BUILDING = 4;
const YARD = 5;     // 建物のまわり（他の建物は建てないが歩ける）
const KEEP_CLEAR = 6; // 扉の前など、飾りも置かない

// 街並みの種類ごとの材料
const STYLES = {
    royal_capital: { ground: 'o', street: 's', plaza: 'p', wall: 'x', roofs: ['#3f5f9e', '#b04a3a', '#3f7a8e', '#8e3f5a'], walls: '#e6dcc6' },
    port: { ground: 'o', street: 's', plaza: 'p', wall: 'w', roofs: ['#c0582f', '#d07a3a', '#3f6fa0', '#a0402f'], walls: '#f0e6d2' },
    tree_city: { ground: 'q', street: 'o', plaza: 'q', wall: 'h', roofs: ['#4f8a3a', '#6aa04a', '#8a6a3a', '#3f7a5a'], walls: '#b8905a' },
    forge: { ground: 'o', street: 's', plaza: 'p', wall: 'i', roofs: ['#5a4a44', '#7a4a3a', '#4a4a52', '#6a3a2a'], walls: '#a89a88' },
    snow: { ground: 'N', street: 's', plaza: 'p', wall: 'w', roofs: ['#5a7aa0', '#3f5a7a', '#8a5a4a', '#4a6a8a'], walls: '#d8d0c0' },
    oasis: { ground: 'D', street: 'o', plaza: 'p', wall: 'w', roofs: ['#d8b070', '#c89a5a', '#b87a4a', '#e0c080'], walls: '#e8d2a4' },
    academy: { ground: 'q', street: 's', plaza: 'e', wall: 'x', roofs: ['#5a4aa0', '#3f3f8a', '#7a4aa0', '#4a5aa0'], walls: '#e4e0ee' },
    necro: { ground: 'K', street: 'j', plaza: 'j', wall: 'x', roofs: ['#3a2f4a', '#4a2f3f', '#2f2f3f', '#5a3a5a'], walls: '#7a7080' },
    sky_temple: { ground: 'e', street: 'p', plaza: 'e', wall: 'x', roofs: ['#6aa0d8', '#e8c860', '#8ac0e8', '#d8e8f0'], walls: '#f6f4ee' },
    demon_castle: { ground: 'V', street: 'j', plaza: 'j', wall: 'x', roofs: ['#4a2030', '#3a2a3a', '#5a2a2a', '#2a2030'], walls: '#5a4a50' },
};

// 施設の並べ方（広場に近い順に置く）
const FACILITY_PRIORITY = [
    'inn', 'equipment_shop', 'temple', 'blacksmith', 'supply', 'tavern', 'synthesis', 'material_exchange',
    'apothecary', 'bank', 'guide', 'map_house', 'ranking_board', 'valmon_farm', 'training_ground',
];

export function cityStyle(style) {
    return STYLES[style] ?? STYLES.royal_capital;
}

export function buildCity(city) {
    const { w, h } = city;
    const style = cityStyle(city.style);
    const rand = mulberry(hashStr(`city:${city.key}`));
    const grid = new Array(w * h).fill(null);   // null = 自然の地形のまま（丸い城壁の外）
    const use = new Uint8Array(w * h);
    const solid = new Uint8Array(w * h);
    const objects = [];
    const cx = Math.floor(w / 2);
    const cy = Math.floor(h / 2);
    const round = city.shape === 'round';

    const idx = (x, y) => y * w + x;
    const inBox = (x, y) => x >= 0 && y >= 0 && x < w && y < h;
    // 丸い都市は楕円の中だけ（inset マス内側の楕円）
    const within = (x, y, inset = 0) => {
        if (!inBox(x, y)) return false;
        if (!round) return x >= inset && y >= inset && x < w - inset && y < h - inset;
        const rx = w / 2 - inset;
        const ry = h / 2 - inset;
        const dx = (x + 0.5 - w / 2) / rx;
        const dy = (y + 0.5 - h / 2) / ry;
        return dx * dx + dy * dy <= 1;
    };
    const set = (x, y, tile, kind) => {
        if (!inBox(x, y)) return;
        grid[idx(x, y)] = tile;
        if (kind !== undefined) use[idx(x, y)] = kind;
    };
    const fillRect = (x0, y0, rw, rh, tile, kind) => {
        for (let y = y0; y < y0 + rh; y++) for (let x = x0; x < x0 + rw; x++) if (within(x, y)) set(x, y, tile, kind);
    };
    const fillCircle = (px, py, r, tile, kind) => {
        for (let y = Math.floor(py - r); y <= Math.ceil(py + r); y++) {
            for (let x = Math.floor(px - r); x <= Math.ceil(px + r); x++) {
                if ((x + 0.5 - px) ** 2 + (y + 0.5 - py) ** 2 <= r * r && within(x, y)) set(x, y, tile, kind);
            }
        }
    };
    const addObject = (o) => {
        const obj = { solid: true, ...o };
        objects.push(obj);
        if (obj.solid) {
            for (let y = obj.y; y < obj.y + obj.h; y++) {
                for (let x = obj.x; x < obj.x + obj.w; x++) {
                    if (inBox(x, y)) {
                        solid[idx(x, y)] = 1;
                        if (use[idx(x, y)] === FREE || use[idx(x, y)] === YARD) use[idx(x, y)] = BUILDING;
                    }
                }
            }
        }
        return obj;
    };

    // ---- 地面・外周・門 --------------------------------------------------------------
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            if (!within(x, y)) continue;
            const edge = !within(x, y, 2);
            set(x, y, edge ? style.wall : style.ground, edge ? WALL : FREE);
        }
    }

    const gateDirs = (city.gates ?? ['n', 's', 'e', 'w']).filter((d) => !(city.harbor === 'west' && d === 'w'));
    const gates = [];
    const GATE_HALF = 3;
    for (const dir of gateDirs) {
        const vertical = dir === 'n' || dir === 's';
        let gx = dir === 'w' ? 0 : dir === 'e' ? w - 1 : cx;
        let gy = dir === 'n' ? 0 : dir === 's' ? h - 1 : cy;
        // 丸い都市の門は楕円の縁
        if (round) {
            if (dir === 'n') gy = firstInside(within, cx, 0, 0, 1);
            if (dir === 's') gy = firstInside(within, cx, h - 1, 0, -1);
            if (dir === 'w') gx = firstInside(within, 0, cy, 1, 0);
            if (dir === 'e') gx = firstInside(within, w - 1, cy, -1, 0);
        }
        for (let d = -GATE_HALF; d < GATE_HALF; d++) {
            for (let k = 0; k < 4; k++) {
                const x = vertical ? gx + d : gx + (dir === 'w' ? k : -k);
                const y = vertical ? gy + (dir === 'n' ? k : -k) : gy + d;
                if (inBox(x, y)) set(x, y, style.street, STREET);
            }
        }
        const out = { n: [0, -6], s: [0, 6], w: [-6, 0], e: [6, 0] }[dir];
        gates.push({ dir, x: gx, y: gy, outX: gx + out[0], outY: gy + out[1] });
        // 門の両脇の塔
        if (vertical) {
            addObject({ type: 'gate_tower', style: city.style, x: gx - GATE_HALF - 3, y: dir === 'n' ? gy : gy - 2, w: 3, h: 3 });
            addObject({ type: 'gate_tower', style: city.style, x: gx + GATE_HALF, y: dir === 'n' ? gy : gy - 2, w: 3, h: 3 });
        } else {
            addObject({ type: 'gate_tower', style: city.style, x: dir === 'w' ? gx : gx - 2, y: gy - GATE_HALF - 3, w: 3, h: 3 });
            addObject({ type: 'gate_tower', style: city.style, x: dir === 'w' ? gx : gx - 2, y: gy + GATE_HALF, w: 3, h: 3 });
        }
    }

    // ---- 名所（街並みの種類ごと） ----------------------------------------------------------
    const plaza = { x: cx, y: cy, r: 15 };
    const landmark = LANDMARKS[city.style] ?? LANDMARKS.royal_capital;
    landmark({ city, style, w, h, cx, cy, plaza, rand, set, fillRect, fillCircle, addObject, within, use, grid, idx });

    // ---- 大通り（門から広場まで） ----------------------------------------------------------
    const avenue = (x0, y0, x1, y1, half = 3) => {
        const steps = Math.max(Math.abs(x1 - x0), Math.abs(y1 - y0));
        for (let i = 0; i <= steps; i++) {
            const x = Math.round(x0 + ((x1 - x0) * i) / Math.max(1, steps));
            const y = Math.round(y0 + ((y1 - y0) * i) / Math.max(1, steps));
            for (let dy = -half; dy < half; dy++) {
                for (let dx = -half; dx < half; dx++) {
                    const u = use[idx(Math.min(w - 1, Math.max(0, x + dx)), Math.min(h - 1, Math.max(0, y + dy)))];
                    if (within(x + dx, y + dy, 2) && (u === FREE || u === STREET)) set(x + dx, y + dy, style.street, STREET);
                }
            }
        }
    };
    for (const g of gates) avenue(g.x, g.y, cx, cy);
    // 広場（名所が広場を持たない街並みだけ）
    if (!plaza.custom) {
        for (let y = cy - plaza.r; y <= cy + plaza.r; y++) {
            for (let x = cx - plaza.r; x <= cx + plaza.r; x++) {
                const inside = (x + 0.5 - cx) ** 2 + (y + 0.5 - cy) ** 2 <= plaza.r * plaza.r;
                if (inside && within(x, y, 2) && (use[idx(x, y)] === FREE || use[idx(x, y)] === STREET)) set(x, y, style.plaza, STREET);
            }
        }
        if (!plaza.noFountain) {
            fillCircle(cx, cy, 3.2, 'u', RESERVED);
            addObject({ type: 'fountain', style: city.style, x: cx - 3, y: cy - 3, w: 6, h: 6, solid: false });
        }
    }

    // ---- 裏通り（格子） -----------------------------------------------------------------
    const streetTile = style.street;
    for (let y = cy % 26; y < h; y += 26) {
        for (let x = 0; x < w; x++) {
            for (let k = 0; k < 3; k++) {
                const yy = y + k;
                if (within(x, yy, 3) && use[idx(x, yy)] === FREE) set(x, yy, streetTile, STREET);
            }
        }
    }
    for (let x = cx % 32; x < w; x += 32) {
        for (let y = 0; y < h; y++) {
            for (let k = 0; k < 3; k++) {
                const xx = x + k;
                if (within(xx, y, 3) && use[idx(xx, y)] === FREE) set(xx, y, streetTile, STREET);
            }
        }
    }

    // ---- 建物を置く -------------------------------------------------------------------
    const canPlace = (x, y, bw, bh) => {
        if (!within(x - 1, y - 1, 3) || !within(x + bw, y + bh, 3) || !within(x + bw, y - 1, 3) || !within(x - 1, y + bh, 3)) return false;
        for (let yy = y - 1; yy <= y + bh; yy++) {
            for (let xx = x - 1; xx <= x + bw; xx++) {
                const u = use[idx(xx, yy)];
                const edge = yy === y - 1 || yy === y + bh || xx === x - 1 || xx === x + bw;
                if (edge ? !(u === FREE || u === STREET || u === YARD) : u !== FREE) return false;
            }
        }
        // 扉の前（南）は通れること
        const doorFront = use[idx(x + Math.floor(bw / 2), y + bh)];
        return doorFront === FREE || doorFront === STREET || doorFront === YARD;
    };
    const claim = (x, y, bw, bh) => {
        for (let yy = y - 1; yy <= y + bh; yy++) {
            for (let xx = x - 1; xx <= x + bw; xx++) {
                if (!inBox(xx, yy)) continue;
                if (use[idx(xx, yy)] === FREE) use[idx(xx, yy)] = YARD;
            }
        }
        const dx = x + Math.floor(bw / 2);
        const dy = y + bh;
        if (use[idx(dx, dy)] !== STREET) {
            use[idx(dx, dy)] = KEEP_CLEAR;
            set(dx, dy, style.street);
        }
    };
    // (nx, ny) に近い順に空きを探す
    const findSpot = (bw, bh, nx, ny, maxR = Math.max(w, h)) => {
        for (let r = 0; r < maxR; r += 2) {
            const cand = [];
            for (let dy = -r; dy <= r; dy += 2) {
                for (let dx = -r; dx <= r; dx += 2) {
                    if (Math.max(Math.abs(dx), Math.abs(dy)) !== r && r !== 0) continue;
                    cand.push([nx + dx - Math.floor(bw / 2), ny + dy - bh]);
                }
            }
            for (const [x, y] of cand) if (canPlace(x, y, bw, bh)) return [x, y];
        }
        return null;
    };

    // 施設：広場の周りに近い順
    const facilities = [...(city.facilities ?? [])].sort((a, b) => FACILITY_PRIORITY.indexOf(a.slug) - FACILITY_PRIORITY.indexOf(b.slug));
    facilities.forEach((f, i) => {
        const [fw, fh] = f.size;
        const angle = (i / facilities.length) * Math.PI * 2 + 0.4;
        const ring = plaza.r + 10 + (i % 3) * 6;
        const nx = Math.round(cx + Math.cos(angle) * ring * 1.3);
        const ny = Math.round(cy + Math.sin(angle) * ring);
        const spot = findSpot(fw, fh, nx, ny);
        if (!spot) return;
        const [x, y] = spot;
        addObject({ type: f.slug === 'ranking_board' ? 'board' : 'facility', facility: f.slug, label: f.label, style: city.style, x, y, w: fw, h: fh, variant: i });
        claim(x, y, fw, fh);
    });

    // 家並み
    const houseTarget = Math.floor((w * h) / 150 * (HOUSE_DENSITY[city.style] ?? 1));
    let houses = 0;
    for (let y = 4; y < h - 4 && houses < houseTarget; y++) {
        for (let x = 4; x < w - 4 && houses < houseTarget; x++) {
            if (use[idx(x, y)] !== FREE || rand() > 0.5) continue;
            const bw = 6 + Math.floor(rand() * 5);
            const bh = 5 + Math.floor(rand() * 3);
            if (!canPlace(x, y, bw, bh)) continue;
            addObject({ type: 'house', style: city.style, x, y, w: bw, h: bh, variant: Math.floor(rand() * 1000), roof: style.roofs[Math.floor(rand() * style.roofs.length)], wall: style.walls });
            claim(x, y, bw, bh);
            houses++;
            x += bw;
        }
    }

    // ---- 飾り：街灯・木・樽・花壇 ---------------------------------------------------------
    for (let y = 3; y < h - 3; y++) {
        for (let x = 3; x < w - 3; x++) {
            const u = use[idx(x, y)];
            if (u === STREET && x % 9 === 0 && y % 9 === 0 && isStreetEdge(use, idx, x, y, w, h)) {
                addObject({ type: 'lamp', style: city.style, x, y, w: 1, h: 1 });
                continue;
            }
            if (u !== FREE) continue;
            const r = rand();
            if (r < 0.015 * (TREE_DENSITY[city.style] ?? 1)) {
                const tree = DECOR_TREES[city.style] ?? 't';
                set(x, y, tree);
                solid[idx(x, y)] = tree === 't' || tree === 'P' || tree === 'I' || tree === 'Z' ? 1 : 0;
                use[idx(x, y)] = RESERVED;
            } else if (r < 0.021) {
                addObject({ type: 'barrel', style: city.style, x, y, w: 1, h: 1 });
            } else if (r < 0.026 && style.ground !== 'D') {
                addObject({ type: 'flowerbed', style: city.style, x, y, w: 1, h: 1, solid: false });
            }
        }
    }

    // ---- 絶対座標にする ---------------------------------------------------------------
    const abs = objects.map((o) => ({ ...o, tx: city.tx + o.x, ty: city.ty + o.y, tw: o.w, th: o.h, cityId: city.id }));
    return {
        city,
        style,
        w,
        h,
        tx: city.tx,
        ty: city.ty,
        grid,
        solid,
        objects: abs,
        gates: gates.map((g) => ({ ...g, tx: city.tx + g.x, ty: city.ty + g.y, outTx: city.tx + g.outX, outTy: city.ty + g.outY })),
        harbor: city.harbor ?? null,
    };
}

// 家の多さ・木の多さ（王都を 1 として）
const HOUSE_DENSITY = { tree_city: 0.55, oasis: 0.75, sky_temple: 0.6, demon_castle: 0.7, snow: 0.85 };
const TREE_DENSITY = { tree_city: 4, oasis: 1.5, snow: 2.5, sky_temple: 2, necro: 2 };
const DECOR_TREES = { snow: 'I', oasis: 'P', necro: 'Z', demon_castle: 'Z', tree_city: 't', sky_temple: 't' };

function firstInside(within, x, y, dx, dy) {
    let px = x;
    let py = y;
    for (let i = 0; i < 400; i++) {
        if (within(px, py)) return dx !== 0 ? px : py;
        px += dx;
        py += dy;
    }
    return dx !== 0 ? x : y;
}

function isStreetEdge(use, idx, x, y, w, h) {
    for (const [dx, dy] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
        const nx = x + dx;
        const ny = y + dy;
        if (nx < 0 || ny < 0 || nx >= w || ny >= h) continue;
        if (use[idx(nx, ny)] !== 1) return true;
    }
    return false;
}

// ---- 街並みの種類ごとの名所 ---------------------------------------------------------------------
// どれも「名所の区画を RESERVED にして物を置く」。広場の形を変える時は plaza を書き換える。

const LANDMARKS = {
    // 王都アークレア：北に城壁で囲んだ王城、広場のまわりに市場
    royal_capital({ city, cx, fillRect, addObject, set, plaza, rand }) {
        const x0 = cx - 46;
        const y0 = 14;
        const cw = 92;
        const ch = 62;
        fillRect(x0, y0, cw, ch, 'q', RESERVED);
        for (let x = x0; x < x0 + cw; x++) for (const y of [y0, y0 + 1, y0 + ch - 2, y0 + ch - 1]) set(x, y, 'x', WALL);
        for (let y = y0; y < y0 + ch; y++) for (const x of [x0, x0 + 1, x0 + cw - 2, x0 + cw - 1]) set(x, y, 'x', WALL);
        // 城門と城への参道
        fillRect(cx - 4, y0 + ch - 2, 8, 2, 's', STREET);
        fillRect(cx - 3, y0 + 36, 6, ch - 36, 's', STREET);
        fillRect(cx - 16, y0 + 36, 32, 5, 'p', STREET);
        addObject({ type: 'castle', style: city.style, label: city.landmark, x: cx - 26, y: y0 + 4, w: 52, h: 32 });
        // 庭園の生け垣と噴水
        for (const side of [-1, 1]) {
            const gx = cx + side * 30;
            fillRect(gx - 8, y0 + 42, 16, 1, 'h', RESERVED);
            fillRect(gx - 8, y0 + 52, 16, 1, 'h', RESERVED);
            addObject({ type: 'fountain', style: city.style, x: gx - 2, y: y0 + 45, w: 4, h: 4 });
            addObject({ type: 'statue', style: city.style, x: gx, y: y0 + 38, w: 1, h: 2 });
        }
        for (const corner of [[x0, y0], [x0 + cw - 5, y0], [x0, y0 + ch - 5], [x0 + cw - 5, y0 + ch - 5]]) {
            addObject({ type: 'wall_tower', style: city.style, x: corner[0], y: corner[1], w: 5, h: 5 });
        }
        // 城から広場へ下る大通り
        fillRect(cx - 3, y0 + ch, 6, plaza.y - (y0 + ch), 's', STREET);
        // 広場の市場
        for (let i = 0; i < 10; i++) {
            const a = (i / 10) * Math.PI * 2 + rand() * 0.1;
            const x = Math.round(cx + Math.cos(a) * (plaza.r - 4));
            const y = Math.round(plaza.y + Math.sin(a) * (plaza.r - 4));
            if (Math.abs(Math.cos(a)) > 0.92 || Math.abs(Math.sin(a)) > 0.92) continue; // 大通りはあける
            addObject({ type: 'stall', style: city.style, x: x - 1, y: y - 1, w: 3, h: 2, variant: i });
        }
        plaza.r = 17;
    },

    // 港町マリネス：西が港。桟橋・帆船・灯台・魚市場
    port({ city, w, h, fillRect, addObject, set }) {
        const quay = 36;
        fillRect(0, 2, quay, h - 4, 'W', RESERVED);
        fillRect(quay, 2, 4, h - 4, 'k', STREET);
        const piers = [Math.floor(h * 0.22), Math.floor(h * 0.45), Math.floor(h * 0.68)];
        piers.forEach((py, i) => {
            fillRect(4, py, quay - 4, 4, 'k', STREET);
            addObject({ type: 'ship', style: city.style, x: 8, y: py - 9, w: 22, h: 8, variant: i, label: i === 1 ? '定期船' : null });
            for (let k = 0; k < 4; k++) addObject({ type: 'crate', style: city.style, x: quay - 2 - k * 5, y: py + 4, w: 1, h: 1 });
        });
        // 灯台（北西の突堤の先）
        fillRect(2, 4, 10, 10, 'p', STREET);
        fillRect(10, 8, quay - 10, 3, 'k', STREET);
        addObject({ type: 'lighthouse', style: city.style, label: city.landmark, x: 4, y: 4, w: 6, h: 7 });
        // 港に沿って魚市場の屋台
        for (let i = 0; i < 7; i++) {
            addObject({ type: 'stall', style: city.style, x: quay + 6, y: 10 + i * 16, w: 3, h: 2, variant: i });
        }
        // 城壁は港側で切れている
        for (let y = 0; y < h; y++) for (const x of [0, 1]) set(x, y, 'W', RESERVED);
    },

    // 精霊の森エルフィア：中心に世界樹の幹。根の間に広場、生け垣の城壁
    tree_city({ city, cx, cy, fillCircle, addObject, plaza, rand }) {
        fillCircle(cx, cy, 36, 'q', 1);
        fillCircle(cx, cy, 30, 'p', 1);
        fillCircle(cx, cy, 20, 'F', RESERVED);
        addObject({ type: 'world_tree', style: city.style, label: city.landmark, x: cx - 18, y: cy - 18, w: 36, h: 36 });
        for (let i = 0; i < 16; i++) {
            const a = (i / 16) * Math.PI * 2;
            const x = Math.round(cx + Math.cos(a) * 26);
            const y = Math.round(cy + Math.sin(a) * 26);
            addObject({ type: 'spirit_lantern', style: city.style, x, y, w: 1, h: 1 });
        }
        for (let i = 0; i < 24; i++) {
            const a = rand() * Math.PI * 2;
            const r = 40 + rand() * 40;
            addObject({ type: 'flowerbed', style: city.style, x: Math.round(cx + Math.cos(a) * r), y: Math.round(cy + Math.sin(a) * r * 0.9), w: 1, h: 1, solid: false });
        }
        plaza.custom = true;
        plaza.r = 36;
    },

    // 鍛冶街グランベルグ：北に大溶鉱炉と溶岩の水路、煙突、鉱山の門
    forge({ city, cx, fillRect, addObject, w }) {
        fillRect(cx - 34, 6, 68, 34, 'j', RESERVED);
        addObject({ type: 'furnace', style: city.style, label: city.landmark, x: cx - 17, y: 8, w: 34, h: 22 });
        fillRect(cx - 34, 34, 68, 3, 'X', RESERVED);
        fillRect(cx - 3, 34, 6, 3, 'c', STREET);
        fillRect(cx - 3, 30, 6, 4, 's', STREET);
        for (const x of [cx - 30, cx - 24, cx + 22, cx + 28]) addObject({ type: 'chimney', style: city.style, x, y: 12, w: 3, h: 3 });
        addObject({ type: 'mine_gate', style: city.style, label: '鉱山の門', x: w - 26, y: 6, w: 10, h: 7 });
        fillRect(w - 23, 13, 4, 12, 's', STREET);
        for (let i = 0; i < 6; i++) addObject({ type: 'anvil', style: city.style, x: cx - 26 + i * 10, y: 42, w: 2, h: 1 });
    },

    // 雪原の町フロストリア：北に氷晶の大聖堂、広場に氷の像
    snow({ city, cx, cy, fillRect, addObject, plaza }) {
        fillRect(cx - 24, 8, 48, 34, 'p', RESERVED);
        addObject({ type: 'cathedral', style: city.style, label: city.landmark, x: cx - 16, y: 10, w: 32, h: 24 });
        fillRect(cx - 3, 34, 6, cy - 34, 's', 1);
        plaza.noFountain = true;
        addObject({ type: 'ice_statue', style: city.style, x: cx - 2, y: cy - 2, w: 4, h: 4 });
    },

    // 砂漠の宿場サンドラ：中心にオアシスの泉、ヤシ、市場の天幕
    oasis({ city, cx, cy, fillCircle, addObject, plaza, rand, set }) {
        fillCircle(cx, cy, 32, 'p', 1);
        fillCircle(cx, cy, 22, 'A', RESERVED);
        fillCircle(cx, cy, 19, 'l', RESERVED);
        for (let i = 0; i < 18; i++) {
            const a = (i / 18) * Math.PI * 2;
            set(Math.round(cx + Math.cos(a) * 21), Math.round(cy + Math.sin(a) * 21), 'P', RESERVED);
        }
        addObject({ type: 'oasis_shrine', style: city.style, label: city.landmark, x: cx - 3, y: cy - 3, w: 6, h: 6 });
        for (let i = 0; i < 14; i++) {
            const a = (i / 14) * Math.PI * 2 + 0.2;
            if (Math.abs(Math.cos(a)) > 0.93 || Math.abs(Math.sin(a)) > 0.93) continue;
            addObject({ type: 'tent', style: city.style, x: Math.round(cx + Math.cos(a) * 28) - 2, y: Math.round(cy + Math.sin(a) * 28) - 1, w: 4, h: 3, variant: Math.floor(rand() * 4) });
        }
        plaza.custom = true;
        plaza.r = 32;
    },

    // 魔導学院ルミナス：北に大魔導塔と学舎、大理石の中庭と魔法陣
    academy({ city, cx, cy, fillRect, fillCircle, addObject }) {
        fillCircle(cx, 34, 26, 'e', RESERVED);
        addObject({ type: 'magic_tower', style: city.style, label: city.landmark, x: cx - 10, y: 12, w: 20, h: 26 });
        for (const side of [-1, 1]) {
            fillRect(cx + side * 44 - 18, 14, 36, 24, 'e', RESERVED);
            addObject({ type: 'academy_hall', style: city.style, label: side < 0 ? '学舎（東館）' : '学舎（西館）', x: cx + side * 44 - 16, y: 16, w: 32, h: 18 });
        }
        fillRect(cx - 3, 58, 6, cy - 58, 's', 1);
        for (const [dx, dy] of [[-30, 20], [30, 20], [-30, -20], [30, -20]]) {
            addObject({ type: 'magic_circle', style: city.style, x: cx + dx - 2, y: cy + dy - 2, w: 4, h: 4, solid: false });
        }
    },

    // 死霊街ネクロム：北に黒曜の大聖堂、北東に墓地
    necro({ city, w, cx, fillRect, addObject }) {
        fillRect(cx - 24, 8, 48, 34, 'j', RESERVED);
        addObject({ type: 'cathedral', style: city.style, label: city.landmark, x: cx - 17, y: 10, w: 34, h: 24 });
        fillRect(cx - 3, 34, 6, 30, 'j', 1);
        const gx = w - 50;
        fillRect(gx, 8, 42, 36, 'K', RESERVED);
        for (let y = 12; y < 40; y += 5) {
            for (let x = gx + 3; x < gx + 40; x += 5) addObject({ type: 'tombstone', style: city.style, x, y, w: 1, h: 1 });
        }
        addObject({ type: 'mausoleum', style: city.style, label: '霊廟', x: gx + 16, y: 10, w: 10, h: 7 });
    },

    // 天空神殿セレスティア：北に天空大神殿、雲の泉と天使像
    sky_temple({ city, cx, cy, fillRect, fillCircle, addObject }) {
        fillRect(cx - 34, 10, 68, 42, 'p', RESERVED);
        addObject({ type: 'sky_temple', style: city.style, label: city.landmark, x: cx - 24, y: 12, w: 48, h: 30 });
        fillRect(cx - 3, 42, 6, cy - 42, 'p', 1);
        for (const side of [-1, 1]) {
            fillCircle(cx + side * 44, cy + 20, 7, 'l', RESERVED);
            addObject({ type: 'angel_statue', style: city.style, x: cx + side * 44 - 1, y: cy + 8, w: 2, h: 3 });
        }
    },

    // 魔王城ヴァルゼリア：北に魔王城の本丸、南は冒険者たちの前線基地
    demon_castle({ city, w, cx, cy, fillRect, addObject, plaza, set }) {
        fillRect(8, 6, w - 16, 64, 'j', RESERVED);
        for (let x = 8; x < w - 8; x++) set(x, 70, 'X', RESERVED);
        fillRect(cx - 4, 68, 8, 4, 'c', 1);
        addObject({ type: 'demon_castle', style: city.style, label: city.landmark, x: cx - 45, y: 8, w: 90, h: 52 });
        fillRect(cx - 4, 60, 8, 8, 'j', 1);
        plaza.noFountain = true;
        addObject({ type: 'bonfire', style: city.style, x: cx - 1, y: cy + 10, w: 2, h: 2 });
        for (let i = 0; i < 8; i++) {
            addObject({ type: 'tent', style: city.style, x: 16 + i * 22, y: cy + 36, w: 4, h: 3, variant: i % 4 });
        }
    },
};

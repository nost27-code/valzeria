// 折れ線（街道・川）の近さを速く調べるための道具。

import { hash2i } from './noise.js';

// 点 (x, y) から線分 a-b への距離と、線分上の位置 t（0〜1）
export function segmentDistance(x, y, ax, ay, bx, by) {
    const dx = bx - ax;
    const dy = by - ay;
    const len2 = dx * dx + dy * dy;
    let t = len2 === 0 ? 0 : ((x - ax) * dx + (y - ay) * dy) / len2;
    t = Math.max(0, Math.min(1, t));
    return { d: Math.hypot(x - (ax + dx * t), y - (ay + dy * t)), t };
}

/**
 * 折れ線をくねらせる（中点をずらして2つに分けることを depth 回くり返す）。
 * 端の点は動かさない。amount は区間の長さに対するずらし幅の割合。
 */
export function meander(points, seed, depth = 3, amount = 0.14, minLength = 24) {
    let pts = points.map(([x, y]) => [x, y]);
    for (let level = 0; level < depth; level++) {
        const next = [pts[0]];
        for (let i = 0; i + 1 < pts.length; i++) {
            const [ax, ay] = pts[i];
            const [bx, by] = pts[i + 1];
            const len = Math.hypot(bx - ax, by - ay);
            if (len >= minLength) {
                const r = hash2i(Math.round(ax + bx), Math.round(ay + by), seed + level * 31) * 2 - 1;
                const off = r * amount * len;
                next.push([(ax + bx) / 2 + (-(by - ay) / len) * off, (ay + by) / 2 + ((bx - ax) / len) * off]);
            }
            next.push(pts[i + 1]);
        }
        pts = next;
    }
    return pts;
}

// 折れ線の長さに沿った位置（0〜1）ごとの累積長
export function cumulativeLengths(points) {
    const out = [0];
    for (let i = 1; i < points.length; i++) {
        out.push(out[i - 1] + Math.hypot(points[i][0] - points[i - 1][0], points[i][1] - points[i - 1][1]));
    }
    return out;
}

/**
 * 線分をバケツに入れておき、あるマスの近くの線分だけを調べる。
 * 線分には任意の情報（どの道か、幅など）を持たせる。
 */
export class SegmentIndex {
    constructor(bucket = 256) {
        this.bucket = bucket;
        this.cells = new Map();
        this.segments = [];
    }

    // reach: この線分がマスに影響する最大の距離（幅の半分 + 余白）
    add(ax, ay, bx, by, reach, data) {
        const seg = { ax, ay, bx, by, reach, data };
        this.segments.push(seg);
        const b = this.bucket;
        const x0 = Math.floor((Math.min(ax, bx) - reach) / b);
        const x1 = Math.floor((Math.max(ax, bx) + reach) / b);
        const y0 = Math.floor((Math.min(ay, by) - reach) / b);
        const y1 = Math.floor((Math.max(ay, by) + reach) / b);
        for (let y = y0; y <= y1; y++) {
            for (let x = x0; x <= x1; x++) {
                const key = x * 100003 + y;
                let list = this.cells.get(key);
                if (!list) this.cells.set(key, (list = []));
                list.push(seg);
            }
        }
        return seg;
    }

    // 近い線分（reach の中にあるもの）を { seg, d, t } で返す
    near(x, y) {
        const key = Math.floor(x / this.bucket) * 100003 + Math.floor(y / this.bucket);
        const list = this.cells.get(key);
        if (!list) return [];
        const out = [];
        for (const seg of list) {
            if (x < Math.min(seg.ax, seg.bx) - seg.reach || x > Math.max(seg.ax, seg.bx) + seg.reach) continue;
            if (y < Math.min(seg.ay, seg.by) - seg.reach || y > Math.max(seg.ay, seg.by) + seg.reach) continue;
            const { d, t } = segmentDistance(x, y, seg.ax, seg.ay, seg.bx, seg.by);
            if (d <= seg.reach) out.push({ seg, d, t });
        }
        return out;
    }

    // 最も近い線分（filter に合うもの）。全件を調べるので初期化の時だけ使う
    nearest(x, y, filter = () => true) {
        let best = null;
        for (const seg of this.segments) {
            if (!filter(seg)) continue;
            const { d, t } = segmentDistance(x, y, seg.ax, seg.ay, seg.bx, seg.by);
            if (!best || d < best.d) best = { seg, d, t, x: seg.ax + (seg.bx - seg.ax) * t, y: seg.ay + (seg.by - seg.ay) * t };
        }
        return best;
    }
}

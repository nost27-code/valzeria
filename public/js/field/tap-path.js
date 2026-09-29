import { TILE } from './constants.js';

const DIRECTIONS = [
    [1, 0], [-1, 0], [0, 1], [0, -1],
];

class MinHeap {
    constructor() { this.items = []; }

    push(item) {
        const a = this.items;
        a.push(item);
        let i = a.length - 1;
        while (i > 0) {
            const p = (i - 1) >> 1;
            if (a[p].f <= item.f) break;
            a[i] = a[p];
            i = p;
        }
        a[i] = item;
    }

    pop() {
        const a = this.items;
        if (!a.length) return null;
        const first = a[0];
        const last = a.pop();
        if (!a.length) return first;
        let i = 0;
        while (true) {
            const left = i * 2 + 1;
            if (left >= a.length) break;
            const right = left + 1;
            const child = right < a.length && a[right].f < a[left].f ? right : left;
            if (a[child].f >= last.f) break;
            a[i] = a[child];
            i = child;
        }
        a[i] = last;
        return first;
    }

    get size() { return this.items.length; }
}

const keyOf = (tx, ty) => `${tx},${ty}`;
const centerOf = (tile, size) => (tile + 0.5) * size;

function canTraverse(world, fromX, fromY, toX, toY, tileSize) {
    const distance = Math.hypot(toX - fromX, toY - fromY);
    const steps = Math.max(1, Math.ceil(distance / Math.max(4, tileSize / 3)));
    let previousX = fromX;
    let previousY = fromY;
    for (let i = 1; i <= steps; i++) {
        const x = fromX + (toX - fromX) * (i / steps);
        const y = fromY + (toY - fromY) * (i / steps);
        if (!world.canOccupy(x, y, previousX, previousY)) return false;
        previousX = x;
        previousY = y;
    }
    return true;
}

function pathTo(records, endKey, target, reachedTarget) {
    const waypoints = [];
    let key = endKey;
    while (key !== null) {
        const node = records.get(key);
        if (!node) break;
        if (node.parent !== null) waypoints.push({ x: node.x, y: node.y });
        key = node.parent;
    }
    waypoints.reverse();
    if (reachedTarget) {
        const last = waypoints.at(-1);
        if (!last || Math.hypot(last.x - target.x, last.y - target.y) > 0.5) waypoints.push({ x: target.x, y: target.y });
    }
    return waypoints;
}

/**
 * 画面内のタップ移動用A*。既存の足元当たり判定を使い、段差・建物・水場を避ける。
 * 到達不能な地点は、探索できた中で最も近い地点までの経路を返す。
 */
export function findTapPath(world, start, target, options = {}) {
    const tileSize = options.tileSize ?? TILE;
    const margin = options.marginTiles ?? 24;
    const maxNodes = options.maxNodes ?? 12000;
    if (![start.x, start.y, target.x, target.y, tileSize].every(Number.isFinite) || tileSize <= 0) return null;

    const startTx = Math.floor(start.x / tileSize);
    const startTy = Math.floor(start.y / tileSize);
    const targetTx = Math.floor(target.x / tileSize);
    const targetTy = Math.floor(target.y / tileSize);
    const minTx = Math.min(startTx, targetTx) - margin;
    const maxTx = Math.max(startTx, targetTx) + margin;
    const minTy = Math.min(startTy, targetTy) - margin;
    const maxTy = Math.max(startTy, targetTy) + margin;
    const heuristic = (tx, ty) => Math.abs(tx - targetTx) + Math.abs(ty - targetTy);

    const startKey = keyOf(startTx, startTy);
    const records = new Map([[startKey, {
        tx: startTx, ty: startTy, x: start.x, y: start.y, g: 0, parent: null, closed: false,
    }]]);
    const open = new MinHeap();
    open.push({ key: startKey, g: 0, f: heuristic(startTx, startTy) });
    let bestKey = startKey;
    let bestDistance = Math.hypot(start.x - target.x, start.y - target.y);
    let visited = 0;

    while (open.size && visited < maxNodes) {
        const candidate = open.pop();
        const current = records.get(candidate.key);
        if (!current || current.closed || candidate.g !== current.g) continue;
        current.closed = true;
        visited++;

        const distance = Math.hypot(current.x - target.x, current.y - target.y);
        if (distance < bestDistance) {
            bestDistance = distance;
            bestKey = candidate.key;
        }
        if (current.tx === targetTx && current.ty === targetTy) {
            const reachedTarget = canTraverse(world, current.x, current.y, target.x, target.y, tileSize);
            return {
                waypoints: pathTo(records, candidate.key, target, reachedTarget),
                reachedTarget,
                visited,
            };
        }

        for (const [dx, dy] of DIRECTIONS) {
            const tx = current.tx + dx;
            const ty = current.ty + dy;
            if (tx < minTx || tx > maxTx || ty < minTy || ty > maxTy) continue;
            const x = centerOf(tx, tileSize);
            const y = centerOf(ty, tileSize);
            if (!canTraverse(world, current.x, current.y, x, y, tileSize)) continue;

            const key = keyOf(tx, ty);
            const nextG = current.g + 1;
            const old = records.get(key);
            if (old && nextG >= old.g) continue;
            records.set(key, { tx, ty, x, y, g: nextG, parent: candidate.key, closed: false });
            open.push({ key, g: nextG, f: nextG + heuristic(tx, ty) });
        }
    }

    if (bestKey === startKey) return null;
    return { waypoints: pathTo(records, bestKey, target, false), reachedTarget: false, visited };
}

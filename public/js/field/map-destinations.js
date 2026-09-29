import { TILE } from './constants.js';

// 地図の候補は、サーバーから渡された解放状態を使って組み立てる。
export function mapDestinations(gen, world, plane, player) {
    const destinations = [];
    for (const c of gen.cities) {
        if (c.plane !== plane || c.city.minor || !world.unlockedCities.has(c.city.id)) continue;
        destinations.push({
            id: `city:${c.city.id}`, kind: 'city', name: c.city.name, plane,
            x: (c.tx + Math.floor(c.w / 2) + 0.5) * TILE,
            y: (c.ty + Math.floor(c.h / 2) + 20.5) * TILE,
        });
    }
    for (const e of gen.entrances) {
        if (e.plane !== plane || !world.enterableAreas.has(e.area_id)) continue;
        destinations.push({
            id: `area:${e.area_id}`, kind: 'area', name: e.name, plane,
            x: (e.doorTx + 0.5) * TILE, y: (e.doorTy + 0.5) * TILE,
        });
    }
    for (const w of gen.waysides) {
        if (w.plane !== plane) continue;
        const gate = w.gates[0];
        destinations.push({
            id: `wayside:${w.city.id}`, kind: 'wayside', name: w.city.name, plane,
            x: (gate.outTx + 0.5) * TILE, y: (gate.outTy + 0.5) * TILE,
        });
    }
    return destinations.sort((a, b) => Math.hypot(a.x - player.x, a.y - player.y) - Math.hypot(b.x - player.x, b.y - player.y));
}

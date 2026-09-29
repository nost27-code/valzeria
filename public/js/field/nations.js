// Persistent player-built layouts layered over the deterministic terrain.
import { TILE } from './constants.js';

export function pieceRect(piece, catalog) {
    const c = catalog[piece.kind];
    return { x: piece.x, y: piece.y, w: c.line && !piece.vertical ? piece.length : c.w, h: c.line && piece.vertical ? piece.length : c.h };
}

export function buildNation(s, catalog, gen) {
    const n = s.size, grid = new Array(n * n).fill('o'), objects = [];
    for (const side of ['n', 's', 'w', 'e']) {
        const spec = s.layout.sides[side];
        for (let a = 0; a < n; a++) for (let b = 0; b < 2; b++) {
            const [x, y] = side === 'n' ? [a, b] : side === 's' ? [a, n - 1 - b] : side === 'w' ? [b, a] : [n - 1 - b, a];
            const open = spec.gate !== null && a >= spec.gate && a < spec.gate + 6;
            grid[y * n + x] = open ? 's' : spec.wall === 'wall' ? 'w' : 'f';
        }
    }
    s.layout.pieces.forEach((p, i) => {
        const c = catalog[p.kind], r = pieceRect(p, catalog);
        if (c.tile) for (let y = r.y; y < r.y + r.h; y++) for (let x = r.x; x < r.x + r.w; x++) grid[y * n + x] = c.tile;
        if (c.sprite) objects.push({ type: c.sprite === 'lodge' ? 'house' : c.sprite, nationId: s.nation_id, nationName: s.name,
            plane: s.plane, label: c.label, tx: s.tx + r.x, ty: s.ty + r.y, tw: r.w, th: r.h, solid: c.solid,
            variant: i, style: 'stone', roof: p.kind === 'hall' ? '#415e85' : '#945844', wall: '#d8ccb1' });
    });
    const [side, gateSpec] = Object.entries(s.layout.sides).find(([, spec]) => spec.gate !== null) ?? [];
    if (side) {
        const a = gateSpec.gate + 1;
        const [x, y] = side === 'n' ? [a, -2] : side === 's' ? [a, n + 1] : side === 'w' ? [-2, a] : [n + 1, a];
        objects.push({ type: 'signpost', nationId: s.nation_id, nationName: s.name, plane: s.plane, label: s.name, tx: s.tx + x, ty: s.ty + y, tw: 1, th: 1, solid: false });
    }
    const level = gen.levelAt(s.tx + Math.floor(n / 2), s.ty + Math.floor(n / 2));
    return { ...s, grid, objects, level };
}

export function nationAt(layouts, x, y, margin = 0) {
    return layouts.find(s => x >= s.tx - margin && y >= s.ty - margin && x < s.tx + s.size + margin && y < s.ty + s.size + margin);
}

export class FieldNations {
    constructor(game, boot) { this.game = game; this.boot = boot; this.next = 0; this.pending = false; this.signature = ''; }
    async tick(now) {
        if (this.pending || now < this.next || document.visibilityState === 'hidden') return;
        this.pending = true; this.next = now + 5;
        const p = this.game.player, plane = p.plane;
        try {
            const url = new URL(this.boot.urls.nations);
            url.search = new URLSearchParams({ plane, x: Math.floor(p.x / TILE), y: Math.floor(p.y / TILE) });
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(8000) });
            if (!response.ok) throw Error('nation sync');
            const data = await response.json();
            if (this.game.player.plane !== plane) { this.next = 0; return; }
            const signature = JSON.stringify(data.settlements);
            if (signature === this.signature) return;
            this.game.world.setNations(data.settlements, data.catalog);
            this.game.renderer.blocks.clear();
            this.signature = signature;
            // A wall/building may have been placed on a visitor while they were standing still.
            if (!this.game.spectator) {
                [p.x, p.y] = this.game.world.nearestOpen(p.x, p.y, Math.max(40, ...data.settlements.map(s => s.size + 6)));
            }
        } catch { this.next = now + 10; }
        finally { this.pending = false; }
    }
}

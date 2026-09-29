// フィールドの見える魔物と、宝箱・採取ポイント。
// 縄張り・ポイントの位置は app/Services/Field/FieldEncounterService.php と同じ式で決める（サーバーが確かめる）。

import { TILE } from './constants.js';

// 0〜1 の決まった乱数（FieldEncounterService::spotHash と同じ）
export function spotHash(x, y, salt) {
    let h = (Math.imul(x, 73856093) ^ Math.imul(y, 19349663) ^ Math.imul(salt, 83492791)) >>> 0;
    h = Math.imul(h ^ (h >>> 15), 0x2c1b3c6d) >>> 0;
    h = Math.imul(h ^ (h >>> 12), 0x297a2d39) >>> 0;
    h = (h ^ (h >>> 15)) >>> 0;
    return h / 4294967296;
}

const MOVE_RADIUS_TILES = 60; // これより遠い魔物は動かさない

const SLIME_COLORS = ['#5ab0e8', '#6ac85a', '#e8a04a', '#c85ae8', '#e85a6a', '#e8d85a'];

export class FieldEncounters {
    constructor(def, world, gen, enterableAreas, claimed) {
        this.def = def;
        this.world = world;
        this.gen = gen;
        this.cfg = def.encounters;
        this.gcfg = def.gathering;
        this.seed = def.seed;
        this.enterable = enterableAreas;
        this.claimed = new Set(claimed);
        this.monsters = new Map();
        this.defeated = new Set();
        this.spots = new Map();
        this.images = new Map();
        this.graceUntil = 0;
        this.refreshAt = 0;
        this.entrances = def.entrances.map((e) => ({ area: e.area_id, plane: e.plane, tx: e.tx, ty: e.ty }));
        // エリアの推奨Lv（強さの目安を魔物の頭上に出す）
        this.areaLevels = new Map(def.entrances.map((e) => [e.area_id, e.level?.[0] ?? 1]));
        this.playerLevel = 1;
        this.cities = def.cities;
    }

    // ---- 縄張り ----------------------------------------------------------------------------

    territoryAt(plane, tx, ty) {
        const m = this.cfg.safe_margin_tiles;
        for (const c of this.cities) {
            if (c.plane !== plane) continue;
            if (tx >= c.tx - m && tx < c.tx + c.w + m && ty >= c.ty - m && ty < c.ty + c.h + m) return null;
        }
        for (const c of this.gen.waysides ?? []) {
            if (c.plane !== plane) continue;
            if (tx >= c.tx - 24 && tx < c.tx + c.w + 24 && ty >= c.ty - 24 && ty < c.ty + c.h + 24) return null;
        }
        // いちばん近い入口のエリア（解放前のエリアでも、その土地の魔物が出る）
        let best = null;
        let bestD = Infinity;
        for (const e of this.entrances) {
            if (e.plane !== plane) continue;
            const d = Math.hypot(tx - e.tx, ty - e.ty);
            if (d < bestD) {
                bestD = d;
                best = e.area;
            }
        }
        if (best === null || bestD > this.cfg.territory_tiles || bestD < this.cfg.entrance_safe_tiles) return null;
        return best;
    }

    image(url) {
        if (!url) return null;
        let img = this.images.get(url);
        if (!img) {
            img = new Image();
            img.src = url;
            this.images.set(url, img);
        }
        return img.complete && img.naturalWidth > 0 ? img : null;
    }

    // ---- 更新 ------------------------------------------------------------------------------

    // viewHalfTiles: 画面の長い辺の半分（マス）。その範囲まで魔物を置く
    update(dt, player, now, viewHalfTiles = 40) {
        const ptx = Math.floor(player.x / TILE);
        const pty = Math.floor(player.y / TILE);
        if (now > this.refreshAt) {
            this.refreshAt = now + 0.5;
            this.refreshMonsters(player.plane, ptx, pty, Math.min(10, Math.ceil(viewHalfTiles / this.cfg.cell_tiles) + 1));
            this.refreshSpots(player.plane, ptx, pty);
        }
        const graze = now < this.graceUntil;
        let contact = null;
        const active = MOVE_RADIUS_TILES * TILE;
        for (const m of this.monsters.values()) {
            // 遠くの魔物はその場で揺れているだけ（画面を引いた時に数百体を動かさない）
            if (Math.abs(m.x - player.x) > active || Math.abs(m.y - player.y) > active) {
                m.moving = false;
                m.chasing = false;
                continue;
            }
            this.moveMonster(m, dt, player, graze);
            if (!graze && !contact && Math.hypot(m.x - player.x, m.y - player.y) < 24) contact = m;
        }
        return contact;
    }

    refreshMonsters(plane, ptx, pty, radius = 4) {
        const size = this.cfg.cell_tiles;
        const epoch = Math.floor(Date.now() / (this.cfg.respawn_minutes * 60000));
        const pcx = Math.floor(ptx / size);
        const pcy = Math.floor(pty / size);
        const wanted = new Set();
        for (let cy = pcy - radius; cy <= pcy + radius; cy++) {
            for (let cx = pcx - radius; cx <= pcx + radius; cx++) {
                const count = (spotHash(cx, cy, this.seed + 100 + epoch) < this.cfg.spawn_chance ? 1 : 0)
                    + (spotHash(cx, cy, this.seed + 101 + epoch) < this.cfg.second_chance ? 1 : 0);
                for (let i = 0; i < count; i++) {
                    const id = `${cx}:${cy}:${i}:${epoch}`;
                    if (this.defeated.has(id)) continue;
                    wanted.add(id);
                    if (this.monsters.has(id)) continue;
                    const m = this.spawnMonster(id, plane, cx, cy, i, epoch);
                    if (m) this.monsters.set(id, m);
                    else wanted.delete(id);
                }
            }
        }
        for (const id of this.monsters.keys()) if (!wanted.has(id)) this.monsters.delete(id);
    }

    spawnMonster(id, plane, cx, cy, i, epoch) {
        const size = this.cfg.cell_tiles;
        for (let k = 0; k < 6; k++) {
            const salt = this.seed + 200 + epoch * 13 + i * 7 + k;
            const tx = cx * size + Math.floor(spotHash(cx, cy, salt) * size);
            const ty = cy * size + Math.floor(spotHash(cx, cy, salt + 1000) * size);
            const area = this.territoryAt(plane, tx, ty);
            if (!area) return null;
            if (this.world.isBlocked(tx, ty)) continue;
            const looks = this.def.monster_looks?.[area] ?? [];
            const look = looks.length ? looks[Math.floor(spotHash(cx, cy, salt + 7) * looks.length)] : null;
            return {
                id,
                area,
                look,
                color: SLIME_COLORS[area % SLIME_COLORS.length],
                x: tx * TILE + TILE / 2,
                y: ty * TILE + TILE / 2,
                dir: { x: 0, y: 0 },
                turnIn: 0,
                chasing: false,
                phase: spotHash(cx, cy, salt + 9) * 6,
            };
        }
        return null;
    }

    moveMonster(m, dt, player, graze) {
        const d = Math.hypot(player.x - m.x, player.y - m.y);
        m.chasing = !graze && d < this.cfg.sight_tiles * TILE;
        let speed = this.cfg.wander_tiles_per_second * TILE;
        if (m.chasing) {
            speed = this.cfg.chase_tiles_per_second * TILE;
            m.dir = { x: (player.x - m.x) / (d || 1), y: (player.y - m.y) / (d || 1) };
        } else if (graze && d < 4 * TILE) {
            m.dir = { x: (m.x - player.x) / (d || 1), y: (m.y - player.y) / (d || 1) }; // 戦った直後は離れていく
        } else {
            m.turnIn -= dt;
            if (m.turnIn <= 0) {
                m.turnIn = 1 + Math.random() * 2.5;
                const a = Math.random() * Math.PI * 2;
                m.dir = Math.random() < 0.3 ? { x: 0, y: 0 } : { x: Math.cos(a), y: Math.sin(a) };
            }
        }
        const nx = m.x + m.dir.x * speed * dt;
        const ny = m.y + m.dir.y * speed * dt;
        const ok = (x, y) => this.world.canOccupy(x, y, m.x, m.y, 16, 10) && this.territoryAt(player.plane, Math.floor(x / TILE), Math.floor(y / TILE)) === m.area;
        if (ok(nx, m.y)) m.x = nx;
        else m.turnIn = 0;
        if (ok(m.x, ny)) m.y = ny;
        else m.turnIn = 0;
        m.moving = m.dir.x !== 0 || m.dir.y !== 0;
    }

    defeat(m) {
        this.defeated.add(m.id);
        this.monsters.delete(m.id);
    }

    grace(now) {
        this.graceUntil = now + this.cfg.graze_seconds;
    }

    // ---- 宝箱・採取 --------------------------------------------------------------------------

    spotAt(plane, cx, cy) {
        const size = this.gcfg.cell_tiles;
        const roll = spotHash(cx, cy, this.seed + 1);
        const kind = roll < this.gcfg.chest_chance ? 'chest' : roll < this.gcfg.chest_chance + this.gcfg.gather_chance ? 'gather' : null;
        if (!kind) return null;
        const pad = Math.floor(size / 8);
        const tx = cx * size + pad + Math.floor(spotHash(cx, cy, this.seed + 2) * (size - pad * 2));
        const ty = cy * size + pad + Math.floor(spotHash(cx, cy, this.seed + 3) * (size - pad * 2));
        return { key: `${plane}:${cx}:${cy}`, kind, plane, tx, ty };
    }

    refreshSpots(plane, ptx, pty) {
        const size = this.gcfg.cell_tiles;
        const pcx = Math.floor(ptx / size);
        const pcy = Math.floor(pty / size);
        const wanted = new Set();
        for (let cy = pcy - 1; cy <= pcy + 1; cy++) {
            for (let cx = pcx - 1; cx <= pcx + 1; cx++) {
                const s = this.spotAt(plane, cx, cy);
                if (!s || this.claimed.has(s.key)) continue;
                wanted.add(s.key);
                if (this.spots.has(s.key)) continue;
                const area = this.territoryAt(plane, s.tx, s.ty);
                if (!area) continue;
                // 置き場所が塞がっていたら、すぐそばの空いたマスへ
                let at = null;
                for (let r = 0; r <= 4 && !at; r++) {
                    for (let dy = -r; dy <= r && !at; dy++) {
                        for (let dx = -r; dx <= r && !at; dx++) {
                            if (Math.max(Math.abs(dx), Math.abs(dy)) !== r) continue;
                            if (!this.world.isBlocked(s.tx + dx, s.ty + dy)) at = [s.tx + dx, s.ty + dy];
                        }
                    }
                }
                if (!at) continue;
                const tile = this.world.tileAt(at[0], at[1]);
                const ore = 'HNnVvK'.includes(tile);
                this.spots.set(s.key, { ...s, x: (at[0] + 0.5) * TILE, y: (at[1] + 0.5) * TILE, ore });
            }
        }
        for (const key of this.spots.keys()) if (!wanted.has(key)) this.spots.delete(key);
    }

    spotNear(player) {
        for (const s of this.spots.values()) {
            if (Math.hypot(s.x - player.x, s.y - player.y) < TILE * 1.4) return s;
        }
        return null;
    }

    markClaimed(key) {
        this.claimed.add(key);
        this.spots.delete(key);
    }

    // ---- 描画 ------------------------------------------------------------------------------

    sprites(t) {
        const out = [];
        for (const m of this.monsters.values()) out.push({ y: m.y, draw: (ctx) => this.drawMonster(ctx, m, t) });
        for (const s of this.spots.values()) out.push({ y: s.y, draw: (ctx) => this.drawSpot(ctx, s, t) });
        return out;
    }

    drawMonster(ctx, m, t) {
        const bob = Math.abs(Math.sin(t * (m.chasing ? 9 : 5) + m.phase)) * (m.moving ? 5 : 2);
        ctx.fillStyle = 'rgba(0,0,0,0.3)';
        ctx.beginPath();
        ctx.ellipse(m.x, m.y + 2, 14, 5, 0, 0, Math.PI * 2);
        ctx.fill();
        const img = this.image(m.look?.image);
        if (img) {
            const h = 46;
            const w = (img.naturalWidth / img.naturalHeight) * h;
            ctx.drawImage(img, m.x - w / 2, m.y - h - bob + 4, w, h);
        } else {
            ctx.fillStyle = m.color;
            ctx.beginPath();
            ctx.moveTo(m.x - 14, m.y);
            ctx.quadraticCurveTo(m.x - 14, m.y - 20 - bob, m.x, m.y - 26 - bob);
            ctx.quadraticCurveTo(m.x + 14, m.y - 20 - bob, m.x + 14, m.y);
            ctx.closePath();
            ctx.fill();
            ctx.fillStyle = '#fff';
            ctx.fillRect(m.x - 7, m.y - 16 - bob, 4, 5);
            ctx.fillRect(m.x + 3, m.y - 16 - bob, 4, 5);
            ctx.fillStyle = '#1a1a1a';
            ctx.fillRect(m.x - 6, m.y - 14 - bob, 2, 3);
            ctx.fillRect(m.x + 4, m.y - 14 - bob, 2, 3);
        }
        // 自分より推奨Lvがずっと高い土地の魔物には、赤い推奨Lvを出す
        const areaLevel = this.areaLevels.get(m.area) ?? 1;
        if (areaLevel > this.playerLevel + 5) {
            ctx.font = 'bold 12px sans-serif';
            ctx.textAlign = 'center';
            ctx.lineWidth = 3;
            ctx.strokeStyle = '#000';
            const text = `Lv${areaLevel}〜`;
            ctx.strokeText(text, m.x, m.y - 56 - bob);
            ctx.fillStyle = '#ff5a5a';
            ctx.fillText(text, m.x, m.y - 56 - bob);
        }
        if (m.chasing) {
            ctx.font = 'bold 16px sans-serif';
            ctx.textAlign = 'center';
            ctx.lineWidth = 3;
            ctx.strokeStyle = '#000';
            ctx.strokeText('!', m.x, m.y - 52);
            ctx.fillStyle = '#ffd84a';
            ctx.fillText('!', m.x, m.y - 52);
        }
    }

    drawSpot(ctx, s, t) {
        const glint = 0.5 + 0.5 * Math.sin(t * 4 + s.tx);
        if (s.kind === 'chest') {
            ctx.fillStyle = 'rgba(0,0,0,0.3)';
            ctx.fillRect(s.x - 13, s.y + 2, 26, 5);
            ctx.fillStyle = '#8a5a2a';
            ctx.fillRect(s.x - 13, s.y - 14, 26, 16);
            ctx.fillStyle = '#a8703a';
            ctx.fillRect(s.x - 13, s.y - 20, 26, 8);
            ctx.fillStyle = '#e8c860';
            ctx.fillRect(s.x - 13, s.y - 13, 26, 3);
            ctx.fillRect(s.x - 2, s.y - 12, 4, 6);
        } else if (s.ore) {
            ctx.fillStyle = '#7d7466';
            ctx.beginPath();
            ctx.moveTo(s.x - 12, s.y + 2);
            ctx.lineTo(s.x - 8, s.y - 12);
            ctx.lineTo(s.x + 4, s.y - 16);
            ctx.lineTo(s.x + 12, s.y + 2);
            ctx.closePath();
            ctx.fill();
            ctx.fillStyle = '#9ad8ff';
            ctx.fillRect(s.x - 4, s.y - 10, 4, 4);
            ctx.fillRect(s.x + 3, s.y - 6, 3, 3);
        } else {
            ctx.strokeStyle = '#3f8a3a';
            ctx.lineWidth = 3;
            for (const dx of [-6, 0, 6]) {
                ctx.beginPath();
                ctx.moveTo(s.x + dx * 0.4, s.y + 2);
                ctx.quadraticCurveTo(s.x + dx, s.y - 10, s.x + dx * 1.6, s.y - 16);
                ctx.stroke();
            }
            ctx.fillStyle = '#f39ac0';
            ctx.fillRect(s.x - 2, s.y - 18, 4, 4);
        }
        ctx.fillStyle = `rgba(255,250,200,${glint})`;
        ctx.fillRect(s.x + 8, s.y - 24 - glint * 4, 3, 3);
        ctx.fillRect(s.x - 10, s.y - 18 + glint * 3, 2, 2);
    }
}

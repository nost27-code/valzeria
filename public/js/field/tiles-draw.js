// 地形のマスを描く（16x16 マスの塊ごとにキャンバスへ描いてキャッシュする）。
// 画像素材が届いたら、ここでタイル画像を使うように差し替える。

import { TILE, TILES, isWaterTile } from './constants.js';
import { fbm, hash2i } from './noise.js';
import { drawCliffArt, drawTerrainArt, drawTerrainOverlay } from './terrain-art.js';

const T = TILE;

function shade(hex, amount) {
    const n = parseInt(hex.slice(1), 16);
    const f = (v) => Math.max(0, Math.min(255, Math.round(v + amount)));
    return `rgb(${f(n >> 16)},${f((n >> 8) & 255)},${f(n & 255)})`;
}

function dots(ctx, tx, ty, px, py, color, count, seed, size = 2) {
    ctx.fillStyle = color;
    for (let i = 0; i < count; i++) {
        const x = Math.floor(hash2i(tx, ty, seed + i * 7) * (T - size));
        const y = Math.floor(hash2i(tx, ty, seed + i * 7 + 3) * (T - size));
        ctx.fillRect(px + x, py + y, size, size);
    }
}

// 木の下の地面（まわりに合わせる）
const UNDER = { T: 'G', U: 'F', I: 'N', J: 'N', P: 'D', C: 'D', Z: 'K', Y: 'V', R: 'H', M: 'H', Q: 'e', t: 'q' };

export function terrainRockUnderlay(world, tx, ty) {
    const gen = world?.gen;
    const natural = gen?.naturalCell?.(tx, ty);
    const plane = gen?.planeOfTile?.(tx) ?? 'land';
    const localGround = natural ? gen?.groundFor?.(natural.biome, plane) : null;
    return localGround && localGround !== 'R' ? localGround : UNDER.R;
}

function ground(ctx, tile, tx, ty, px, py) {
    const base = TILES[tile]?.color ?? '#ff00ff';
    // マス目が目立たないよう、1マスごとのばらつきは小さく、数マス単位のゆるいむらを付ける
    const v = (hash2i(tx, ty, 11) - 0.5) * 3 + fbm(tx / 9, ty / 9, 17, 2) * 12;
    ctx.fillStyle = shade(base, v);
    ctx.fillRect(px, py, T, T);
    switch (tile) {
        case 'G':
            dots(ctx, tx, ty, px, py, shade(base, 18), 4, 21);
            dots(ctx, tx, ty, px, py, shade(base, -16), 3, 23);
            break;
        case 'g':
            ctx.strokeStyle = shade(base, -22);
            ctx.lineWidth = 2;
            for (let i = 0; i < 3; i++) {
                const x = px + 4 + Math.floor(hash2i(tx, ty, 31 + i) * 22);
                const y = py + 10 + Math.floor(hash2i(tx, ty, 33 + i) * 16);
                ctx.beginPath();
                ctx.moveTo(x - 3, y - 6);
                ctx.lineTo(x, y);
                ctx.lineTo(x + 3, y - 6);
                ctx.stroke();
            }
            break;
        case 'L': {
            dots(ctx, tx, ty, px, py, shade(base, 14), 3, 21);
            const colors = ['#f4e36a', '#f39ac0', '#ffffff', '#b99af0', '#f08a5a'];
            for (let i = 0; i < 4; i++) {
                ctx.fillStyle = colors[Math.floor(hash2i(tx, ty, 41 + i) * colors.length)];
                ctx.fillRect(px + 3 + Math.floor(hash2i(tx, ty, 43 + i) * 24), py + 3 + Math.floor(hash2i(tx, ty, 45 + i) * 24), 3, 3);
            }
            break;
        }
        case 'F':
            dots(ctx, tx, ty, px, py, shade(base, -18), 5, 51, 3);
            dots(ctx, tx, ty, px, py, shade(base, 14), 2, 53);
            break;
        case 'H':
            dots(ctx, tx, ty, px, py, shade(base, -20), 4, 61);
            dots(ctx, tx, ty, px, py, '#8a9a5a', 2, 63, 3);
            break;
        case 'A':
        case 'D':
            dots(ctx, tx, ty, px, py, shade(base, -18), 4, 71);
            dots(ctx, tx, ty, px, py, shade(base, 16), 3, 73);
            break;
        case 'd':
            ctx.strokeStyle = shade(base, -24);
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(px, py + 12);
            ctx.quadraticCurveTo(px + 16, py + 4, px + T, py + 12);
            ctx.moveTo(px, py + 26);
            ctx.quadraticCurveTo(px + 16, py + 18, px + T, py + 26);
            ctx.stroke();
            break;
        case 'N':
        case 'n':
            dots(ctx, tx, ty, px, py, '#d4dee9', 3, 81);
            dots(ctx, tx, ty, px, py, '#ffffff', 3, 83);
            if (tile === 'n') dots(ctx, tx, ty, px, py, '#7a9a8a', 3, 85, 2);
            break;
        case 'V':
        case 'v':
            dots(ctx, tx, ty, px, py, shade(base, -18), tile === 'v' ? 7 : 4, 91, tile === 'v' ? 3 : 2);
            dots(ctx, tx, ty, px, py, '#7a5a4a', 2, 93);
            break;
        case 'S':
            dots(ctx, tx, ty, px, py, shade(base, -20), 4, 101);
            dots(ctx, tx, ty, px, py, '#d6c98a', 3, 103);
            break;
        case 'K':
            dots(ctx, tx, ty, px, py, shade(base, -16), 5, 111);
            dots(ctx, tx, ty, px, py, '#8a6aa0', 2, 113);
            break;
        case 'o':
            dots(ctx, tx, ty, px, py, shade(base, -14), 3, 121);
            break;
        case 'q':
            dots(ctx, tx, ty, px, py, shade(base, 16), 4, 131);
            break;
        case 'p':
        case 'e':
        case 'j': {
            ctx.strokeStyle = shade(base, tile === 'j' ? 18 : -22);
            ctx.lineWidth = 1;
            ctx.strokeRect(px + 0.5, py + 0.5, T / 2, T / 2);
            ctx.strokeRect(px + T / 2 + 0.5, py + T / 2 + 0.5, T / 2 - 1, T / 2 - 1);
            break;
        }
        case 'm':
            // 畑：土の畝と、並んだ作物
            ctx.fillStyle = '#6e5230';
            for (let i = 0; i < 4; i++) ctx.fillRect(px, py + i * 8 + 5, T, 2);
            ctx.fillStyle = hash2i(tx, ty, 311) < 0.5 ? '#6aa84a' : '#c8b04a';
            for (let i = 0; i < 4; i++) for (let j = 0; j < 4; j++) ctx.fillRect(px + j * 8 + 2, py + i * 8 + 1, 4, 4);
            break;
        case 'k':
        case 'b':
            break;
        default:
            break;
    }
}

function water(ctx, tile, tx, ty, px, py) {
    const deep = tile === 'O';
    ctx.fillStyle = shade(TILES[tile].color, (hash2i(tx, ty, 11) - 0.5) * 6);
    ctx.fillRect(px, py, T, T);
    ctx.strokeStyle = deep ? 'rgba(170,200,240,0.25)' : 'rgba(210,235,255,0.45)';
    ctx.lineWidth = 2;
    if (hash2i(tx, ty, 141) < (deep ? 0.35 : 0.55)) {
        const x = px + 4 + Math.floor(hash2i(tx, ty, 143) * 16);
        const y = py + 6 + Math.floor(hash2i(tx, ty, 145) * 20);
        ctx.beginPath();
        ctx.moveTo(x, y);
        ctx.quadraticCurveTo(x + 5, y - 4, x + 10, y);
        ctx.stroke();
    }
}

function tree(ctx, tx, ty, px, py, leaf, dark, radius = 14) {
    const ox = (hash2i(tx, ty, 151) - 0.5) * 4;
    ctx.fillStyle = 'rgba(0,0,0,0.25)';
    ctx.beginPath();
    ctx.ellipse(px + 17 + ox, py + 25, radius * 0.8, 5, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = '#5a3a22';
    ctx.fillRect(px + 14 + ox, py + 18, 5, 9);
    ctx.fillStyle = dark;
    ctx.beginPath();
    ctx.arc(px + 16 + ox, py + 13, radius, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = leaf;
    ctx.beginPath();
    ctx.arc(px + 13 + ox, py + 10, radius * 0.72, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,0.18)';
    ctx.beginPath();
    ctx.arc(px + 11 + ox, py + 7, radius * 0.3, 0, Math.PI * 2);
    ctx.fill();
}

function pine(ctx, tx, ty, px, py, snowy) {
    const ox = (hash2i(tx, ty, 151) - 0.5) * 4;
    ctx.fillStyle = 'rgba(0,0,0,0.22)';
    ctx.beginPath();
    ctx.ellipse(px + 16 + ox, py + 27, 10, 4, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = '#4a3222';
    ctx.fillRect(px + 14 + ox, py + 22, 4, 6);
    for (const [y, w] of [[2, 8], [9, 11], [15, 14]]) {
        ctx.fillStyle = '#2f5a48';
        ctx.beginPath();
        ctx.moveTo(px + 16 + ox, py + y);
        ctx.lineTo(px + 16 + ox - w, py + y + 10);
        ctx.lineTo(px + 16 + ox + w, py + y + 10);
        ctx.closePath();
        ctx.fill();
        if (snowy) {
            ctx.fillStyle = '#f4f8fc';
            ctx.beginPath();
            ctx.moveTo(px + 16 + ox, py + y);
            ctx.lineTo(px + 16 + ox - w * 0.45, py + y + 4.5);
            ctx.lineTo(px + 16 + ox + w * 0.45, py + y + 4.5);
            ctx.closePath();
            ctx.fill();
        }
    }
}

function boulder(ctx, tx, ty, px, py, color, big = false) {
    const s = big ? 1 : 0.75 + hash2i(tx, ty, 161) * 0.2;
    const cx = px + 16;
    const cy = py + 18;
    ctx.fillStyle = 'rgba(0,0,0,0.25)';
    ctx.beginPath();
    ctx.ellipse(cx + 2, cy + 8 * s, 13 * s, 5 * s, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = shade(color, -18);
    ctx.beginPath();
    ctx.moveTo(cx - 14 * s, cy + 8 * s);
    ctx.lineTo(cx - 10 * s, cy - 8 * s);
    ctx.lineTo(cx + 2 * s, cy - 13 * s);
    ctx.lineTo(cx + 13 * s, cy - 4 * s);
    ctx.lineTo(cx + 14 * s, cy + 8 * s);
    ctx.closePath();
    ctx.fill();
    ctx.fillStyle = shade(color, 16);
    ctx.beginPath();
    ctx.moveTo(cx - 9 * s, cy - 6 * s);
    ctx.lineTo(cx + 2 * s, cy - 11 * s);
    ctx.lineTo(cx + 8 * s, cy - 4 * s);
    ctx.lineTo(cx - 2 * s, cy);
    ctx.closePath();
    ctx.fill();
}

function mountain(ctx, tx, ty, px, py, color, snowcap) {
    ctx.fillStyle = shade(color, (hash2i(tx, ty, 171) - 0.5) * 14);
    ctx.fillRect(px, py, T, T);
    ctx.fillStyle = shade(color, 22);
    ctx.beginPath();
    const peak = 6 + Math.floor(hash2i(tx, ty, 173) * 20);
    ctx.moveTo(px, py + T);
    ctx.lineTo(px + peak, py + 4);
    ctx.lineTo(px + peak + 4, py + 10);
    ctx.lineTo(px + T, py + T);
    ctx.closePath();
    ctx.fill();
    if (snowcap) {
        ctx.fillStyle = '#f4f8fc';
        ctx.beginPath();
        ctx.moveTo(px + peak - 5, py + 13);
        ctx.lineTo(px + peak, py + 4);
        ctx.lineTo(px + peak + 7, py + 14);
        ctx.closePath();
        ctx.fill();
    }
    ctx.strokeStyle = shade(color, -28);
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(px + peak + 4, py + 10);
    ctx.lineTo(px + peak + 10, py + T - 2);
    ctx.stroke();
}

export function drawTerrainTile(ctx, world, tx, ty, px, py, atlases) {
    const tile = world.tileAt(tx, ty);
    if (tile === '=' && drawTerrainArt(ctx, 's', px, py, atlases)) {
        drawStairs(ctx, world, tx, ty, px, py, true);
        return;
    }
    let rotate = false;
    if (tile === 'b' || tile === 'k') rotate = horizontalBridge(world, tx, ty);
    else if (['w', 'x', 'i', 'f'].includes(tile)) {
        const horizontal = Number(world.tileAt(tx - 1, ty) === tile) + Number(world.tileAt(tx + 1, ty) === tile);
        const vertical = Number(world.tileAt(tx, ty - 1) === tile) + Number(world.tileAt(tx, ty + 1) === tile);
        rotate = vertical > horizontal;
    }
    if (tile === 'R') {
        const underlay = terrainRockUnderlay(world, tx, ty);
        if (drawTerrainArt(ctx, underlay, px, py, atlases)) {
            ctx.fillStyle = 'rgba(0,0,0,0.22)';
            ctx.beginPath();
            ctx.ellipse(px + 17, py + 25, 13, 5, 0, 0, Math.PI * 2);
            ctx.fill();
            if (drawTerrainOverlay(ctx, tile, px, py, atlases)) return;
            boulder(ctx, tx, ty, px, py, '#8d8476');
            return;
        }
    }
    if (drawTerrainArt(ctx, tile, px, py, atlases, rotate)) return;
    if (isWaterTile(tile) && tile !== 'u') {
        water(ctx, tile, tx, ty, px, py);
        return;
    }
    switch (tile) {
        case 'T':
            ground(ctx, 'G', tx, ty, px, py);
            tree(ctx, tx, ty, px, py, '#4f9a3a', '#2f6a2a');
            return;
        case 'U':
            ground(ctx, 'F', tx, ty, px, py);
            tree(ctx, tx, ty, px, py, '#3f7f36', '#23552a', 15);
            return;
        case 't':
            ground(ctx, 'q', tx, ty, px, py);
            tree(ctx, tx, ty, px, py, '#5aa844', '#346a2a', 12);
            return;
        case 'I':
            ground(ctx, 'N', tx, ty, px, py);
            pine(ctx, tx, ty, px, py, true);
            return;
        case 'Z':
            ground(ctx, 'K', tx, ty, px, py);
            ctx.strokeStyle = '#2a2030';
            ctx.lineWidth = 3;
            ctx.beginPath();
            ctx.moveTo(px + 16, py + 28);
            ctx.lineTo(px + 16, py + 12);
            ctx.lineTo(px + 8, py + 4);
            ctx.moveTo(px + 16, py + 16);
            ctx.lineTo(px + 25, py + 7);
            ctx.moveTo(px + 16, py + 20);
            ctx.lineTo(px + 22, py + 18);
            ctx.stroke();
            return;
        case 'P':
            ground(ctx, 'D', tx, ty, px, py);
            ctx.strokeStyle = '#8a6a3a';
            ctx.lineWidth = 3;
            ctx.beginPath();
            ctx.moveTo(px + 16, py + 29);
            ctx.quadraticCurveTo(px + 12, py + 18, px + 16, py + 9);
            ctx.stroke();
            ctx.fillStyle = '#3f8a3a';
            for (let i = 0; i < 5; i++) {
                const a = (i / 5) * Math.PI * 2;
                ctx.beginPath();
                ctx.ellipse(px + 16 + Math.cos(a) * 7, py + 9 + Math.sin(a) * 4, 8, 3, a, 0, Math.PI * 2);
                ctx.fill();
            }
            return;
        case 'C':
            ground(ctx, 'D', tx, ty, px, py);
            ctx.fillStyle = '#4f9a4a';
            ctx.fillRect(px + 13, py + 8, 6, 20);
            ctx.fillRect(px + 7, py + 13, 4, 8);
            ctx.fillRect(px + 21, py + 11, 4, 8);
            return;
        case 'R':
            ground(ctx, UNDER.R, tx, ty, px, py);
            boulder(ctx, tx, ty, px, py, '#8d8476');
            return;
        case 'Y':
            ground(ctx, 'V', tx, ty, px, py);
            boulder(ctx, tx, ty, px, py, '#3a2e40');
            return;
        case 'J':
            mountain(ctx, tx, ty, px, py, '#a9bccf', true);
            return;
        case 'M':
            mountain(ctx, tx, ty, px, py, '#6f665c', false);
            return;
        case 'Q':
            ground(ctx, 'e', tx, ty, px, py);
            ctx.fillStyle = 'rgba(0,0,0,0.2)';
            ctx.fillRect(px + 10, py + 24, 16, 5);
            ctx.fillStyle = '#e4dfd2';
            ctx.fillRect(px + 10, py + 2, 12, 24);
            ctx.fillStyle = '#c4bfb2';
            ctx.fillRect(px + 18, py + 2, 4, 24);
            ctx.fillStyle = '#f4f1ea';
            ctx.fillRect(px + 8, py, 16, 4);
            return;
        case 'X': {
            ctx.fillStyle = shade('#d84a1e', (hash2i(tx, ty, 11) - 0.5) * 20);
            ctx.fillRect(px, py, T, T);
            ctx.fillStyle = '#ffb040';
            for (let i = 0; i < 3; i++) {
                const x = px + Math.floor(hash2i(tx, ty, 181 + i) * 26);
                const y = py + Math.floor(hash2i(tx, ty, 183 + i) * 26);
                ctx.fillRect(x, y, 6, 3);
            }
            ctx.fillStyle = 'rgba(60,20,10,0.35)';
            ctx.fillRect(px + Math.floor(hash2i(tx, ty, 187) * 20), py + Math.floor(hash2i(tx, ty, 189) * 20), 10, 6);
            return;
        }
        case 'z': {
            ctx.fillStyle = '#9fd0f4';
            ctx.fillRect(px, py, T, T);
            ctx.fillStyle = 'rgba(255,255,255,0.85)';
            for (let i = 0; i < 3; i++) {
                ctx.beginPath();
                ctx.arc(px + 6 + hash2i(tx, ty, 191 + i) * 20, py + 6 + hash2i(tx, ty, 193 + i) * 20, 8 + hash2i(tx, ty, 195 + i) * 8, 0, Math.PI * 2);
                ctx.fill();
            }
            return;
        }
        case 'r':
            ctx.fillStyle = shade(TILES.r.color, (hash2i(tx, ty, 11) - 0.5) * 10);
            ctx.fillRect(px, py, T, T);
            dots(ctx, tx, ty, px, py, '#a8864e', 4, 201);
            dots(ctx, tx, ty, px, py, '#dcc08a', 2, 203);
            return;
        case 's': {
            ctx.fillStyle = '#9e9584';
            ctx.fillRect(px, py, T, T);
            for (let row = 0; row < 4; row++) {
                const off = row % 2 ? 4 : 0;
                for (let col = -1; col < 4; col++) {
                    ctx.fillStyle = shade('#bdb4a2', (hash2i(tx * 4 + col, ty * 4 + row, 205) - 0.5) * 24);
                    ctx.fillRect(px + col * 8 + off + 1, py + row * 8 + 1, 6, 6);
                }
            }
            return;
        }
        case 'b':
        case 'k': {
            ctx.fillStyle = '#3f7fc4';
            ctx.fillRect(px, py, T, T);
            const alongX = tile === 'k' ? true : horizontalBridge(world, tx, ty);
            ctx.fillStyle = '#8a6034';
            ctx.fillRect(px, py, T, T);
            ctx.fillStyle = '#b58a52';
            for (let i = 0; i < 4; i++) {
                if (alongX) ctx.fillRect(px + i * 8 + 1, py, 6, T);
                else ctx.fillRect(px, py + i * 8 + 1, T, 6);
            }
            return;
        }
        case 'c':
            ctx.fillStyle = '#d84a1e';
            ctx.fillRect(px, py, T, T);
            ctx.fillStyle = '#3e3236';
            ctx.fillRect(px + 1, py + 1, T - 2, T - 2);
            dots(ctx, tx, ty, px, py, '#5a4a4e', 5, 211, 4);
            return;
        case '=':
            drawStairs(ctx, world, tx, ty, px, py);
            return;
        case 'w':
        case 'x': {
            const base = TILES[tile].color;
            ctx.fillStyle = shade(base, -10);
            ctx.fillRect(px, py, T, T);
            ctx.fillStyle = shade(base, 12);
            for (let row = 0; row < 4; row++) {
                const off = row % 2 ? 8 : 0;
                for (let col = -1; col < 2; col++) ctx.fillRect(px + col * 16 + off + 1, py + row * 8 + 1, 14, 6);
            }
            ctx.fillStyle = 'rgba(255,255,255,0.18)';
            ctx.fillRect(px, py, T, 3);
            return;
        }
        case 'i': {
            ground(ctx, 'o', tx, ty, px, py);
            const horizontal = world.tileAt(tx - 1, ty) === 'i' || world.tileAt(tx + 1, ty) === 'i';
            const vertical = world.tileAt(tx, ty - 1) === 'i' || world.tileAt(tx, ty + 1) === 'i';
            ctx.fillStyle = '#34363a';
            if (horizontal) {
                ctx.fillRect(px, py + 10, T, 4);
                ctx.fillRect(px, py + 21, T, 4);
            }
            if (vertical) {
                ctx.fillRect(px + 10, py, 4, T);
                ctx.fillRect(px + 21, py, 4, T);
            }
            ctx.fillStyle = '#858078';
            ctx.fillRect(px + 14, py + 14, 4, 4);
            return;
        }
        case 'h':
            ground(ctx, 'q', tx, ty, px, py);
            ctx.fillStyle = '#2a6a32';
            ctx.fillRect(px, py + 4, T, T - 6);
            ctx.fillStyle = '#3f8a42';
            for (let i = 0; i < 4; i++) {
                ctx.beginPath();
                ctx.arc(px + 4 + i * 8, py + 8, 6, 0, Math.PI * 2);
                ctx.fill();
            }
            return;
        case 'f':
            ground(ctx, 'o', tx, ty, px, py);
            ctx.fillStyle = '#7a5230';
            ctx.fillRect(px, py + 12, T, 4);
            ctx.fillRect(px, py + 22, T, 4);
            ctx.fillRect(px + 4, py + 8, 4, 22);
            ctx.fillRect(px + 22, py + 8, 4, 22);
            return;
        case 'u':
        case 'l':
            water(ctx, 'W', tx, ty, px, py);
            return;
        default:
            ground(ctx, tile, tx, ty, px, py);
    }
}

function horizontalBridge(world, tx, ty) {
    const road = (x, y) => ['b', 'k', 'r', 's', '=', 'c'].includes(world.tileAt(x, y));
    const h = (road(tx - 1, ty) ? 1 : 0) + (road(tx + 1, ty) ? 1 : 0);
    const v = (road(tx, ty - 1) ? 1 : 0) + (road(tx, ty + 1) ? 1 : 0);
    return h > v;
}

function drawStairs(ctx, world, tx, ty, px, py, illustrated = false) {
    const lv = world.levelAt(tx, ty);
    const ns = world.levelAt(tx, ty - 1) !== lv || world.levelAt(tx, ty + 1) !== lv;
    if (!illustrated) {
        ctx.fillStyle = '#b4aa94';
        ctx.fillRect(px, py, T, T);
    }
    ctx.fillStyle = illustrated ? 'rgba(226,218,199,0.72)' : '#d8cfba';
    for (let i = 0; i < 4; i++) {
        if (ns) ctx.fillRect(px, py + i * 8, T, 5);
        else ctx.fillRect(px + i * 8, py, 5, T);
    }
    ctx.fillStyle = 'rgba(0,0,0,0.18)';
    for (let i = 0; i < 4; i++) {
        if (ns) ctx.fillRect(px, py + i * 8 + 5, T, 3);
        else ctx.fillRect(px + i * 8 + 5, py, 3, T);
    }
}

// 崖の色（地面に合わせる）
function cliffColor(tile) {
    if ('NnIJ'.includes(tile)) return ['#9eb2c6', '#7b8fa6'];
    if ('VvXYc'.includes(tile)) return ['#4a3a3a', '#2e2428'];
    if ('DdPCA'.includes(tile)) return ['#c49a5e', '#9a7440'];
    if ('Kz'.includes(tile)) return ['#5a4a64', '#3e3246'];
    if ('ep'.includes(tile)) return ['#d6d0c2', '#aaa292'];
    return ['#8a6a48', '#6a4e34'];
}

// 段差：高い段の南の縁に崖の面、東西の縁に影、低い段の北側に落ちる影
export function drawElevation(ctx, world, tx, ty, px, py, atlases) {
    const tile = world.tileAt(tx, ty);
    if (isWaterTile(tile) || tile === 'z' || tile === '=') return;
    const lv = world.levelAt(tx, ty);
    const south = world.levelAt(tx, ty + 1);
    const north = world.levelAt(tx, ty - 1);
    const west = world.levelAt(tx - 1, ty);
    const east = world.levelAt(tx + 1, ty);
    const southTile = world.tileAt(tx, ty + 1);
    if (lv > 0) {
        ctx.fillStyle = `rgba(255,255,240,${Math.min(0.14, lv * 0.018)})`;
        ctx.fillRect(px, py, T, T);
    }
    if (south < lv && !isWaterTile(southTile) && southTile !== '=') {
        const depth = Math.min(20, 10 + (lv - south) * 4);
        const variant = hash2i(tx, ty, 229) < 0.5 ? 0 : 1;
        const cliffIndex = 'NnIJ'.includes(tile) ? 2 + variant
            : 'VvXYc'.includes(tile) ? 4 + variant
            : 'DdPCA'.includes(tile) ? 6 + variant
            : 'ep'.includes(tile) ? 8 + variant
            : 'Kz'.includes(tile) ? 10 + variant : variant;
        if (!drawCliffArt(ctx, px, py, depth, atlases?.get('cliff'), cliffIndex)) {
            const [face, dark] = cliffColor(tile);
            ctx.fillStyle = face;
            ctx.fillRect(px, py + T - depth, T, depth);
            ctx.fillStyle = dark;
            for (let i = 0; i < 4; i++) ctx.fillRect(px + 3 + i * 8 + Math.floor(hash2i(tx, ty, 221 + i) * 3), py + T - depth + 3, 2, depth - 4);
        }
        ctx.fillStyle = 'rgba(255,255,255,0.35)';
        ctx.fillRect(px, py + T - depth, T, 2);
    }
    if (north > lv && world.tileAt(tx, ty - 1) !== '=') {
        const g = ctx.createLinearGradient(0, py, 0, py + 10);
        g.addColorStop(0, 'rgba(0,0,0,0.35)');
        g.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = g;
        ctx.fillRect(px, py, T, 10);
    }
    if (west < lv && world.tileAt(tx - 1, ty) !== '=') {
        ctx.fillStyle = 'rgba(40,24,10,0.55)';
        ctx.fillRect(px, py, 3, T);
    }
    if (east < lv && world.tileAt(tx + 1, ty) !== '=') {
        ctx.fillStyle = 'rgba(40,24,10,0.55)';
        ctx.fillRect(px + T - 3, py, 3, T);
    }
    if (north < lv && world.tileAt(tx, ty - 1) !== '=') {
        ctx.fillStyle = 'rgba(255,255,255,0.3)';
        ctx.fillRect(px, py, T, 2);
    }
}

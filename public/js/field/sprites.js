// フィールドの物（建物・名所・入口・転移陣・冒険者）の仮の絵。
// 画像素材が届いたら、ASSET_KEYS の画像があればそれを描き、無ければこの仮の絵を描く。

import { TILE } from './constants.js';
import { isBuilding, objectFootprint } from './object-footprint.js';
import { hash2i, hashStr } from './noise.js';

const T = TILE;

// 結界のドームではなく地面の石輪を配置中心へ合わせる（素材内の高さ比）。
export function sealGroundAnchorY(o) {
    return o.type === 'barrier' && !o.unlocked ? 0.72 : 0.5;
}

// 施設の看板の文字
const FACILITY_SIGNS = {
    inn: '宿', supply: '補', equipment_shop: '武', blacksmith: '鍛', synthesis: '合', material_exchange: '交',
    apothecary: '薬', temple: '神', bank: '銀', tavern: '酒', guide: '案', ranking_board: '番', map_house: '図',
    valmon_farm: '牧', training_ground: '訓',
};

import { assetKeysFor } from './asset-keys.js';
export { assetKeysFor } from './asset-keys.js';

function rect(ctx, color, x, y, w, h) {
    ctx.fillStyle = color;
    ctx.fillRect(x, y, w, h);
}

function shadow(ctx, x, y, w) {
    ctx.fillStyle = 'rgba(0,0,0,0.28)';
    ctx.beginPath();
    ctx.ellipse(x + w / 2, y, w * 0.52, 7, 0, 0, Math.PI * 2);
    ctx.fill();
}

// 本体が画像でも、従来と同じ速度・寸法で羽根を回す。
function windmillSails(ctx, hubX, hubY, t) {
    for (let i = 0; i < 4; i++) {
        ctx.save();
        ctx.translate(hubX, hubY);
        ctx.rotate(t * 0.8 + (i * Math.PI) / 2);
        rect(ctx, '#6a4a2a', -3, 0, 6, 86);
        rect(ctx, 'rgba(244,236,220,0.92)', 3, 18, 22, 64);
        ctx.restore();
    }
    ctx.fillStyle = '#3a2a1a';
    ctx.beginPath();
    ctx.arc(hubX, hubY, 7, 0, Math.PI * 2);
    ctx.fill();
}

// 切妻屋根の家（上から見た 3/4 視点）
function house(ctx, x, y, w, h, roof, wall, seed, opts = {}) {
    shadow(ctx, x + 4, y + h + 2, w);
    const wallH = Math.max(18, Math.round(h * 0.42));
    const roofH = h - wallH + 8;
    rect(ctx, wall, x + 2, y + h - wallH, w - 4, wallH);
    rect(ctx, 'rgba(0,0,0,0.12)', x + 2, y + h - 4, w - 4, 4);
    // 屋根
    ctx.fillStyle = roof;
    ctx.beginPath();
    ctx.moveTo(x - 3, y + roofH);
    ctx.lineTo(x + 6, y);
    ctx.lineTo(x + w - 6, y);
    ctx.lineTo(x + w + 3, y + roofH);
    ctx.closePath();
    ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,0.16)';
    ctx.fillRect(x + 6, y, w - 12, 4);
    ctx.fillStyle = 'rgba(0,0,0,0.12)';
    for (let i = 1; i < 4; i++) ctx.fillRect(x, y + (roofH * i) / 4, w, 2);
    // 扉と窓
    const doorW = Math.min(18, Math.max(12, w * 0.16));
    rect(ctx, '#4a2c18', x + w / 2 - doorW / 2, y + h - 24, doorW, 24);
    rect(ctx, '#c8a060', x + w / 2 + doorW / 2 - 5, y + h - 13, 2, 2);
    const windows = Math.max(1, Math.floor((w - 30) / 28));
    for (let i = 0; i < windows; i++) {
        const wx = x + 10 + i * 28;
        if (Math.abs(wx + 7 - (x + w / 2)) < doorW) continue;
        rect(ctx, '#3a4a5a', wx, y + h - wallH + 8, 14, 11);
        rect(ctx, opts.lit ? '#f4d67a' : '#8ab0d0', wx + 1, y + h - wallH + 9, 12, 9);
        rect(ctx, 'rgba(0,0,0,0.3)', wx + 6, y + h - wallH + 9, 1, 9);
    }
    for (let i = 0; i < windows; i++) {
        const wx = x + w - 24 - i * 28;
        if (wx < x + 10 || Math.abs(wx + 7 - (x + w / 2)) < doorW) continue;
        rect(ctx, '#3a4a5a', wx, y + h - wallH + 8, 14, 11);
        rect(ctx, opts.lit ? '#f4d67a' : '#8ab0d0', wx + 1, y + h - wallH + 9, 12, 9);
    }
    if (hash2i(seed, 1, 5) < 0.45) {
        const cx = x + w * (0.25 + hash2i(seed, 2, 5) * 0.5);
        rect(ctx, '#6a5a52', cx, y - 8, 8, 14);
        rect(ctx, '#4a3a32', cx - 1, y - 10, 10, 3);
    }
}

function label(ctx, text, x, y, color = '#fff', size = 13) {
    ctx.font = `bold ${size}px "Hiragino Kaku Gothic ProN", "Noto Sans JP", sans-serif`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'bottom';
    ctx.lineWidth = 4;
    ctx.strokeStyle = 'rgba(0,0,0,0.8)';
    ctx.strokeText(text, x, y);
    ctx.fillStyle = color;
    ctx.fillText(text, x, y);
}

function signboard(ctx, char, x, y, color) {
    rect(ctx, '#5a3a1e', x - 13, y - 13, 26, 26);
    rect(ctx, color, x - 11, y - 11, 22, 22);
    ctx.font = 'bold 15px "Hiragino Mincho ProN", "Noto Serif JP", serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillStyle = '#fff8e0';
    ctx.fillText(char, x, y + 1);
}

function tower(ctx, x, y, w, h, body, roof, lit = false) {
    rect(ctx, body, x, y + w * 0.5, w, h - w * 0.5);
    rect(ctx, 'rgba(0,0,0,0.18)', x + w * 0.65, y + w * 0.5, w * 0.35, h - w * 0.5);
    ctx.fillStyle = roof;
    ctx.beginPath();
    ctx.moveTo(x - 4, y + w * 0.6);
    ctx.lineTo(x + w / 2, y - w * 0.7);
    ctx.lineTo(x + w + 4, y + w * 0.6);
    ctx.closePath();
    ctx.fill();
    if (lit) rect(ctx, '#f4d67a', x + w / 2 - 3, y + w * 0.9, 6, 9);
}

// ---- 名所 --------------------------------------------------------------------------------

function castle(ctx, x, y, w, h, palette) {
    const { stone, dark, roof } = palette;
    shadow(ctx, x + 10, y + h + 4, w - 10);
    // 外壁
    rect(ctx, stone, x + 20, y + h * 0.35, w - 40, h * 0.65);
    rect(ctx, dark, x + 20, y + h - 10, w - 40, 10);
    for (let i = 0; i < (w - 40) / 16; i++) rect(ctx, stone, x + 20 + i * 16, y + h * 0.35 - 8, 10, 8);
    // 本丸
    rect(ctx, stone, x + w * 0.3, y + h * 0.1, w * 0.4, h * 0.6);
    rect(ctx, 'rgba(0,0,0,0.15)', x + w * 0.55, y + h * 0.1, w * 0.15, h * 0.6);
    ctx.fillStyle = roof;
    ctx.beginPath();
    ctx.moveTo(x + w * 0.27, y + h * 0.14);
    ctx.lineTo(x + w * 0.5, y - h * 0.22);
    ctx.lineTo(x + w * 0.73, y + h * 0.14);
    ctx.closePath();
    ctx.fill();
    // 塔
    const towers = [[0, 0.25], [w - 44, 0.25], [w * 0.18, 0.02], [w * 0.82 - 44, 0.02]];
    for (const [tx, ty] of towers) tower(ctx, x + tx, y + h * ty, 44, h * 0.75, stone, roof, true);
    // 門
    rect(ctx, '#2a1a10', x + w / 2 - 26, y + h - 58, 52, 58);
    ctx.fillStyle = '#2a1a10';
    ctx.beginPath();
    ctx.arc(x + w / 2, y + h - 58, 26, Math.PI, 0);
    ctx.fill();
    // 窓
    for (let i = 0; i < 6; i++) rect(ctx, '#f4d67a', x + w * 0.34 + i * (w * 0.32 / 6), y + h * 0.3, 6, 12);
    // 旗
    rect(ctx, '#5a4a3a', x + w / 2 - 1, y - h * 0.22 - 30, 3, 32);
    rect(ctx, palette.flag ?? '#c83a3a', x + w / 2 + 2, y - h * 0.22 - 30, 22, 13);
}

function worldTree(ctx, x, y, w, h, t) {
    const cx = x + w / 2;
    const cy = y + h / 2;
    // 根
    ctx.strokeStyle = '#5a3a22';
    ctx.lineCap = 'round';
    for (let i = 0; i < 10; i++) {
        const a = (i / 10) * Math.PI * 2 + 0.2;
        ctx.lineWidth = 18 - (i % 3) * 4;
        ctx.beginPath();
        ctx.moveTo(cx + Math.cos(a) * w * 0.3, cy + Math.sin(a) * h * 0.3);
        ctx.quadraticCurveTo(cx + Math.cos(a + 0.3) * w * 0.55, cy + Math.sin(a + 0.3) * h * 0.55, cx + Math.cos(a + 0.1) * w * 0.72, cy + Math.sin(a + 0.1) * h * 0.72);
        ctx.stroke();
    }
    // 幹
    ctx.fillStyle = '#6a4428';
    ctx.beginPath();
    ctx.arc(cx, cy, w * 0.36, 0, Math.PI * 2);
    ctx.fill();
    ctx.strokeStyle = '#4a2c18';
    ctx.lineWidth = 4;
    for (let i = 0; i < 8; i++) {
        ctx.beginPath();
        ctx.arc(cx, cy, w * (0.08 + i * 0.035), 0, Math.PI * 2);
        ctx.stroke();
    }
    // 精霊の光
    for (let i = 0; i < 12; i++) {
        const a = (i / 12) * Math.PI * 2 + t * 0.2;
        const r = w * (0.45 + 0.1 * Math.sin(t + i));
        ctx.fillStyle = `rgba(140,255,220,${0.5 + 0.4 * Math.sin(t * 2 + i)})`;
        ctx.beginPath();
        ctx.arc(cx + Math.cos(a) * r, cy + Math.sin(a) * r * 0.9, 4, 0, Math.PI * 2);
        ctx.fill();
    }
}

function flame(ctx, x, y, s, t) {
    const f = Math.sin(t * 11 + x) * 2;
    ctx.fillStyle = '#ff7a20';
    ctx.beginPath();
    ctx.moveTo(x - 7 * s, y);
    ctx.quadraticCurveTo(x + f, y - 22 * s, x + 7 * s, y);
    ctx.fill();
    ctx.fillStyle = '#ffe07a';
    ctx.beginPath();
    ctx.moveTo(x - 3.5 * s, y);
    ctx.quadraticCurveTo(x - f, y - 12 * s, x + 3.5 * s, y);
    ctx.fill();
}

function smoke(ctx, x, y, t) {
    for (let i = 0; i < 4; i++) {
        const p = (t * 0.35 + i / 4) % 1;
        ctx.fillStyle = `rgba(90,90,100,${0.35 * (1 - p)})`;
        ctx.beginPath();
        ctx.arc(x + Math.sin(p * 6 + i) * 6, y - p * 70, 6 + p * 14, 0, Math.PI * 2);
        ctx.fill();
    }
}

// ---- 入口 --------------------------------------------------------------------------------

const ENTRANCE_LOOKS = {
    cave: { shape: 'arch', rock: '#7d7466', mouth: '#1a1414' },
    sea_cave: { shape: 'arch', rock: '#6a7a86', mouth: '#10202a' },
    ice_cave: { shape: 'arch', rock: '#a9bccf', mouth: '#203040' },
    mine: { shape: 'mine', rock: '#7d7466', mouth: '#1a1414' },
    hideout: { shape: 'arch', rock: '#6a5a4a', mouth: '#1a1010' },
    canyon: { shape: 'arch', rock: '#9a7a5a', mouth: '#2a1a14' },
    mountain: { shape: 'arch', rock: '#9eb2c6', mouth: '#203040' },
    dragon_lair: { shape: 'arch', rock: '#7a8aa0', mouth: '#1a1a2a' },
    forest: { shape: 'grove', leaf: '#3f7f36' },
    fairy_forest: { shape: 'grove', leaf: '#4fa87a', glow: '#b8ffe8' },
    snow_forest: { shape: 'grove', leaf: '#6a9a8a' },
    roots: { shape: 'grove', leaf: '#5a4a2a' },
    tree_door: { shape: 'arch', rock: '#6a4428', mouth: '#1a2a10' },
    meadow: { shape: 'sign', color: '#8a6a3a' },
    hill: { shape: 'sign', color: '#8a6a3a' },
    snowfield: { shape: 'sign', color: '#6a7a8a' },
    dunes: { shape: 'sign', color: '#a0804a' },
    quicksand: { shape: 'sign', color: '#a0804a' },
    wasteland: { shape: 'sign', color: '#5a4a64' },
    cloud_field: { shape: 'sign', color: '#8ac0e8' },
    beach: { shape: 'sign', color: '#8a6a3a' },
    cove: { shape: 'arch', rock: '#6a7a86', mouth: '#1a3a5a' },
    spring: { shape: 'spring' },
    graveyard: { shape: 'gate', color: '#4a4050', accent: '#8a8090' },
    training: { shape: 'building', roof: '#7a4a2a', wall: '#d8c8a8' },
    shipwreck: { shape: 'wreck' },
    coral: { shape: 'arch', rock: '#e08a8a', mouth: '#1a3a5a' },
    sea_temple: { shape: 'temple', stone: '#8ab0c8', roof: '#3f6f8a' },
    temple: { shape: 'temple', stone: '#d8d0c0', roof: '#6a6a8a' },
    sun_temple: { shape: 'temple', stone: '#e8c880', roof: '#c88a3a' },
    thunder_temple: { shape: 'temple', stone: '#c8c8e0', roof: '#5a5aa0' },
    garden: { shape: 'gate', color: '#6a8a5a', accent: '#f0c8e0' },
    furnace: { shape: 'building', roof: '#4a3a34', wall: '#8a5a4a', smoke: true },
    factory: { shape: 'building', roof: '#4a4a52', wall: '#8a8a90', smoke: true },
    ruins: { shape: 'ruins', stone: '#b8b0a0' },
    ancient_forge: { shape: 'ruins', stone: '#8a6a5a', glow: '#ff8a3a' },
    pyramid: { shape: 'pyramid' },
    library: { shape: 'building', roof: '#4a3a8a', wall: '#d8d0e8' },
    lab: { shape: 'building', roof: '#3a5a6a', wall: '#c8d8d8', glow: '#8affd0' },
    tower: { shape: 'tower', stone: '#c8c0d8', roof: '#4a3a8a' },
    observatory: { shape: 'tower', stone: '#a8b0c8', roof: '#2a3a6a' },
    portal: { shape: 'portal', color: '#b070ff' },
    rift: { shape: 'portal', color: '#ff3a6a' },
    cursed_castle: { shape: 'tower', stone: '#5a5060', roof: '#2a2030' },
    underworld_gate: { shape: 'gate', color: '#2a1a2a', accent: '#ff4a3a' },
    fortress: { shape: 'building', roof: '#3a2a30', wall: '#6a5a5a' },
    abyss_stairs: { shape: 'stairs' },
    altar: { shape: 'ruins', stone: '#f0ece0', glow: '#ffe890' },
    sky_corridor: { shape: 'gate', color: '#e8e4d8', accent: '#8ac0e8' },
    dragon_sanctuary: { shape: 'temple', stone: '#d8e0f0', roof: '#c8a040' },
    demon_gate: { shape: 'gate', color: '#3a2030', accent: '#ff3a2a' },
    dark_corridor: { shape: 'arch', rock: '#3a2e40', mouth: '#0a0008' },
    demon_hall: { shape: 'temple', stone: '#4a3a44', roof: '#2a1a24' },
    prison: { shape: 'gate', color: '#3a3a44', accent: '#8a8a9a' },
    throne: { shape: 'temple', stone: '#3a2a34', roof: '#6a1a2a' },
    castle_core: { shape: 'portal', color: '#ff2a4a' },
    final_altar: { shape: 'ruins', stone: '#2a2030', glow: '#ff2a4a' },
};

function entrance(ctx, o, t) {
    const x = o.tx * T;
    const y = o.ty * T;
    const w = o.tw * T;
    const h = o.th * T;
    const look = ENTRANCE_LOOKS[o.kind] ?? ENTRANCE_LOOKS.cave;
    const cx = x + w / 2;
    shadow(ctx, x, y + h, w);
    switch (look.shape) {
        case 'arch':
        case 'mine': {
            ctx.fillStyle = look.rock;
            ctx.beginPath();
            ctx.moveTo(x - 6, y + h);
            ctx.quadraticCurveTo(x + 4, y - 10, cx, y - 14);
            ctx.quadraticCurveTo(x + w - 4, y - 10, x + w + 6, y + h);
            ctx.closePath();
            ctx.fill();
            ctx.fillStyle = 'rgba(255,255,255,0.15)';
            ctx.beginPath();
            ctx.arc(cx - 20, y + 20, 18, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = look.mouth;
            ctx.beginPath();
            ctx.moveTo(cx - 22, y + h);
            ctx.lineTo(cx - 22, y + h - 34);
            ctx.arc(cx, y + h - 34, 22, Math.PI, 0);
            ctx.lineTo(cx + 22, y + h);
            ctx.closePath();
            ctx.fill();
            if (look.shape === 'mine') {
                rect(ctx, '#8a6034', cx - 26, y + h - 60, 6, 60);
                rect(ctx, '#8a6034', cx + 20, y + h - 60, 6, 60);
                rect(ctx, '#a0703a', cx - 30, y + h - 64, 60, 8);
            }
            break;
        }
        case 'grove':
            for (const [dx, dy, r] of [[-40, 30, 30], [40, 30, 30], [-20, 6, 32], [20, 6, 32], [0, -8, 30]]) {
                ctx.fillStyle = look.leaf;
                ctx.beginPath();
                ctx.arc(cx + dx, y + dy + 20, r, 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.fillStyle = '#1a2a14';
            ctx.beginPath();
            ctx.arc(cx, y + h - 20, 20, Math.PI, 0);
            ctx.lineTo(cx + 20, y + h);
            ctx.lineTo(cx - 20, y + h);
            ctx.fill();
            if (look.glow) {
                for (let i = 0; i < 6; i++) {
                    ctx.fillStyle = look.glow;
                    ctx.globalAlpha = 0.5 + 0.5 * Math.sin(t * 3 + i);
                    ctx.fillRect(cx - 50 + i * 20, y + 10 + (i % 3) * 16, 4, 4);
                }
                ctx.globalAlpha = 1;
            }
            break;
        case 'sign':
            rect(ctx, '#5a3a1e', cx - 3, y + h - 50, 6, 50);
            rect(ctx, look.color, cx - 34, y + h - 58, 68, 26);
            rect(ctx, 'rgba(255,255,255,0.25)', cx - 32, y + h - 56, 64, 4);
            break;
        case 'spring':
            ctx.fillStyle = '#8a8476';
            ctx.beginPath();
            ctx.ellipse(cx, y + h - 40, 62, 34, 0, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#5ab0e8';
            ctx.beginPath();
            ctx.ellipse(cx, y + h - 42, 54, 27, 0, 0, Math.PI * 2);
            ctx.fill();
            for (let i = 0; i < 5; i++) {
                ctx.fillStyle = `rgba(255,255,255,${0.5 + 0.5 * Math.sin(t * 4 + i * 2)})`;
                ctx.fillRect(cx - 40 + i * 18, y + h - 50 + (i % 2) * 10, 3, 3);
            }
            break;
        case 'gate':
            rect(ctx, look.color, x + 8, y + 10, 18, h - 10);
            rect(ctx, look.color, x + w - 26, y + 10, 18, h - 10);
            rect(ctx, look.color, x, y, w, 18);
            rect(ctx, look.accent, x + 4, y + 4, w - 8, 6);
            rect(ctx, 'rgba(0,0,0,0.55)', x + 26, y + 18, w - 52, h - 18);
            break;
        case 'building':
            house(ctx, x, y, w, h, look.roof, look.wall, hashStr(o.kind));
            if (look.smoke) smoke(ctx, x + w * 0.75, y - 4, t);
            if (look.glow) {
                ctx.fillStyle = look.glow;
                ctx.globalAlpha = 0.4 + 0.3 * Math.sin(t * 3);
                ctx.fillRect(x + 10, y + h - 40, w - 20, 6);
                ctx.globalAlpha = 1;
            }
            break;
        case 'temple':
            rect(ctx, look.stone, x, y + 22, w, h - 22);
            ctx.fillStyle = look.roof;
            ctx.beginPath();
            ctx.moveTo(x - 8, y + 26);
            ctx.lineTo(cx, y - 14);
            ctx.lineTo(x + w + 8, y + 26);
            ctx.closePath();
            ctx.fill();
            for (let i = 0; i < 5; i++) rect(ctx, 'rgba(0,0,0,0.18)', x + 10 + i * ((w - 20) / 4) - 3, y + 30, 6, h - 34);
            rect(ctx, '#1a1418', cx - 14, y + h - 34, 28, 34);
            break;
        case 'tower':
            tower(ctx, cx - 24, y - 50, 48, h + 50, look.stone, look.roof, true);
            rect(ctx, '#1a1418', cx - 10, y + h - 26, 20, 26);
            break;
        case 'ruins':
            for (let i = 0; i < 5; i++) {
                const px = x + 6 + i * ((w - 20) / 4);
                const ph = 30 + hash2i(o.area_id, i, 7) * 50;
                rect(ctx, look.stone, px, y + h - ph, 12, ph);
                rect(ctx, 'rgba(0,0,0,0.2)', px + 8, y + h - ph, 4, ph);
            }
            rect(ctx, '#1a1418', cx - 16, y + h - 30, 32, 30);
            if (look.glow) {
                ctx.fillStyle = look.glow;
                ctx.globalAlpha = 0.35 + 0.25 * Math.sin(t * 2.5);
                ctx.beginPath();
                ctx.arc(cx, y + h - 20, 26, 0, Math.PI * 2);
                ctx.fill();
                ctx.globalAlpha = 1;
            }
            break;
        case 'pyramid':
            ctx.fillStyle = '#d8b070';
            ctx.beginPath();
            ctx.moveTo(x - 20, y + h);
            ctx.lineTo(cx, y - 60);
            ctx.lineTo(x + w + 20, y + h);
            ctx.closePath();
            ctx.fill();
            ctx.fillStyle = '#b8904a';
            ctx.beginPath();
            ctx.moveTo(cx, y - 60);
            ctx.lineTo(x + w + 20, y + h);
            ctx.lineTo(cx + 10, y + h);
            ctx.closePath();
            ctx.fill();
            rect(ctx, '#1a1410', cx - 12, y + h - 26, 24, 26);
            break;
        case 'portal':
            for (let i = 0; i < 3; i++) {
                ctx.strokeStyle = look.color;
                ctx.globalAlpha = 0.9 - i * 0.25;
                ctx.lineWidth = 5 - i;
                ctx.beginPath();
                ctx.ellipse(cx, y + h / 2 + 4, 34 + i * 8 + Math.sin(t * 3 + i) * 3, 50 + i * 6, 0, 0, Math.PI * 2);
                ctx.stroke();
            }
            ctx.globalAlpha = 1;
            ctx.fillStyle = 'rgba(20,0,30,0.6)';
            ctx.beginPath();
            ctx.ellipse(cx, y + h / 2 + 4, 30, 46, 0, 0, Math.PI * 2);
            ctx.fill();
            break;
        case 'wreck':
            ctx.fillStyle = '#6a4a2a';
            ctx.beginPath();
            ctx.moveTo(x - 10, y + h - 20);
            ctx.lineTo(x + w + 10, y + h - 40);
            ctx.lineTo(x + w - 10, y + h);
            ctx.lineTo(x, y + h);
            ctx.closePath();
            ctx.fill();
            rect(ctx, '#4a3220', cx - 3, y - 20, 6, h);
            rect(ctx, 'rgba(230,220,200,0.7)', cx + 3, y - 10, 30, 26);
            break;
        case 'stairs':
            rect(ctx, '#2a2030', x, y, w, h);
            for (let i = 0; i < 6; i++) rect(ctx, `rgba(120,100,140,${0.8 - i * 0.12})`, x + 8 + i * 4, y + 8 + i * 16, w - 16 - i * 8, 10);
            break;
        default:
            break;
    }
    const lvl = o.level ? `Lv${o.level[0]}〜${o.level[1]}` : '';
    const locked = !o.enterable;
    label(ctx, `${locked ? '🔒 ' : ''}${o.name}`, cx, y - 18, locked ? '#c8c0b8' : '#fff4c8', 13);
    if (lvl) label(ctx, lvl, cx, y - 2, '#d8e8ff', 11);
}

// ---- まとめ ------------------------------------------------------------------------------

const PALETTES = {
    royal_capital: { stone: '#e6dcc6', dark: '#b8ab92', roof: '#3f5f9e', flag: '#c83a3a' },
    demon_castle: { stone: '#3e3440', dark: '#2a2030', roof: '#5a1a2a', flag: '#8a1a2a' },
    sky_temple: { stone: '#f6f4ee', dark: '#d8d4c8', roof: '#e8c860', flag: '#6aa0d8' },
};

export function drawObject(ctx, o, t, images, player = null) {
    const img = images?.size ? assetKeysFor(o).map((k) => images.get(k)).find(Boolean) : null;
    const x = o.tx * T;
    const y = o.ty * T;
    const w = o.tw * T;
    const h = o.th * T;
    if (img) {
        const scale = w / img.width;
        const dh = img.height * scale;
        if (o.type === 'teleporter' || o.type === 'barrier') {
            // 地面の陣は操作・封鎖範囲の中心へ合わせる。
            const top = y + h / 2 - dh * sealGroundAnchorY(o);
            ctx.drawImage(img, x, top, w, dh);
            const broken = o.type === 'barrier' && o.unlocked;
            const color = o.type === 'teleporter' ? (o.unlocked ? '#c8f0ff' : '#b8b8c0') : (broken ? '#c8b8e0' : '#f0d8ff');
            label(ctx, `${o.name}${broken ? '（破れている）' : ''}`, x + w / 2, top - 4, color, o.type === 'teleporter' ? 14 : broken ? 13 : 15);
            return;
        }
        // 地面に描かれた魔法陣には、立体物の接地影を足さない。
        if (o.type !== 'magic_circle' && !isBuilding(o)) shadow(ctx, x, y + h, w);
        const alpha = ctx.globalAlpha;
        // 階段・入口や建物の横は背後ではない。接地判定の北端を基準にする。
        const behind = isBuilding(o) && player && player.x >= x && player.x < x + w
            && player.y < objectFootprint(o).ty * TILE && player.y >= y + h - dh;
        if (behind) ctx.globalAlpha = 0.45;
        ctx.drawImage(img, x, y + h - dh, w, dh);
        if (o.type === 'windmill') {
            // 採用原画の回転軸は、余白除去後の画像高の39%の位置。
            windmillSails(ctx, x + w / 2, y + h - dh * 0.61, t);
        }
        ctx.globalAlpha = alpha;
        if (o.type === 'entrance') {
            label(ctx, `${o.enterable ? '' : '🔒 '}${o.name}`, x + w / 2, y + h - dh - 18, o.enterable ? '#fff4c8' : '#c8c0b8', 13);
            if (o.level) label(ctx, `Lv${o.level[0]}〜${o.level[1]}`, x + w / 2, y + h - dh - 2, '#d8e8ff', 11);
        } else if (o.type === 'waystone') {
            label(ctx, o.discovered ? '旅の祠（記憶済み）' : '旅の祠', x + w / 2, y + h - dh - 4, o.discovered ? '#a8e4ff' : '#fff4c8', 12);
        } else if (o.type !== 'signpost' && (o.label || o.name)) {
            label(ctx, o.label ?? o.name, x + w / 2, y + h - dh - 4);
        }
        return;
    }
    switch (o.type) {
        case 'house':
            house(ctx, x, y, w, h, o.roof, o.wall, o.variant, { lit: hash2i(o.variant, 3, 9) < 0.5 });
            return;
        case 'facility': {
            const roof = { inn: '#b04a3a', temple: '#e8e0c8', tavern: '#8a5a2a', bank: '#3a6a5a', blacksmith: '#4a3a34', equipment_shop: '#3f5f9e' }[o.facility] ?? '#6a4a8a';
            house(ctx, x, y, w, h, roof, '#efe6d0', o.variant * 97 + 13, { lit: true });
            if (o.facility === 'blacksmith') smoke(ctx, x + w * 0.8, y - 6, t);
            signboard(ctx, FACILITY_SIGNS[o.facility] ?? '？', x + w / 2 + 34, y + h - 30, '#7a2a1a');
            label(ctx, o.label, x + w / 2, y - 4, '#fff4c8', 14);
            return;
        }
        case 'board':
            rect(ctx, '#5a3a1e', x + 8, y + 20, 6, h - 20);
            rect(ctx, '#5a3a1e', x + w - 14, y + 20, 6, h - 20);
            rect(ctx, '#9a6a3a', x, y, w, 40);
            for (let i = 0; i < 5; i++) rect(ctx, '#efe5c8', x + 8 + i * 36, y + 6, 28, 26);
            label(ctx, o.label, x + w / 2, y - 4, '#fff4c8', 13);
            return;
        case 'castle':
        case 'demon_castle':
            castle(ctx, x, y, w, h, PALETTES[o.style] ?? PALETTES.royal_capital);
            if (o.type === 'demon_castle') {
                ctx.fillStyle = `rgba(255,40,60,${0.25 + 0.15 * Math.sin(t * 2)})`;
                ctx.beginPath();
                ctx.arc(x + w / 2, y + h * 0.1, w * 0.32, 0, Math.PI * 2);
                ctx.fill();
            }
            label(ctx, o.label, x + w / 2, y - h * 0.3, '#ffe8a0', 16);
            return;
        case 'world_tree':
            worldTree(ctx, x, y, w, h, t);
            label(ctx, o.label, x + w / 2, y - 6, '#c8ffe8', 16);
            return;
        case 'furnace':
            house(ctx, x, y, w, h, '#3a302c', '#6a5048', 7);
            rect(ctx, '#ff8a2a', x + w / 2 - 40, y + h - 40, 80, 26);
            ctx.fillStyle = `rgba(255,160,60,${0.4 + 0.3 * Math.sin(t * 5)})`;
            ctx.fillRect(x + w / 2 - 48, y + h - 48, 96, 40);
            smoke(ctx, x + w * 0.3, y - 10, t);
            smoke(ctx, x + w * 0.7, y - 10, t + 0.5);
            label(ctx, o.label, x + w / 2, y - 12, '#ffd8a0', 16);
            return;
        case 'cathedral': {
            const icy = o.style === 'snow';
            const stone = icy ? '#d8e8f4' : '#2e2634';
            const roof = icy ? '#6a9ac8' : '#1a1420';
            rect(ctx, stone, x + 20, y + h * 0.3, w - 40, h * 0.7);
            for (const tx of [x, x + w - 44, x + w / 2 - 30]) tower(ctx, tx, y - h * 0.1, tx === x + w / 2 - 30 ? 60 : 44, h * 0.8, stone, roof, true);
            ctx.fillStyle = icy ? '#a8e0ff' : '#b04aff';
            ctx.beginPath();
            ctx.arc(x + w / 2, y + h * 0.5, 22, 0, Math.PI * 2);
            ctx.fill();
            rect(ctx, '#1a1418', x + w / 2 - 20, y + h - 50, 40, 50);
            label(ctx, o.label, x + w / 2, y - h * 0.35, '#fff', 16);
            return;
        }
        case 'magic_tower':
            tower(ctx, x, y - h * 0.5, w, h * 1.5, '#d8d0e8', '#4a3a8a', true);
            ctx.fillStyle = `rgba(190,140,255,${0.6 + 0.3 * Math.sin(t * 2)})`;
            ctx.beginPath();
            ctx.arc(x + w / 2, y - h * 0.5 - w * 0.8, 12, 0, Math.PI * 2);
            ctx.fill();
            rect(ctx, '#1a1418', x + w / 2 - 16, y + h - 40, 32, 40);
            label(ctx, o.label, x + w / 2, y - h * 0.5 - w, '#e8d8ff', 16);
            return;
        case 'academy_hall':
            house(ctx, x, y, w, h, '#4a3a8a', '#e4e0ee', 11, { lit: true });
            label(ctx, o.label, x + w / 2, y - 4, '#e8d8ff', 13);
            return;
        case 'sky_temple':
            rect(ctx, '#f6f4ee', x, y + h * 0.3, w, h * 0.7);
            for (let i = 0; i < 9; i++) rect(ctx, '#d8d4c8', x + 16 + i * ((w - 40) / 8), y + h * 0.35, 10, h * 0.6);
            ctx.fillStyle = '#e8c860';
            ctx.beginPath();
            ctx.moveTo(x - 12, y + h * 0.34);
            ctx.lineTo(x + w / 2, y - h * 0.15);
            ctx.lineTo(x + w + 12, y + h * 0.34);
            ctx.closePath();
            ctx.fill();
            label(ctx, o.label, x + w / 2, y - h * 0.2, '#fff8d0', 16);
            return;
        case 'lighthouse':
            tower(ctx, x + 20, y - 120, w - 40, h + 120, '#f4f0e8', '#c83a3a', true);
            for (let i = 0; i < 4; i++) rect(ctx, '#c83a3a', x + 20, y - 60 + i * 50, w - 40, 12);
            ctx.fillStyle = `rgba(255,240,160,${0.35 + 0.25 * Math.sin(t * 2)})`;
            ctx.beginPath();
            ctx.moveTo(x + w / 2, y - 110);
            ctx.lineTo(x + w / 2 - 160 * Math.cos(t * 0.8), y - 150 - 40 * Math.sin(t * 0.8));
            ctx.lineTo(x + w / 2 - 160 * Math.cos(t * 0.8 + 0.25), y - 150 - 40 * Math.sin(t * 0.8 + 0.25));
            ctx.closePath();
            ctx.fill();
            label(ctx, o.label, x + w / 2, y - 150, '#fff', 14);
            return;
        case 'ship':
            ctx.fillStyle = '#6a4424';
            ctx.beginPath();
            ctx.moveTo(x, y + h * 0.4);
            ctx.lineTo(x + w, y + h * 0.4);
            ctx.lineTo(x + w - 30, y + h);
            ctx.lineTo(x + 30, y + h);
            ctx.closePath();
            ctx.fill();
            rect(ctx, '#8a5a2a', x + 20, y + h * 0.4, w - 40, 10);
            for (const mx of [x + w * 0.35, x + w * 0.65]) {
                rect(ctx, '#4a3220', mx - 3, y - h * 0.8, 6, h * 1.2);
                rect(ctx, '#f4efe0', mx - 36, y - h * 0.7, 72, h * 0.8);
                rect(ctx, 'rgba(0,0,0,0.08)', mx, y - h * 0.7, 36, h * 0.8);
            }
            if (o.label) label(ctx, o.label, x + w / 2, y - h * 0.9, '#fff', 13);
            return;
        case 'tent': {
            const colors = ['#c84a3a', '#3a6ac8', '#d8b040', '#4a9a5a'];
            ctx.fillStyle = colors[o.variant % colors.length];
            ctx.beginPath();
            ctx.moveTo(x - 4, y + h);
            ctx.lineTo(x + w / 2, y - 12);
            ctx.lineTo(x + w + 4, y + h);
            ctx.closePath();
            ctx.fill();
            ctx.fillStyle = 'rgba(255,255,255,0.25)';
            ctx.beginPath();
            ctx.moveTo(x + w / 2, y - 12);
            ctx.lineTo(x + w + 4, y + h);
            ctx.lineTo(x + w / 2 + 6, y + h);
            ctx.closePath();
            ctx.fill();
            rect(ctx, '#2a1a10', x + w / 2 - 8, y + h - 22, 16, 22);
            return;
        }
        case 'stall': {
            const colors = ['#c84a3a', '#3a6ac8', '#d8b040', '#4a9a5a'];
            rect(ctx, '#8a6034', x + 4, y + 16, w - 8, h - 16);
            for (let i = 0; i < 6; i++) rect(ctx, i % 2 ? '#f4efe0' : colors[o.variant % colors.length], x - 4 + i * ((w + 8) / 6), y, (w + 8) / 6, 18);
            rect(ctx, '#e8c860', x + 10, y + 22, 8, 6);
            rect(ctx, '#8ac060', x + 24, y + 22, 8, 6);
            return;
        }
        case 'fountain':
            ctx.fillStyle = '#b8b0a0';
            ctx.beginPath();
            ctx.ellipse(x + w / 2, y + h / 2, w / 2, h / 2.4, 0, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#5ab0e8';
            ctx.beginPath();
            ctx.ellipse(x + w / 2, y + h / 2, w / 2 - 8, h / 2.4 - 8, 0, 0, Math.PI * 2);
            ctx.fill();
            rect(ctx, '#d8d0c0', x + w / 2 - 6, y + h / 2 - 26, 12, 26);
            for (let i = 0; i < 6; i++) {
                const a = (i / 6) * Math.PI * 2 + t * 2;
                ctx.fillStyle = 'rgba(230,245,255,0.8)';
                ctx.fillRect(x + w / 2 + Math.cos(a) * 16, y + h / 2 - 20 + Math.sin(a) * 6, 3, 3);
            }
            return;
        case 'statue':
        case 'angel_statue':
        case 'ice_statue': {
            const c = o.type === 'ice_statue' ? '#bfe4ff' : o.type === 'angel_statue' ? '#f4f2ec' : '#b8b0a0';
            rect(ctx, '#8a8478', x - 4, y + h - 14, w + 8, 14);
            rect(ctx, c, x + w / 2 - 8, y - 24, 16, h + 10);
            ctx.fillStyle = c;
            ctx.beginPath();
            ctx.arc(x + w / 2, y - 30, 9, 0, Math.PI * 2);
            ctx.fill();
            if (o.type === 'angel_statue') {
                ctx.beginPath();
                ctx.ellipse(x + w / 2 - 16, y - 10, 12, 20, -0.4, 0, Math.PI * 2);
                ctx.ellipse(x + w / 2 + 16, y - 10, 12, 20, 0.4, 0, Math.PI * 2);
                ctx.fill();
            }
            return;
        }
        case 'oasis_shrine':
            rect(ctx, '#e8d2a4', x, y + 20, w, h - 20);
            ctx.fillStyle = '#3aa0c8';
            ctx.beginPath();
            ctx.arc(x + w / 2, y + 22, w / 2, Math.PI, 0);
            ctx.fill();
            label(ctx, o.label, x + w / 2, y - w / 2 + 16, '#fff8d0', 14);
            return;
        case 'magic_circle':
            ctx.strokeStyle = `rgba(190,140,255,${0.5 + 0.3 * Math.sin(t * 2 + x)})`;
            ctx.lineWidth = 3;
            ctx.beginPath();
            ctx.arc(x + w / 2, y + h / 2, w / 2 - 4, 0, Math.PI * 2);
            ctx.stroke();
            ctx.beginPath();
            for (let i = 0; i < 5; i++) {
                const a = (i * 4 * Math.PI) / 5 + t * 0.3;
                ctx.lineTo(x + w / 2 + Math.cos(a) * (w / 2 - 8), y + h / 2 + Math.sin(a) * (h / 2 - 8));
            }
            ctx.closePath();
            ctx.stroke();
            return;
        case 'bonfire':
            rect(ctx, '#5a3a22', x + 8, y + h - 12, w - 16, 8);
            flame(ctx, x + w / 2, y + h - 10, 1.8, t);
            return;
        case 'spirit_lantern':
            rect(ctx, '#5a4a3a', x + 14, y + 8, 4, 24);
            ctx.fillStyle = `rgba(140,255,220,${0.6 + 0.3 * Math.sin(t * 3 + x)})`;
            ctx.beginPath();
            ctx.arc(x + 16, y + 4, 7, 0, Math.PI * 2);
            ctx.fill();
            return;
        case 'lamp':
            rect(ctx, '#3a3a3a', x + 14, y - 14, 4, 44);
            rect(ctx, '#f4d67a', x + 10, y - 20, 12, 10);
            return;
        case 'barrel':
            rect(ctx, '#7a5230', x + 7, y + 6, 18, 22);
            rect(ctx, '#4a3220', x + 7, y + 10, 18, 3);
            rect(ctx, '#4a3220', x + 7, y + 21, 18, 3);
            return;
        case 'crate':
            rect(ctx, '#9a7040', x + 5, y + 6, 22, 22);
            ctx.strokeStyle = '#6a4a28';
            ctx.lineWidth = 2;
            ctx.strokeRect(x + 6, y + 7, 20, 20);
            return;
        case 'anvil':
            rect(ctx, '#3a3a40', x + 10, y + 8, w - 20, 12);
            rect(ctx, '#2a2a30', x + w / 2 - 6, y + 20, 12, 10);
            return;
        case 'chimney':
            rect(ctx, '#5a4a44', x + 10, y - 40, w - 20, h + 40);
            smoke(ctx, x + w / 2, y - 44, t);
            return;
        case 'tombstone':
            rect(ctx, '#7a7480', x + 8, y + 4, 16, 22);
            ctx.fillStyle = '#7a7480';
            ctx.beginPath();
            ctx.arc(x + 16, y + 6, 8, Math.PI, 0);
            ctx.fill();
            rect(ctx, '#4a4450', x + 14, y + 8, 4, 12);
            return;
        case 'mausoleum':
        case 'mine_gate':
            house(ctx, x, y, w, h, o.type === 'mausoleum' ? '#2a2030' : '#5a4a3a', o.type === 'mausoleum' ? '#5a5060' : '#8a7a6a', 3);
            label(ctx, o.label, x + w / 2, y - 4, '#e8e0ff', 13);
            return;
        case 'gate_tower':
        case 'wall_tower': {
            const stone = o.style === 'demon_castle' || o.style === 'necro' ? '#4a4050' : o.style === 'sky_temple' ? '#f0eee8' : '#9a9488';
            const roof = o.style === 'royal_capital' ? '#3f5f9e' : o.style === 'demon_castle' ? '#5a1a2a' : '#6a5a4a';
            tower(ctx, x, y - 20, w, h + 20, stone, roof, false);
            return;
        }
        case 'flowerbed':
            rect(ctx, '#6a4a2a', x + 2, y + 10, 28, 16);
            for (let i = 0; i < 5; i++) rect(ctx, ['#f39ac0', '#f4e36a', '#ffffff', '#b99af0'][i % 4], x + 5 + i * 5, y + 12 + (i % 2) * 5, 4, 4);
            return;
        case 'entrance':
            entrance(ctx, o, t);
            return;
        case 'teleporter': {
            const cx = (o.centerTx + 0.5) * T;
            const cy = (o.centerTy + 0.5) * T;
            // 石の台座と、光る魔法陣
            ctx.fillStyle = '#c8c4d0';
            ctx.beginPath();
            ctx.ellipse(cx, cy, 70, 44, 0, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#a8a4b4';
            ctx.beginPath();
            ctx.ellipse(cx, cy, 62, 38, 0, 0, Math.PI * 2);
            ctx.fill();
            if (o.unlocked) {
                const glow = ctx.createRadialGradient(cx, cy, 4, cx, cy, 64);
                glow.addColorStop(0, `rgba(170,240,255,${0.55 + 0.2 * Math.sin(t * 3)})`);
                glow.addColorStop(1, 'rgba(120,200,255,0)');
                ctx.fillStyle = glow;
                ctx.beginPath();
                ctx.ellipse(cx, cy, 64, 40, 0, 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.strokeStyle = o.unlocked ? `rgba(120,220,255,${0.7 + 0.3 * Math.sin(t * 3)})` : 'rgba(120,120,140,0.8)';
            ctx.lineWidth = 4;
            for (const r of [60, 44]) {
                ctx.beginPath();
                ctx.ellipse(cx, cy, r, r * 0.6, 0, 0, Math.PI * 2);
                ctx.stroke();
            }
            if (o.unlocked) {
                for (let i = 0; i < 8; i++) {
                    const a = (i / 8) * Math.PI * 2 + t;
                    ctx.fillStyle = 'rgba(200,245,255,0.9)';
                    ctx.fillRect(cx + Math.cos(a) * 52, cy + Math.sin(a) * 31 - ((t * 40 + i * 10) % 40), 3, 3);
                }
            }
            label(ctx, o.name, cx, cy - 44, o.unlocked ? '#c8f0ff' : '#b8b8c0', 14);
            return;
        }
        case 'sky_tower':
            tower(ctx, x, y - 140, w, h + 140, '#e8e4f0', '#5a8ac8', true);
            ctx.fillStyle = `rgba(160,220,255,${0.5 + 0.3 * Math.sin(t * 2)})`;
            ctx.beginPath();
            ctx.arc(x + w / 2, y - 150, 14, 0, Math.PI * 2);
            ctx.fill();
            return;
        case 'barrier': {
            const cx = (o.centerTx + 0.5) * T;
            const cy = (o.centerTy + 0.5) * T;
            if (o.unlocked) {
                ctx.strokeStyle = 'rgba(160,120,200,0.35)';
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.arc(cx, cy, 70, 0, Math.PI * 2);
                ctx.stroke();
                label(ctx, `${o.name}（破れている）`, cx, cy - 70, '#c8b8e0', 13);
                return;
            }
            const g = ctx.createRadialGradient(cx, cy, 10, cx, cy, 110);
            g.addColorStop(0, `rgba(170,60,255,${0.55 + 0.15 * Math.sin(t * 3)})`);
            g.addColorStop(1, 'rgba(90,0,160,0)');
            ctx.fillStyle = g;
            ctx.fillRect(cx - 120, cy - 120, 240, 240);
            ctx.strokeStyle = 'rgba(230,190,255,0.8)';
            ctx.lineWidth = 2;
            for (let i = 0; i < 6; i++) {
                const a = (i / 6) * Math.PI * 2 + t * 0.6;
                ctx.strokeRect(cx + Math.cos(a) * 70 - 6, cy + Math.sin(a) * 40 - 6, 12, 12);
            }
            label(ctx, o.name, cx, cy - 80, '#f0d8ff', 15);
            return;
        }
        case 'guard_gate':
            rect(ctx, '#7a5230', x, y + h / 2 - 6, w, 12);
            for (let i = 0; i < 2; i++) {
                const gx = x + (i === 0 ? 16 : w - 16);
                const gy = y + h / 2;
                rect(ctx, '#3a4a7a', gx - 7, gy - 22, 14, 20);
                ctx.fillStyle = '#e8c8a0';
                ctx.beginPath();
                ctx.arc(gx, gy - 28, 7, 0, Math.PI * 2);
                ctx.fill();
                rect(ctx, '#9aa0a8', gx - 8, gy - 38, 16, 6);
                rect(ctx, '#8a8a8a', gx + 9, gy - 46, 2, 46);
            }
            label(ctx, o.label, x + w / 2, y - 16, '#ffd8c8', 13);
            return;
        // ---- 街道沿いの寄り道 ----
        case 'well':
            ctx.fillStyle = '#8a8478';
            ctx.beginPath();
            ctx.ellipse(x + w / 2, y + h / 2 + 4, w / 2, h / 2.6, 0, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#2a4a6a';
            ctx.beginPath();
            ctx.ellipse(x + w / 2, y + h / 2 + 4, w / 2 - 6, h / 2.6 - 5, 0, 0, Math.PI * 2);
            ctx.fill();
            rect(ctx, '#6a4a2a', x + 4, y - 18, 4, h + 8);
            rect(ctx, '#6a4a2a', x + w - 8, y - 18, 4, h + 8);
            rect(ctx, '#8a3a2a', x - 2, y - 24, w + 4, 8);
            return;
        case 'lodge':
            house(ctx, x, y, w, h, '#7a3a2a', '#efe0c0', 991, { lit: true });
            signboard(ctx, '宿', x + w / 2 + 40, y + h - 30, '#5a2a1a');
            label(ctx, o.label, x + w / 2, y - 4, '#fff4c8', 13);
            return;
        case 'stable':
            house(ctx, x, y, w, h, '#8a5a2a', '#a8804a', 377);
            label(ctx, o.label, x + w / 2, y - 4, '#fff4c8', 12);
            return;
        case 'cart':
            rect(ctx, 'rgba(0,0,0,0.25)', x + 2, y + h - 4, w - 4, 6);
            rect(ctx, '#8a6034', x + 4, y + 6, w - 8, h - 12);
            ctx.fillStyle = '#e8dcc0';
            ctx.beginPath();
            ctx.ellipse(x + w / 2, y + 8, w / 2 - 4, 14, 0, Math.PI, 0);
            ctx.fill();
            ctx.fillStyle = '#3a2a1a';
            ctx.beginPath();
            ctx.arc(x + 12, y + h - 6, 7, 0, Math.PI * 2);
            ctx.arc(x + w - 12, y + h - 6, 7, 0, Math.PI * 2);
            ctx.fill();
            return;
        case 'bench':
            rect(ctx, '#7a5230', x, y + 8, w, 8);
            rect(ctx, '#5a3a1e', x + 4, y + 16, 4, 10);
            rect(ctx, '#5a3a1e', x + w - 8, y + 16, 4, 10);
            return;
        case 'haystack':
            ctx.fillStyle = '#d8b850';
            ctx.beginPath();
            ctx.ellipse(x + w / 2, y + h / 2 + 4, w / 2, h / 2, 0, Math.PI, 0);
            ctx.lineTo(x + w, y + h);
            ctx.lineTo(x, y + h);
            ctx.fill();
            rect(ctx, 'rgba(120,90,20,0.35)', x + 6, y + h / 2, w - 12, 3);
            return;
        case 'windmill': {
            shadow(ctx, x, y + h, w);
            ctx.fillStyle = '#e8dcc8';
            ctx.beginPath();
            ctx.moveTo(x + 18, y + h);
            ctx.lineTo(x + 34, y - 30);
            ctx.lineTo(x + w - 34, y - 30);
            ctx.lineTo(x + w - 18, y + h);
            ctx.closePath();
            ctx.fill();
            ctx.fillStyle = '#8a4a2a';
            ctx.beginPath();
            ctx.moveTo(x + 28, y - 26);
            ctx.lineTo(x + w / 2, y - 56);
            ctx.lineTo(x + w - 28, y - 26);
            ctx.closePath();
            ctx.fill();
            rect(ctx, '#4a2c18', x + w / 2 - 10, y + h - 30, 20, 30);
            // 回る羽根
            const hubX = x + w / 2;
            const hubY = y - 16;
            windmillSails(ctx, hubX, hubY, t);
            label(ctx, o.label, x + w / 2, y - 110, '#fff4c8', 13);
            return;
        }
        case 'watchtower':
            tower(ctx, x + 20, y - 110, w - 40, h + 110, '#8a6a4a', '#5a3a22', true);
            rect(ctx, '#6a4a2a', x + 8, y - 110, w - 16, 10);
            rect(ctx, '#c83a3a', x + w / 2 + 2, y - 150, 24, 14);
            rect(ctx, '#4a3220', x + w / 2 - 1, y - 152, 3, 44);
            label(ctx, o.label, x + w / 2, y - 156, '#fff4c8', 13);
            return;
        case 'shrine':
            shadow(ctx, x, y + h, w);
            rect(ctx, '#b8b0a0', x - 6, y + h - 14, w + 12, 14);
            rect(ctx, '#d8d0c0', x + 6, y + 10, w - 12, h - 20);
            ctx.fillStyle = '#6a3a2a';
            ctx.beginPath();
            ctx.moveTo(x - 8, y + 16);
            ctx.lineTo(x + w / 2, y - 16);
            ctx.lineTo(x + w + 8, y + 16);
            ctx.closePath();
            ctx.fill();
            rect(ctx, '#f4d67a', x + w / 2 - 6, y + 26, 12, 16);
            label(ctx, o.label, x + w / 2, y - 22, '#fff4c8', 13);
            return;
        case 'waystone': {
            const glow = 0.5 + 0.35 * Math.sin(t * 2.4 + x);
            ctx.fillStyle = `rgba(120,200,255,${glow * 0.35})`;
            ctx.beginPath();
            ctx.ellipse(x + w / 2, y + h - 4, 34, 14, 0, 0, Math.PI * 2);
            ctx.fill();
            rect(ctx, '#8a8a96', x + 6, y + h - 12, w - 12, 10);
            ctx.fillStyle = '#6a7a9a';
            ctx.beginPath();
            ctx.moveTo(x + w / 2, y - 34);
            ctx.lineTo(x + w - 12, y - 8);
            ctx.lineTo(x + w - 16, y + h - 10);
            ctx.lineTo(x + 16, y + h - 10);
            ctx.lineTo(x + 12, y - 8);
            ctx.closePath();
            ctx.fill();
            ctx.fillStyle = `rgba(170,230,255,${glow})`;
            ctx.fillRect(x + w / 2 - 3, y - 18, 6, 26);
            ctx.fillRect(x + w / 2 - 9, y - 8, 18, 5);
            label(ctx, o.discovered ? '旅の祠（記憶済み）' : '旅の祠', x + w / 2, y - 40, o.discovered ? '#a8e4ff' : '#fff4c8', 12);
            return;
        }
        case 'signpost':
            rect(ctx, '#5a3a1e', x + 14, y - 14, 5, 44);
            rect(ctx, '#9a6a3a', x - 8, y - 18, 30, 11);
            rect(ctx, '#9a6a3a', x + 10, y - 4, 30, 11);
            ctx.fillStyle = '#9a6a3a';
            ctx.beginPath();
            ctx.moveTo(x - 8, y - 18);
            ctx.lineTo(x - 16, y - 12.5);
            ctx.lineTo(x - 8, y - 7);
            ctx.fill();
            ctx.beginPath();
            ctx.moveTo(x + 40, y - 4);
            ctx.lineTo(x + 48, y + 1.5);
            ctx.lineTo(x + 40, y + 7);
            ctx.fill();
            return;
        default:
            rect(ctx, '#ff00ff', x, y, w, h);
    }
}

// ---- 冒険者 ------------------------------------------------------------------------------

const HAIR = ['#3a2a1a', '#8a5a2a', '#e8c860', '#c8c8d0', '#6a2a2a', '#2a3a6a', '#e89ab0', '#f4f0e8'];
const CLOTH = ['#3a6ac8', '#c84a3a', '#4a9a5a', '#8a4ac8', '#d8a040', '#3a8a9a', '#6a6a78', '#a05a3a'];

// 戻り値はシートを描いたかどうか。住人は役割専用キーを明示して使う。
const playerHorizontalFacing = new WeakMap();

export function drawAdventurer(ctx, p, t, isSelf, images, spriteKey) {
    const x = Math.round(p.x);
    const y = Math.round(p.y);
    const seed = p.id ?? 1;
    const hair = HAIR[seed % HAIR.length];
    const cloth = CLOTH[(seed >> 3) % CLOTH.length];
    const walking = p.moving;
    const step = walking ? Math.sin(t * 14) : 0;
    ctx.fillStyle = 'rgba(0,0,0,0.3)';
    ctx.beginPath();
    ctx.ellipse(x, y + 2, 11, 5, 0, 0, Math.PI * 2);
    ctx.fill();
    // 選択アイコンをそのまま使う。上・下へ歩く間も直前の左右方向を保つ。
    if (!spriteKey && p.icon) {
        if (p.facing === 1 || p.facing === 2) playerHorizontalFacing.set(p, p.facing);
        const portrait = images?.get(`player-icon:${p.icon}`);
        if (portrait?.width > 0 && portrait?.height > 0) {
            const size = Math.min(48 / portrait.width, 56 / portrait.height);
            const width = portrait.width * size, height = portrait.height * size;
            const bob = walking ? Math.abs(Math.sin(t * 10)) * 2 : 0;
            ctx.save();
            ctx.translate(x, y - bob);
            if (playerHorizontalFacing.get(p) === 2) ctx.scale(-1, 1);
            ctx.drawImage(portrait, -width / 2, -height, width, height);
            ctx.restore();
            label(ctx, p.name, x, y - height - 5, isSelf ? '#fff4a0' : '#ffffff', 12);
            return true;
        }
    }
    // 64pxセル、行は南・西・東・北、列は踏み出し・停止・逆足。
    // 外見は従来の色分けと同様にIDから固定。職業・性別データは変更しない。
    const variant = Math.abs(Math.trunc(Number(seed) || 0)) % 2;
    const image = images?.get(spriteKey ?? `adventurer_base_${variant}`);
    if (image?.width === 192 && image?.height === 256) {
        const facing = Number.isInteger(p.facing) && p.facing >= 0 && p.facing < 4 ? p.facing : 0;
        const phase = Math.floor(Math.max(0, Number(t) || 0) * 9) % 4;
        const frame = walking ? [0, 1, 2, 1][phase] : 1;
        ctx.save();
        ctx.imageSmoothingEnabled = false;
        // 素材の接地位置(32,62)を既存のプレイヤー座標へ合わせる。
        ctx.drawImage(image, frame * 64, facing * 64, 64, 64, x - 24, y - 46.5, 48, 48);
        ctx.restore();
        label(ctx, p.name, x, y - 49, isSelf ? '#fff4a0' : '#ffffff', 12);
        return true;
    }
    // 足
    rect(ctx, '#3a2a20', x - 6, y - 8 + Math.max(0, step) * 3, 5, 9);
    rect(ctx, '#3a2a20', x + 1, y - 8 + Math.max(0, -step) * 3, 5, 9);
    // 体
    rect(ctx, cloth, x - 8, y - 24, 16, 17);
    rect(ctx, 'rgba(0,0,0,0.18)', x + 3, y - 24, 5, 17);
    rect(ctx, '#6a4a2a', x - 8, y - 12, 16, 3);
    // 腕
    rect(ctx, cloth, x - 11, y - 22 - step * 2, 4, 11);
    rect(ctx, cloth, x + 7, y - 22 + step * 2, 4, 11);
    // 頭
    ctx.fillStyle = '#f0d0b0';
    ctx.beginPath();
    ctx.arc(x, y - 32, 9, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = hair;
    const f = p.facing ?? 0;
    ctx.beginPath();
    if (f === 3) ctx.arc(x, y - 32, 9.5, 0, Math.PI * 2);
    else ctx.arc(x, y - 35, 9.5, Math.PI, 0);
    ctx.fill();
    if (f !== 3) {
        rect(ctx, '#2a1a10', x - 4 + (f === 1 ? -2 : f === 2 ? 2 : 0), y - 33, 2, 3);
        rect(ctx, '#2a1a10', x + 2 + (f === 1 ? -2 : f === 2 ? 2 : 0), y - 33, 2, 3);
    }
    label(ctx, p.name, x, y - 44, isSelf ? '#fff4a0' : '#ffffff', 12);
    return false;
}

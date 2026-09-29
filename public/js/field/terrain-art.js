// 4×4のイラスト原画。並び順は output/imagegen/field/terrain/ の原画と一致させる。
export const TERRAIN_ATLAS_GROUPS = Object.freeze({
    plains: ['G', 'g', 'L', 'F', 'T', 'U', 'H', 'R', 'M', 'q', 't', 'm', 'S', 'K', 'Z', 'A'],
    climate: ['O', 'W', 'D', 'd', 'P', 'C', 'N', 'n', 'I', 'J', 'V', 'v', 'X', 'Y', 'z', 'l'],
    settlement: ['Q', 'r', 's', 'b', 'c', 'w', 'x', 'i', 'f', 'h', 'p', 'o', 'e', 'j', 'u', 'k'],
});

const CELL = Object.fromEntries(Object.entries(TERRAIN_ATLAS_GROUPS).flatMap(([group, tiles]) =>
    tiles.map((tile, index) => [tile, { group, index }])));
const EDGE_INSET = 24; // 生成原画のセル境界に混ざった隣の色を除く
const OVERLAY_SIZE = 128;

function cellRect(image, tile) {
    const cell = CELL[tile];
    if (!cell || !image?.width || !image?.height) return null;
    const cellW = image.width / 4;
    const cellH = image.height / 4;
    return {
        sx: (cell.index % 4) * cellW + EDGE_INSET,
        sy: Math.floor(cell.index / 4) * cellH + EDGE_INSET,
        sw: cellW - EDGE_INSET * 2,
        sh: cellH - EDGE_INSET * 2,
    };
}

// 草原込みで生成された岩セルから、岩だけを残すための透明度。
// 色相で草を除き、セル外周も薄くして別バイオームへ自然に重ねられるようにする。
export function rockOverlayAlpha(r, g, b, alpha, nx, ny) {
    if (alpha <= 0) return 0;
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const chroma = max - min;
    let hue = 0;
    if (chroma > 0) {
        if (max === r) hue = 60 * (((g - b) / chroma) % 6);
        else if (max === g) hue = 60 * ((b - r) / chroma + 2);
        else hue = 60 * ((r - g) / chroma + 4);
        if (hue < 0) hue += 360;
    }
    const saturation = max === 0 ? 0 : chroma / max;
    const green = hue >= 42 && hue <= 175;
    const chromaStrength = Math.max(0, Math.min(1, (chroma - 8) / 34));
    const saturationStrength = Math.max(0, Math.min(1, (saturation - 0.08) / 0.25));
    const grassRemoval = green ? chromaStrength * saturationStrength : 0;

    const dx = (nx - 0.52) / 0.5;
    const dy = (ny - 0.56) / 0.48;
    const radius = Math.hypot(dx, dy);
    const edge = Math.max(0, Math.min(1, (1 - radius) / 0.12));
    return Math.round(alpha * (1 - grassRemoval) * edge);
}

// 草地込みで生成された木セルから、中央の樹冠・幹・根だけを残すための透明度。
// 葉と草は同系色なので色だけでは分離せず、木の形を保護したうえで外周を滑らかに落とす。
export function treeOverlayAlpha(r, g, b, alpha, nx, ny) {
    if (alpha <= 0) return 0;
    const clamp = (value) => Math.max(0, Math.min(1, value));
    const ellipse = (cx, cy, rx, ry) => clamp((1.04 - Math.hypot((nx - cx) / rx, (ny - cy) / ry)) / 0.12);
    const canopyShape = Math.max(
        ellipse(0.5, 0.36, 0.34, 0.27),
        ellipse(0.27, 0.39, 0.2, 0.21),
        ellipse(0.73, 0.39, 0.2, 0.21),
        ellipse(0.38, 0.2, 0.21, 0.17),
        ellipse(0.62, 0.2, 0.21, 0.17),
        ellipse(0.38, 0.52, 0.24, 0.16),
        ellipse(0.62, 0.52, 0.24, 0.16),
    );

    const trunkProgress = clamp((ny - 0.48) / 0.44);
    const trunkHalfWidth = 0.065 + trunkProgress * 0.145;
    const trunkSide = clamp((trunkHalfWidth - Math.abs(nx - 0.5)) / 0.035);
    const trunkTop = clamp((ny - 0.4) / 0.08);
    const trunkBottom = clamp((0.96 - ny) / 0.06);
    const trunkShape = trunkSide * trunkTop * trunkBottom;

    const brownDetail = r > g * 0.92 && r > b * 1.28 ? 1 : 0;
    const shape = Math.max(canopyShape, trunkShape * brownDetail);
    return Math.round(alpha * shape);
}

export function createTerrainOverlays(group, image, makeCanvas = () => document.createElement('canvas')) {
    const overlays = new Map();
    if (group !== 'plains') return overlays;
    try {
        for (const [tile, alphaForPixel] of [['R', rockOverlayAlpha], ['T', treeOverlayAlpha]]) {
            const rect = cellRect(image, tile);
            if (!rect) continue;
            const canvas = makeCanvas();
            canvas.width = OVERLAY_SIZE;
            canvas.height = OVERLAY_SIZE;
            const ctx = canvas.getContext('2d', { willReadFrequently: true });
            ctx.imageSmoothingEnabled = true;
            ctx.drawImage(image, rect.sx, rect.sy, rect.sw, rect.sh, 0, 0, OVERLAY_SIZE, OVERLAY_SIZE);
            const pixels = ctx.getImageData(0, 0, OVERLAY_SIZE, OVERLAY_SIZE);
            for (let y = 0; y < OVERLAY_SIZE; y++) {
                for (let x = 0; x < OVERLAY_SIZE; x++) {
                    const i = (y * OVERLAY_SIZE + x) * 4;
                    pixels.data[i + 3] = alphaForPixel(
                        pixels.data[i], pixels.data[i + 1], pixels.data[i + 2], pixels.data[i + 3],
                        (x + 0.5) / OVERLAY_SIZE, (y + 0.5) / OVERLAY_SIZE,
                    );
                }
            }
            ctx.putImageData(pixels, 0, 0);
            overlays.set(tile, canvas);
        }
    } catch {
        // 読み出せない画像環境では、tiles-draw.js の手描き岩・木へ安全にフォールバックする。
    }
    return overlays;
}

export function drawTerrainArt(ctx, tile, px, py, atlases, rotate = false) {
    // 池や噴水の下地は広い面積を埋める。丸い池・噴水の絵を各マスに繰り返さない。
    const cell = CELL[tile === 'l' || tile === 'u' ? 'W' : tile];
    const image = cell && atlases?.get(cell.group);
    if (!image?.width || !image?.height) return false;
    const cellW = image.width / 4;
    const cellH = image.height / 4;
    const sw = cellW - EDGE_INSET * 2;
    const sh = cellH - EDGE_INSET * 2;
    const sx = (cell.index % 4) * cellW + EDGE_INSET;
    const sy = Math.floor(cell.index / 4) * cellH + EDGE_INSET;
    if (rotate) {
        ctx.save();
        ctx.translate(px + 32, py);
        ctx.rotate(Math.PI / 2);
        ctx.drawImage(image, sx, sy, sw, sh, 0, 0, 32, 32);
        ctx.restore();
    } else {
        ctx.drawImage(image, sx, sy, sw, sh, px, py, 32, 32);
    }
    return true;
}

export function drawTerrainOverlay(ctx, tile, px, py, atlases) {
    const image = atlases?.get(`overlay:${tile}`);
    if (!image?.width || !image?.height) return false;
    ctx.drawImage(image, px, py, 32, 32);
    return true;
}

export function drawCliffArt(ctx, px, py, depth, image, index) {
    if (!image?.width || !image?.height) return false;
    const cellW = image.width / 4;
    const cellH = image.height / 4;
    const sw = cellW - EDGE_INSET * 2;
    const sh = cellH - EDGE_INSET * 2;
    const sx = (index % 4) * cellW + EDGE_INSET;
    const sy = Math.floor(index / 4) * cellH + EDGE_INSET;
    const crop = sh * depth / 32;
    ctx.drawImage(image, sx, sy + sh - crop, sw, crop, px, py + 32 - depth, 32, depth);
    return true;
}

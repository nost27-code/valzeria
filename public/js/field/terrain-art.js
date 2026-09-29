// 4×4のイラスト原画。並び順は output/imagegen/field/terrain/ の原画と一致させる。
export const TERRAIN_ATLAS_GROUPS = Object.freeze({
    plains: ['G', 'g', 'L', 'F', 'T', 'U', 'H', 'R', 'M', 'q', 't', 'm', 'S', 'K', 'Z', 'A'],
    climate: ['O', 'W', 'D', 'd', 'P', 'C', 'N', 'n', 'I', 'J', 'V', 'v', 'X', 'Y', 'z', 'l'],
    settlement: ['Q', 'r', 's', 'b', 'c', 'w', 'x', 'i', 'f', 'h', 'p', 'o', 'e', 'j', 'u', 'k'],
});

const CELL = Object.fromEntries(Object.entries(TERRAIN_ATLAS_GROUPS).flatMap(([group, tiles]) =>
    tiles.map((tile, index) => [tile, { group, index }])));
const EDGE_INSET = 24; // 生成原画のセル境界に混ざった隣の色を除く

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

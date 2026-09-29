// ヴァルゼリア大陸フィールドの共通定数。
// 地形帯 BIOME は scripts/field/build_valzeria_macro.py の BIOME_* と一致させること。

export const TILE = 32;
export const BLOCK = 16; // 描画キャッシュの単位（16x16 マス）

export const BIOME = {
    OCEAN: 0,
    GRASS: 1,
    FOREST: 2,
    DEEP_FOREST: 3,
    HILLS: 4,
    MOUNTAIN: 5,
    SNOW: 6,
    SNOW_MOUNTAIN: 7,
    VOLCANO: 8,
    DESERT: 9,
    BLIGHT: 10,
    SAVANNA: 11,
};

// マスの種類（1文字）。solid は通れない。road は街道扱い（段差を階段でつなぐ）
export const TILES = {
    // 自然
    O: { name: '外洋', solid: true, color: '#2b5a9e' },
    W: { name: '浅瀬', solid: true, color: '#3f7fc4' },
    A: { name: '砂浜', color: '#e3cf92' },
    G: { name: '草原', color: '#78b04a' },
    g: { name: '背の高い草', color: '#6aa442' },
    L: { name: '花畑', color: '#86b852' },
    F: { name: '森の下草', color: '#4f8a3a' },
    T: { name: '木', solid: true, color: '#2f6a2a' },
    U: { name: '深い森の木', solid: true, color: '#23552a' },
    H: { name: '丘', color: '#a4a06a' },
    R: { name: '岩', solid: true, color: '#7d7466' },
    M: { name: '岩山', solid: true, color: '#6b6258' },
    N: { name: '雪原', color: '#eef2f7' },
    n: { name: '雪の草むら', color: '#dfe7ee' },
    I: { name: '雪の針葉樹', solid: true, color: '#3d6a5a' },
    J: { name: '氷の岩', solid: true, color: '#b7c7d8' },
    V: { name: '火山灰', color: '#5d4a44' },
    v: { name: '火山礫', color: '#544038' },
    X: { name: '溶岩', solid: true, color: '#e0582a' },
    Y: { name: '黒曜岩', solid: true, color: '#2c2230' },
    D: { name: '砂漠', color: '#e2c483' },
    d: { name: '砂丘', color: '#d7b671' },
    P: { name: 'ヤシの木', solid: true, color: '#4c8a3a' },
    C: { name: 'サボテン', solid: true, color: '#5b9a4a' },
    S: { name: '枯れ草原', color: '#b9ae6a' },
    K: { name: '瘴気の地', color: '#5a4a64' },
    Z: { name: '枯れ木', solid: true, color: '#2e2434' },
    z: { name: '雲', solid: true, color: '#e9f2fb' },
    Q: { name: '古い石柱', solid: true, color: '#c9c3b4' },
    // 人の手
    r: { name: '街道', road: true, color: '#c7a56b' },
    s: { name: '石畳の街道', road: true, color: '#b7ae9c' },
    b: { name: '橋', road: true, color: '#a57a45' },
    c: { name: '溶岩の上の石道', road: true, color: '#4a3a3a' },
    '=': { name: '階段', road: true, stairs: true, color: '#c9bfa8' },
    w: { name: '城壁', solid: true, color: '#8c8374' },
    x: { name: '城の壁', solid: true, color: '#9a9488' },
    i: { name: '鍛冶街の鉄柵', solid: true, color: '#4d4b4a' },
    f: { name: '木の柵', solid: true, color: '#8a6034' },
    h: { name: '生け垣', solid: true, color: '#2f7a3a' },
    p: { name: '広場', color: '#d4cbb6' },
    o: { name: '町の地面', color: '#c9b48a' },
    q: { name: '芝生', color: '#86c05a' },
    e: { name: '大理石', color: '#eceae4' },
    j: { name: '黒い石畳', color: '#3e3440' },
    u: { name: '噴水', solid: true, color: '#63a7df' },
    l: { name: '池', solid: true, color: '#4d93cf' },
    k: { name: '桟橋', color: '#9c7446' },
    t: { name: '街路樹', solid: true, color: '#3c7a34' },
    m: { name: '畑', color: '#8a6a3a' },
};

export const WALKABLE_OVER_WATER = new Set(['b', 'k']);

export function isSolidTile(id) {
    const t = TILES[id];
    return !t || !!t.solid;
}

export function isRoadTile(id) {
    return !!TILES[id]?.road;
}

export function isStairsTile(id) {
    return id === '=';
}

export function isWaterTile(id) {
    return id === 'O' || id === 'W' || id === 'l' || id === 'u';
}

// 方向（保存用の番号）
export const FACING = { down: 0, left: 1, right: 2, up: 3 };

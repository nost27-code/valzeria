// 画像素材のキー（public/images/field/{key}.webp が置かれたらそれを描く）。先にある方を使う
export function assetKeysFor(o) {
    if (o.type === 'facility') return [`facility_${o.facility}_${o.style}`, `facility_${o.facility}`];
    if (o.type === 'house') return [`house_${o.style}_${o.variant % 4}`, `house_${o.style}`, `house_${o.variant % 4}`];
    if (o.type === 'stall' || o.type === 'tent') {
        const variant = (o.variant ?? 0) % 4;
        return [...(o.style ? [`${o.type}_${o.style}_${variant}`, `${o.type}_${o.style}`] : []), `${o.type}_${variant}`, o.type];
    }
    if (o.type === 'guard_gate') return [`guard_gate_${o.dir === 'e' || o.dir === 'w' ? 'ew' : 'ns'}`, 'guard_gate'];
    if (o.type === 'entrance') return [`entrance_${o.kind}`];
    // 状態の違う画像へは代替しない。欠落時は従来の状態別描画を使う。
    if (o.type === 'teleporter') return [o.unlocked ? 'teleporter' : 'teleporter_inactive'];
    if (o.type === 'barrier') return [o.unlocked ? 'barrier_broken' : 'barrier'];
    if (o.style) return [`${o.type}_${o.style}`, o.type];
    return [o.type];
}

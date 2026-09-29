// 描画・配置の範囲と、地面を占める接地部分を分ける。
// 門・城壁・結界・探索地入口は対象外（通行制限を維持する）。
import { BUILDING_CONTOURS } from './building-contours.js';
import { assetKeysFor } from './asset-keys.js';
const BUILDINGS = new Set([
    'house', 'facility', 'castle', 'demon_castle', 'cathedral', 'sky_temple',
    'academy_hall', 'magic_tower', 'lighthouse', 'furnace', 'mausoleum',
    'lodge', 'stable', 'windmill', 'watchtower', 'shrine', 'sky_tower',
    'world_tree',
]);

export function objectFootprint(o) {
    // 下端（扉・描画の基準）は固定し、屋根側60%を背後の通路にする。
    const depth = BUILDINGS.has(o.type) ? Math.max(1, Math.ceil(o.th * 0.4)) : o.th;
    return { tx: o.tx, ty: o.ty + o.th - depth, tw: o.tw, th: depth };
}

export function isBuilding(o) { return BUILDINGS.has(o.type); }

export function inDoorApproach(o, tx, ty) {
    if (o.type !== 'castle') return false;
    const halfWidth = Math.max(1, Math.floor(o.tw * 0.045));
    const center = o.tx + Math.floor(o.tw / 2);
    const steps = Math.max(1, Math.round(o.th * 0.1));
    return tx >= center - halfWidth && tx < center + halfWidth
        && ty >= o.ty + o.th - steps && ty < o.ty + o.th;
}

export function objectBlocksTile(o, tx, ty) {
    return objectBlocksRect(o, tx, ty, tx + 1, ty + 1);
}

// 接地輪郭の手前にある透明部分は、移動と前後表示の両方で共通に扱う。
export function inBuildingApproach(o, tx, ty) {
    const r=objectFootprint(o);
    return isBuilding(o) && tx>=r.tx && tx<r.tx+r.tw && ty>=r.ty && ty<r.ty+r.th
        && !objectBlocksRect(o,tx,ty,tx+0.00001,ty+0.00001);
}

export function objectBlocksRect(o, left, top, right, bottom) {
    const r=objectFootprint(o);
    if(right<=r.tx || left>=r.tx+r.tw || bottom<=r.ty || top>=r.ty+r.th)return false;
    if(!isBuilding(o))return true;
    const profile=isBuilding(o) && assetKeysFor(o).map(k=>BUILDING_CONTOURS[k]).find(Boolean);
    const lo=Math.max(left,r.tx), hi=Math.min(right,r.tx+r.tw);
    const count=profile ? profile.bottom.length : 256;
    const first=Math.max(0,Math.floor((lo-o.tx)/o.tw*count));
    const last=Math.min(count-1,Math.ceil((hi-o.tx)/o.tw*count)-1);
    for(let i=first;i<=last;i++){
        let edge=o.ty+o.th;
        if(profile){
            if(profile.bottom[i]===0)continue;
            edge -= (profile.height-profile.bottom[i])*o.tw/profile.width;
        }
        const x=o.tx+(i+.5)*o.tw/count;
        if(inDoorApproach(o,x,o.ty+o.th-.00001))edge=Math.min(edge,o.ty+o.th-Math.max(1,Math.round(o.th*.1)));
        if(top<edge && bottom>r.ty)return true;
    }
    return false;
}

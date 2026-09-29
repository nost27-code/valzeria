export const MAP_MIN_ZOOM = 1;
export const MAP_MAX_ZOOM = 5;

// 画像の中心を基準に拡大し、余白だけが見える位置へは動かさない。
export function mapFrame(camera, viewport, image) {
    const zoom = Math.max(MAP_MIN_ZOOM, Math.min(MAP_MAX_ZOOM, camera.zoom || 1));
    const fit = Math.min(viewport.width / image.width, viewport.height / image.height) * zoom;
    const maxX = Math.max(0, (image.width * fit - viewport.width) / 2);
    const maxY = Math.max(0, (image.height * fit - viewport.height) / 2);
    const panX = Math.max(-maxX, Math.min(maxX, camera.panX || 0));
    const panY = Math.max(-maxY, Math.min(maxY, camera.panY || 0));
    return {
        zoom, panX, panY, fit,
        ox: (viewport.width - image.width * fit) / 2 + panX,
        oy: (viewport.height - image.height * fit) / 2 + panY,
    };
}

export function zoomMapCamera(camera, factor, anchor, viewport, image) {
    const before = mapFrame(camera, viewport, image);
    const zoom = Math.max(MAP_MIN_ZOOM, Math.min(MAP_MAX_ZOOM, before.zoom * factor));
    const ratio = zoom / before.zoom;
    const next = {
        zoom,
        panX: before.panX + (anchor.x - viewport.width / 2 - before.panX) * (1 - ratio),
        panY: before.panY + (anchor.y - viewport.height / 2 - before.panY) * (1 - ratio),
    };
    const { panX, panY } = mapFrame(next, viewport, image);
    return { zoom, panX, panY };
}

export function panMapCamera(camera, dx, dy, viewport, image) {
    const before = mapFrame(camera, viewport, image);
    const { zoom, panX, panY } = mapFrame({ ...before, panX: before.panX + dx, panY: before.panY + dy }, viewport, image);
    return { zoom, panX, panY };
}

export function pinchMapCamera(camera, before, after, viewport, image) {
    const [a, b] = before;
    const [c, d] = after;
    const oldDistance = Math.hypot(a.x - b.x, a.y - b.y);
    const newDistance = Math.hypot(c.x - d.x, c.y - d.y);
    const oldMid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
    const newMid = { x: (c.x + d.x) / 2, y: (c.y + d.y) / 2 };
    const zoomed = oldDistance > 0 ? zoomMapCamera(camera, newDistance / oldDistance, oldMid, viewport, image) : camera;
    return panMapCamera(zoomed, newMid.x - oldMid.x, newMid.y - oldMid.y, viewport, image);
}

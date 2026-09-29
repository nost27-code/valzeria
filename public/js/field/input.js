// 入力：キーボード（矢印・WASD・Shift で走る・Space/Enter で調べる・M で地図）と、フィールドのタップ移動・A/B ボタン。

const KEYS = {
    ArrowUp: 'up', KeyW: 'up', ArrowDown: 'down', KeyS: 'down',
    ArrowLeft: 'left', KeyA: 'left', ArrowRight: 'right', KeyD: 'right',
};
const ACTIONS = { Space: 'interact', Enter: 'interact', KeyE: 'interact', KeyM: 'map', Tab: 'map', Escape: 'close' };

export function fieldPointFromClient(clientX, clientY, rect, camera, zoom) {
    if (!Number.isFinite(zoom) || zoom <= 0 || rect.width <= 0 || rect.height <= 0) return null;
    return {
        x: camera.x + (clientX - rect.left) / zoom,
        y: camera.y + (clientY - rect.top) / zoom,
    };
}

export function moveVectorToTarget(current, target, stopDistance = 4) {
    const dx = target.x - current.x;
    const dy = target.y - current.y;
    const distance = Math.hypot(dx, dy);
    if (!Number.isFinite(distance) || distance <= stopDistance) return { x: 0, y: 0, distance, arrived: true };
    return { x: dx / distance, y: dy / distance, distance, arrived: false };
}

export function movementSpeedMultiplier(movement, spectator, dashing) {
    const spectatorMultiplier = Number(movement.spectator_multiplier ?? 4);
    const dashMultiplier = Number(movement.dash_multiplier ?? 1);
    const base = spectator && Number.isFinite(spectatorMultiplier) && spectatorMultiplier > 0
        ? spectatorMultiplier
        : 1;
    const dash = dashing && Number.isFinite(dashMultiplier) && dashMultiplier > 0
        ? dashMultiplier
        : 1;
    return base * dash;
}

export class FieldInput {
    constructor() {
        this.dirs = { up: false, down: false, left: false, right: false };
        this.dash = false;
        this.dashLocked = false;
        this.onAction = () => {};
        window.addEventListener('keydown', (e) => this.key(e, true));
        window.addEventListener('keyup', (e) => this.key(e, false));
        window.addEventListener('blur', () => this.reset());
        document.addEventListener('visibilitychange', () => this.reset());
    }

    key(e, pressed) {
        if (pressed && e.target instanceof HTMLElement && e.target.closest('input, textarea, select, dialog[open] button')) return;
        if (e.code === 'ShiftLeft' || e.code === 'ShiftRight') {
            this.dash = pressed;
            return;
        }
        if (pressed && !e.repeat && ACTIONS[e.code]) {
            this.onAction(ACTIONS[e.code]);
            e.preventDefault();
            return;
        }
        const dir = KEYS[e.code];
        if (!dir) return;
        this.dirs[dir] = pressed;
        e.preventDefault();
    }

    reset() {
        for (const k in this.dirs) this.dirs[k] = false;
        this.dash = false;
        this.dashHeld = false;
    }

    get dashing() {
        return this.dash || this.dashLocked || this.dashHeld;
    }

    vector() {
        let x = (this.dirs.right ? 1 : 0) - (this.dirs.left ? 1 : 0);
        let y = (this.dirs.down ? 1 : 0) - (this.dirs.up ? 1 : 0);
        const len = Math.hypot(x, y);
        if (len > 1) {
            x /= len;
            y /= len;
        }
        return { x, y };
    }

    // 押して離すまでに指がほぼ動かなければ、フィールド上の移動先として扱う。
    attachTapMove(surface, onTap) {
        let pointer = null;
        let origin = null;
        let moved = false;
        const move = (e) => {
            if (e.pointerId !== pointer) return;
            if (Math.hypot(e.clientX - origin.x, e.clientY - origin.y) > 10) moved = true;
        };
        const end = (e) => {
            if (e.pointerId !== pointer) return;
            const tapped = !moved;
            pointer = null;
            origin = null;
            if (tapped) onTap(e.clientX, e.clientY);
        };
        surface.addEventListener('pointerdown', (e) => {
            if (pointer !== null || e.button !== 0) return;
            e.preventDefault();
            pointer = e.pointerId;
            origin = { x: e.clientX, y: e.clientY };
            moved = false;
            try {
                surface.setPointerCapture(e.pointerId);
            } catch {
                // 取得できない端末でも、フィールド上で指を離せば操作できる
            }
        });
        surface.addEventListener('pointermove', move);
        surface.addEventListener('pointerup', end);
        surface.addEventListener('pointercancel', () => { pointer = null; origin = null; });
        surface.addEventListener('lostpointercapture', () => { pointer = null; origin = null; });
        surface.addEventListener('contextmenu', (e) => e.preventDefault());
    }

    // 押している間だけ走る（B ボタン）
    attachHoldDash(button) {
        const on = (e) => {
            e.preventDefault();
            this.dashHeld = true;
            button.classList.add('is-on');
        };
        const off = () => {
            this.dashHeld = false;
            button.classList.remove('is-on');
        };
        button.addEventListener('pointerdown', on);
        button.addEventListener('pointerup', off);
        button.addEventListener('pointercancel', off);
        button.addEventListener('pointerleave', off);
        button.addEventListener('contextmenu', (e) => e.preventDefault());
    }
}

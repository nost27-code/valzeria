// 周囲の冒険者の位置交換。静止中も取得し、応答待ちの間は次の要求を重ねない。
export class FieldPresence {
    // fetcher の既定は window に結び付けて呼ぶ（fetch をオブジェクトのメソッドとして呼ぶと Illegal invocation になる）
    constructor({ url, csrf, interval = 1, staleAfter = 10, fetcher = (...args) => globalThis.fetch(...args), clock = () => performance.now() / 1000 }) {
        this.url = url;
        this.csrf = csrf;
        this.interval = Math.max(1, interval);
        this.staleAfter = staleAfter;
        this.fetcher = fetcher;
        this.clock = clock;
        this.players = new Map();
        this.nextAt = 0;
        this.lastReceivedAt = -Infinity;
        this.pending = null;
        this.generation = 0;
        this.failures = 0;
    }

    poll(position) {
        if (this.pending || this.clock() < this.nextAt) return Promise.resolve(null);
        this.nextAt = this.clock() + this.interval;
        const generation = this.generation;
        this.pending = this.request(position, generation).finally(() => { this.pending = null; });
        return this.pending;
    }

    async request(position, generation) {
        const abort = new AbortController();
        const timeout = setTimeout(() => abort.abort(), 8000);
        try {
            const res = await this.fetcher(this.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                body: JSON.stringify(position),
                credentials: 'same-origin',
                signal: abort.signal,
            });
            if (!res.ok) {
                const retryAfter = Number(res.headers?.get('Retry-After'));
                if (res.status === 429 && Number.isFinite(retryAfter) && retryAfter > 0) {
                    this.nextAt = this.clock() + retryAfter;
                }
                throw new Error('field sync failed');
            }
            const data = await res.json();
            if (generation !== this.generation) return null;
            if (!Array.isArray(data.players)) throw new Error('invalid field sync');
            this.failures = 0;
            this.lastReceivedAt = this.clock();
            this.accept(data.players);
            return data;
        } catch {
            if (generation === this.generation) {
                this.failures++;
                this.nextAt = Math.max(this.nextAt, this.clock() + Math.min(8, 2 ** (this.failures - 1)));
            }
            return null;
        } finally {
            clearTimeout(timeout);
        }
    }

    accept(players) {
        const seen = new Set();
        for (const p of players) {
            if (!Number.isFinite(p.x) || !Number.isFinite(p.y)) continue;
            seen.add(p.id);
            const current = this.players.get(p.id);
            // 転移などの大移動では、間の地形を横切るアニメーションをしない。
            if (!current || Math.hypot(p.x - current.x, p.y - current.y) > 32 * 24) {
                this.players.set(p.id, { ...p, fromX: p.x, fromY: p.y, tx: p.x, ty: p.y, elapsed: 0.2, moving: false });
            } else {
                Object.assign(current, {
                    name: p.name, facing: p.facing, level: p.level, icon: p.icon, plane: p.plane,
                    fromX: current.x, fromY: current.y, tx: p.x, ty: p.y, elapsed: 0,
                });
            }
        }
        for (const id of this.players.keys()) if (!seen.has(id)) this.players.delete(id);
    }

    update(dt) {
        if (this.clock() - this.lastReceivedAt > this.staleAfter) this.players.clear();
        for (const p of this.players.values()) {
            p.elapsed = Math.min(0.2, p.elapsed + dt);
            const progress = p.elapsed / 0.2;
            p.x = p.fromX + (p.tx - p.fromX) * progress;
            p.y = p.fromY + (p.ty - p.fromY) * progress;
            p.moving = progress < 1 && Math.hypot(p.tx - p.fromX, p.ty - p.fromY) > 2;
        }
    }

    reset() {
        // 転移前・タブ復帰前に発行した応答は、新しい場所の表示へ混ぜない。
        this.generation++;
        this.players.clear();
        this.nextAt = 0;
        this.failures = 0;
    }
}

// フィールドの近くの人とのチャット。
// 発言は位置の同期（/field/sync）で受け取り、左下のログと、話した人の頭上の吹き出しに出す。
// 宛先：「周り」（話した場所の近く）/「街の中」（同じ街の中にいる人全員。街の外には届かない）

const $ = (id) => document.getElementById(id);
const BUBBLE_SECONDS = 7;
const LOG_LINES = 30;

export class FieldChat {
    constructor(game) {
        this.game = game;
        this.lastId = 0;
        this.seen = new Set();
        this.bubbles = new Map(); // character_id → { text, until }
        this.open = false;
        this.sending = false;
        this.zone = null;      // いまいる街（サーバーの同期で届く）{ key, name }
        this.scope = 'area';   // 'area' 周り / 'town' 街の中
        this.scopeChosen = null; // 街ごとに、自分で選んだ宛先を覚えておく

        // 管理者観察モードは閲覧専用。チャットUIもショートカットも結び付けない。
        if (game.spectator) return;

        $('chat-scope').addEventListener('click', () => {
            if (!this.zone) return;
            this.scope = this.scope === 'town' ? 'area' : 'town';
            this.scopeChosen = { key: this.zone.key, scope: this.scope };
            this.renderScope();
            $('chat-input').focus();
        });

        $('btn-chat').addEventListener('click', () => this.toggle(!this.open));
        $('chat-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.send();
        });
        $('chat-input').addEventListener('keydown', (e) => {
            if (e.key === 'Escape') this.toggle(false);
            e.stopPropagation();
        });
        // T キーで話す（入力中は無視）
        window.addEventListener('keydown', (e) => {
            if (e.target instanceof HTMLElement && e.target.closest('input, textarea')) return;
            if (e.code === 'KeyT' && !this.game.battle?.active) {
                e.preventDefault();
                this.toggle(true);
            }
        });
    }

    toggle(open) {
        if (this.game.spectator) return;
        this.open = open;
        $('chat-bar').hidden = !open;
        $('btn-chat').classList.toggle('is-on', open);
        if (open) {
            this.game.input.reset();
            $('chat-input').focus();
            this.game.presence.nextAt = 0; // すぐに新しい発言を取りに行く
        } else {
            $('chat-input').blur();
        }
    }

    async send() {
        if (this.game.spectator) return;
        const body = $('chat-input').value.trim();
        if (!body || this.sending) return;
        this.sending = true;
        try {
            const res = await fetch(this.game.urls.chat, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.game.csrf },
                body: JSON.stringify({ ...this.game.positionPayload(), body, scope: this.zone ? this.scope : 'area' }),
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                this.game.toast(data.message ?? (res.status === 429 ? '少し間をあけてから話そう。' : '送れませんでした。'));
                return;
            }
            $('chat-input').value = '';
            this.receive([data.said]);
            // スマホは送ったら閉じて視界をあける
            if (document.body.classList.contains('is-touch')) this.toggle(false);
        } finally {
            this.sending = false;
        }
    }

    // 同期で届いた、いまいる街。街に入ったら宛先は「街の中」、出たら「周り」
    setZone(zone) {
        const key = zone?.key ?? null;
        if (key === (this.zone?.key ?? null)) return;
        this.zone = zone ?? null;
        this.scope = !zone ? 'area' : this.scopeChosen?.key === key ? this.scopeChosen.scope : 'town';
        this.renderScope();
    }

    renderScope() {
        const button = $('chat-scope');
        button.hidden = !this.zone;
        const town = this.zone && this.scope === 'town';
        button.textContent = town ? '街の中' : '周り';
        button.classList.toggle('is-on', !!town);
        $('chat-input').placeholder = town
            ? `${this.zone.name}の中にいる人に話す`
            : '近くの人に話す（100文字まで）';
    }

    // 同期で受け取った発言
    receive(messages) {
        for (const m of messages ?? []) {
            if (!m || this.seen.has(m.id)) continue;
            this.seen.add(m.id);
            this.lastId = Math.max(this.lastId, m.id);
            this.bubbles.set(m.character_id, { text: m.body, until: performance.now() / 1000 + BUBBLE_SECONDS });
            this.appendLog(m);
        }
    }

    appendLog(m) {
        const log = $('chat-log');
        const line = document.createElement('div');
        line.className = 'chat-line';
        const name = document.createElement('span');
        name.className = m.character_id === this.game.player.id ? 'chat-name is-self' : 'chat-name';
        name.textContent = m.name;
        const body = document.createElement('span');
        body.textContent = m.body;
        if (m.scope === 'town') {
            const tag = document.createElement('span');
            tag.className = 'chat-tag';
            tag.textContent = m.zone_name ? `[${m.zone_name}]` : '[街]';
            line.append(tag);
        }
        line.append(name, body);
        log.append(line);
        while (log.children.length > LOG_LINES) log.firstChild.remove();
        log.scrollTop = log.scrollHeight;
        log.hidden = false;
        log.classList.add('is-fresh');
        clearTimeout(this.fadeTimer);
        this.fadeTimer = setTimeout(() => log.classList.remove('is-fresh'), 8000);
    }

    // 頭上の吹き出し（renderer の overlay から、世界の座標で描く）
    drawBubbles(ctx, t, people) {
        for (const p of people) {
            const b = this.bubbles.get(p.id);
            if (!b) continue;
            if (b.until < t) {
                this.bubbles.delete(p.id);
                continue;
            }
            drawBubble(ctx, p.x, p.y - 64, b.text, Math.min(1, (b.until - t) / 0.6)); // 名前の札の上
        }
    }
}

function drawBubble(ctx, x, y, text, alpha) {
    ctx.save();
    ctx.globalAlpha = alpha;
    ctx.font = '13px "Hiragino Kaku Gothic ProN", "Noto Sans JP", sans-serif';
    const lines = wrap(ctx, text, 190);
    const width = Math.max(...lines.map((l) => ctx.measureText(l).width)) + 16;
    const height = lines.length * 17 + 10;
    const left = x - width / 2;
    const top = y - height;
    ctx.fillStyle = 'rgba(255,255,255,0.95)';
    ctx.strokeStyle = 'rgba(40,40,60,0.85)';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.roundRect?.(left, top, width, height, 8);
    if (!ctx.roundRect) ctx.rect(left, top, width, height);
    ctx.fill();
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(x - 6, top + height - 1);
    ctx.lineTo(x, top + height + 8);
    ctx.lineTo(x + 6, top + height - 1);
    ctx.fill();
    ctx.fillStyle = '#1a1a24';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'top';
    lines.forEach((l, i) => ctx.fillText(l, left + 8, top + 6 + i * 17));
    ctx.restore();
}

// 1文字ずつ詰めて、幅に収まる行に分ける（最大3行）
function wrap(ctx, text, maxWidth) {
    const lines = [];
    let line = '';
    for (const ch of text) {
        if (ctx.measureText(line + ch).width > maxWidth && line) {
            lines.push(line);
            line = ch;
            if (lines.length === 3) break;
        } else {
            line += ch;
        }
    }
    if (lines.length < 3 && line) lines.push(line);
    else if (lines.length === 3 && line) lines[2] = `${lines[2].slice(0, -1)}…`;
    return lines;
}

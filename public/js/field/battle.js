// フィールドの戦闘ウィンドウ。
// 魔物に触れるとサーバーで既存の探索1回（自動のターン制戦闘）を行い、そのログをターンごとに送って見せる。

const $ = (id) => document.getElementById(id);
const AUTO_SECONDS = 1.6;

const OUTCOME = {
    victory: { title: '勝利！', cls: 'is-win' },
    defeat: { title: '力尽きた……', cls: 'is-lose' },
    draw: { title: '決着はつかなかった', cls: 'is-draw' },
    event: { title: '出来事', cls: 'is-draw' },
};

export class FieldBattle {
    constructor(game) {
        this.game = game;
        this.active = false;
        this.turns = [];
        this.index = 0;
        this.timer = null;
        $('battle-window').addEventListener('click', () => this.advance());
        $('battle-next').addEventListener('click', (e) => {
            e.stopPropagation();
            this.advance();
        });
        $('battle-skip').addEventListener('click', (e) => {
            e.stopPropagation();
            this.showResult();
        });
        $('battle-close').addEventListener('click', () => this.close());
    }

    async start(monster) {
        if (this.active) return;
        const game = this.game;
        this.active = true;
        this.monster = monster;
        this.data = null;
        game.paused = true;
        game.input.reset();

        const name = monster.look?.name ?? '魔物';
        $('battle').hidden = false;
        $('battle-result').hidden = true;
        $('battle-controls').hidden = true;
        this.setEnemy(name, monster.look?.image ?? null);
        $('battle-turn-title').textContent = '';
        const areaLevel = game.encounters.areaLevels.get(monster.area) ?? 1;
        $('battle-log').textContent = areaLevel > game.encounters.playerLevel + 5
            ? `見るからに手強い${name}たちだ……！（推奨Lv${areaLevel}〜）`
            : `${name}たちの気配が迫ってくる……！`;
        $('battle').classList.remove('is-flash');
        void $('battle').offsetWidth;
        $('battle').classList.add('is-flash');

        const data = await game.postJson(`${game.urls.battle}/${monster.area}/battle`);
        if (!data.ok) {
            this.hide();
            if (data.vitals) game.updateVitals(data.vitals);
            game.encounters.grace(performance.now() / 1000);
            game.toast(data.message ?? '戦えなかった。');
            return;
        }
        this.data = data;
        this.setEnemy(data.enemy?.name ?? name, data.enemy?.image ?? monster.look?.image ?? null);
        this.turns = data.turns?.length ? data.turns : [{ title: '', html: `${data.enemy?.name ?? name}との戦い` }];
        this.index = -1;
        $('battle-controls').hidden = false;
        this.advance();
    }

    setEnemy(name, image) {
        $('battle-enemy-name').textContent = name;
        const img = $('battle-enemy');
        if (image) {
            img.src = image;
            img.hidden = false;
        } else {
            img.hidden = true;
        }
    }

    advance() {
        if (!this.active || !this.data) return;
        clearTimeout(this.timer);
        if (!$('battle-result').hidden) return this.close();
        this.index++;
        if (this.index >= this.turns.length) return this.showResult();
        const turn = this.turns[this.index];
        $('battle-turn-title').textContent = turn.title;
        $('battle-log').innerHTML = turn.html;
        $('battle-progress').textContent = `${this.index + 1} / ${this.turns.length}`;
        const img = $('battle-enemy');
        img.classList.remove('is-hit');
        void img.offsetWidth;
        if (/ダメージ/.test(turn.html)) img.classList.add('is-hit');
        this.timer = setTimeout(() => this.advance(), AUTO_SECONDS * 1000 + Math.min(1600, turn.html.length * 4));
    }

    showResult() {
        if (!this.data) return;
        clearTimeout(this.timer);
        const d = this.data;
        const o = OUTCOME[d.outcome] ?? OUTCOME.draw;
        $('battle-controls').hidden = true;
        $('battle-result').hidden = false;
        $('battle-result').className = `panel ${o.cls}`;
        $('battle-result-title').textContent = o.title;
        const rows = [];
        if (d.exp) rows.push(`経験値 +${d.exp.toLocaleString()}`);
        if (d.gold) rows.push(`Gold +${d.gold.toLocaleString()}`);
        if (d.job_exp) rows.push(`職業経験値 +${d.job_exp.toLocaleString()}`);
        for (const lv of d.level_ups ?? []) rows.push(`レベルが ${lv} に上がった！`);
        for (const item of d.drops ?? []) rows.push(`${item} を手に入れた！`);
        for (const note of d.notes ?? []) rows.push(note);
        if (d.respawn) rows.push('気がつくと、街の広場に運ばれていた。宿屋で休もう。');
        const list = $('battle-result-list');
        list.replaceChildren(...rows.map((text) => {
            const li = document.createElement('li');
            li.textContent = text;
            return li;
        }));
        $('battle-close').focus();
    }

    close() {
        if (!this.active) return;
        const game = this.game;
        const d = this.data;
        clearTimeout(this.timer);
        this.hide();
        if (this.monster) game.encounters.defeat(this.monster);
        game.encounters.grace(performance.now() / 1000);
        if (d?.vitals) game.updateVitals(d.vitals);
        if (d?.respawn) game.warpTo(d.respawn.plane, d.respawn.x, d.respawn.y);
    }

    hide() {
        $('battle').hidden = true;
        this.active = false;
        this.data = null;
        this.game.paused = this.game.mapOpen || !$('dialog').hidden;
    }
}

// ヴァルゼリア大陸フィールドの本体。
// 歩く・周りの冒険者と位置をやり取りする・施設や探索地の入口から既存の画面へ入る・転移陣で浮遊島へ渡る。

import { FieldAmbient } from './ambient.js';
import { FACING, TILE } from './constants.js';
import { FieldBattle } from './battle.js';
import { FieldChat } from './chat.js';
import { FieldEncounters } from './encounters.js';
import { FieldInput, fieldPointFromClient, movementSpeedMultiplier, moveVectorToTarget } from './input.js';
import { MacroMap } from './macro.js';
import { panMapCamera, pinchMapCamera, zoomMapCamera } from './map-camera.js';
import { mapDestinations } from './map-destinations.js';
import { FieldPresence } from './presence.js';
import { FieldNations } from './nations.js';
import { FieldRenderer, buildLandMapImage, buildSkyMapImage, drawMinimap, drawWorldMap } from './renderer.js';
import { FieldWorld } from './world.js';
import { FieldGenerator } from './worldgen.js';
import { findTapPath } from './tap-path.js';

const boot = JSON.parse(document.getElementById('field-boot').textContent);
// 視点：画面の短い辺に映すマス数の範囲（大きいほど引いた視点）
const VIEW_TILES_MIN = 10;
const VIEW_TILES_MAX = 160;
const def = boot.world;
const $ = (id) => document.getElementById(id);

const LANDMARK_TEXT = {
    castle: 'アークレア城。王家の旗が風にはためいている。冒険者の帰りを待つ人々の声が聞こえる。',
    world_tree: '世界樹。見上げても梢は見えない。幹に触れると、かすかな精霊の歌が聞こえる。',
    furnace: '大溶鉱炉。昼も夜も火が落ちることはない。鍛冶師たちの槌音が響く。',
    cathedral: '大聖堂。静かな祈りの声が満ちている。',
    magic_tower: '大魔導塔。塔の頂で、星を映す水晶がゆっくり回っている。',
    sky_temple: '天空大神殿。雲の上の光が、白い柱を金色に染めている。',
    demon_castle: '魔王城。城の奥から、地の底のような唸り声が響いてくる……。',
    lighthouse: 'マリネス灯台。夜の海を行く船の目印だ。',
    oasis_shrine: '命のオアシス。砂漠を越えてきた旅人たちが喉をうるおしている。',
    mausoleum: '霊廟。ひやりとした空気が漂っている。',
    mine_gate: '鉱山の門。奥から鉱夫たちのかけ声が聞こえる。',
    academy_hall: '学舎。窓の向こうで学徒たちが魔導書をめくっている。',
    ship: '港に停まる船。荷を積む水夫たちが忙しく行き交う。',
    sky_tower: '天空の転移塔。塔の足元の転移陣が、東の空に浮かぶ大陸へ通じている。',
    well: '井戸の水は冷たくておいしい。旅の疲れが少しやわらいだ気がする。',
    lodge: '宿場の主人「長旅ごくろうさん。この先の道のりもまだ長い。旅の祠に祈っておけば、地図で目印になるぞ。」',
    stable: '馬屋。旅の馬たちがのんびり干し草を食んでいる。',
    windmill: '風車がのんびりと回っている。中から粉をひく音が聞こえる。',
    watchtower: '見張りの兵士「この辺りも魔物が増えた。街道を外れるなら気をつけろ。」',
    shrine: '小さな祠。旅の無事を祈る人々が、野の花を供えている。',
    statue: '崩れた英雄像。台座の文字はすり減って、もう読めない。',
};

class FieldGame {
    constructor(world, renderer, gen) {
        this.world = world;
        this.renderer = renderer;
        this.gen = gen;
        this.spectator = !!boot.spectator;
        this.walkingOnly = !!boot.walkingOnly;
        this.features = boot.features ?? {};
        this.input = new FieldInput();
        const pos = boot.player.position;
        this.player = {
            id: boot.player.id,
            name: boot.player.name,
            icon: boot.player.icon,
            x: pos.x,
            y: pos.y,
            plane: pos.plane,
            facing: pos.facing ?? 0,
            moving: false,
            hidden: !!boot.player.hidden,
        };
        if (!this.spectator) [this.player.x, this.player.y] = world.nearestOpen(this.player.x, this.player.y);
        this.spectatorPositions = { [this.player.plane]: { x: this.player.x, y: this.player.y } };
        this.camera = { x: 0, y: 0 };
        this.presence = new FieldPresence({ url: boot.urls.sync, csrf: boot.csrf, interval: def.sync_interval_seconds, staleAfter: def.presence_seconds ?? 10 });
        this.others = this.presence.players;
        this.positionActionPending = false;
        this.minimapAt = 0;
        this.interactable = null;
        this.paused = false;
        this.moveTarget = null;
        this.movePath = [];
        this.moveBlockedFor = 0;
        this.mapOpen = false;
        this.mapHits = [];
        this.mapDestinations = [];
        this.mapKind = 'all';
        this.mapSelectedId = null;
        this.mapCamera = { zoom: 1, panX: 0, panY: 0 };
        this.mapPointers = new Map();
        this.mapGestureMoved = false;
        this.mapIgnoreClickUntil = 0;
        this.landMap = null;
        this.skyMap = null;
        this.worldMapImage = new Image();
        this.worldMapImage.src = boot.urls.worldMap;
        this.lastPlace = '';
        this.urls = boot.urls;
        this.csrf = boot.csrf;
        this.vitals = boot.player.vitals;
        this.encounters = new FieldEncounters(def, world, gen, world.enterableAreas, boot.player.claimed_spots ?? []);
        this.encounters.playerLevel = boot.player.level ?? 1;
        this.ambient = new FieldAmbient(world, gen);
        this.battle = new FieldBattle(this);
        this.chat = new FieldChat(this);
        if (boot.nationBuilding) {
            this.nations = new FieldNations(this, boot);
            const nationButton = $('btn-nation');
            if (nationButton) {
                nationButton.addEventListener('click', () => this.notice(
                    nationButton.dataset.comingSoonTitle || '国づくり',
                    nationButton.dataset.comingSoonMessage || '国づくりは現在準備中です。',
                ));
            }
        }
        this.renderVitals();
        // 祈った旅の祠は地図で目立たせる。この端末に覚えておく
        this.waystoneKey = `field.waystones.${boot.player.id}`;
        this.waystones = new Set(this.loadWaystones());
        for (const o of world.objects) if (o.type === 'waystone') o.discovered = this.waystones.has(o.placeId);

        this.input.onAction = (a) => this.action(a);
        // タッチ端末では A・B ボタンを表示。フィールドのタップ移動はマウスでも利用できる。
        if (window.matchMedia?.('(pointer: coarse)').matches || navigator.maxTouchPoints > 0) document.body.classList.add('is-touch');
        this.input.attachTapMove($('field-canvas'), (x, y) => this.moveToScreenPoint(x, y));
        this.input.attachHoldDash($('btn-dash-hold'));
        $('btn-action').addEventListener('click', () => this.action('interact'));
        $('btn-map').addEventListener('click', () => this.action('map'));
        $('btn-zoom-in').addEventListener('click', () => this.zoomBy(-1));
        $('btn-zoom-out').addEventListener('click', () => this.zoomBy(1));
        $('btn-dash')?.addEventListener('click', () => {
            this.input.dashLocked = !this.input.dashLocked;
            $('btn-dash').classList.toggle('is-on', this.input.dashLocked);
        });
        $('btn-spectator-plane')?.addEventListener('click', () => this.toggleSpectatorPlane());
        $('btn-spectator-roster')?.addEventListener('click', () => this.toggleSpectatorRoster(true));
        $('spectator-roster-close')?.addEventListener('click', () => this.toggleSpectatorRoster(false));
        $('prompt').addEventListener('click', () => this.action('interact'));
        $('worldmap-close').addEventListener('click', () => this.toggleMap(false));
        $('worldmap-canvas').addEventListener('click', (e) => this.mapClick(e));
        $('worldmap-canvas').addEventListener('pointerdown', (e) => this.mapPointerDown(e));
        $('worldmap-canvas').addEventListener('pointermove', (e) => this.mapPointerMove(e));
        $('worldmap-canvas').addEventListener('pointerup', (e) => this.mapPointerUp(e));
        $('worldmap-canvas').addEventListener('pointercancel', (e) => this.mapPointerUp(e));
        $('worldmap-canvas').addEventListener('wheel', (e) => {
            e.preventDefault();
            const point = this.mapPointerPosition(e);
            this.zoomMap(e.deltaY < 0 ? 1.2 : 1 / 1.2, point);
        }, { passive: false });
        $('worldmap-zoom-in').addEventListener('click', () => this.zoomMap(1.5));
        $('worldmap-zoom-out').addEventListener('click', () => this.zoomMap(1 / 1.5));
        $('worldmap-zoom-reset').addEventListener('click', () => this.resetMapCamera());
        $('worldmap-travel').addEventListener('click', () => this.travelToMapDestination());
        $('worldmap-search').addEventListener('input', () => this.renderMapDestinations());
        for (const button of document.querySelectorAll('.worldmap-filter')) {
            button.addEventListener('click', () => {
                this.mapKind = button.dataset.kind;
                this.renderMapDestinations();
            });
        }
        $('dialog-cancel').addEventListener('click', () => this.closeDialog());
        window.addEventListener('resize', () => this.fit());
        window.addEventListener('pagehide', () => this.beacon());
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                this.clearMoveTarget();
                this.beacon();
            }
            else this.presence.reset();
        });
        window.addEventListener('wheel', (e) => {
            if (this.mapOpen || this.nationEditor?.isOpen || !$('dialog').hidden) return;
            this.zoomBy(e.deltaY > 0 ? 1 : -1);
        }, { passive: true });
        window.addEventListener('keydown', (e) => {
            if (e.target instanceof HTMLElement && e.target.closest('input, textarea')) return; // チャットの入力中
            if (e.key === '+' || e.key === ';' || e.code === 'NumpadAdd') this.zoomBy(-1);
            if (e.key === '-' || e.code === 'NumpadSubtract') this.zoomBy(1);
        });
        this.fit();
    }

    // 視点の引き具合（短い辺に映すマス数）。1回で約1.2倍ずつ変える
    zoomBy(direction) {
        const next = direction > 0 ? this.viewTiles * 1.2 : this.viewTiles / 1.2;
        this.viewTiles = Math.round(Math.max(VIEW_TILES_MIN, Math.min(VIEW_TILES_MAX, next)));
        try {
            localStorage.setItem('field.viewTiles', String(this.viewTiles));
        } catch {
            // 保存できなくても今の画面では効く
        }
        this.fit();
    }

    savedViewTiles() {
        try {
            const v = Number(localStorage.getItem('field.viewTiles'));
            return v >= VIEW_TILES_MIN && v <= VIEW_TILES_MAX ? v : null;
        } catch {
            return null;
        }
    }

    // 画面の短い辺に何マス映すか（大きいほど引いた視点）。ホイール・+/- キーで変えられ、端末に覚えておく
    fit() {
        const w = window.innerWidth;
        const h = window.innerHeight;
        if (!w || !h) return; // 裏のタブなどで大きさが 0 の間は、前の大きさのまま
        this.viewTiles ??= this.savedViewTiles() ?? (Math.min(w, h) < 600 ? 18 : 24);
        this.renderer.resize(w, h, this.viewTiles);
    }

    // ---- ループ --------------------------------------------------------------------------

    start() {
        let last = performance.now();
        const frame = (now) => {
            const dt = Math.min(0.05, (now - last) / 1000);
            last = now;
            this.update(dt, now / 1000);
            this.draw(now / 1000);
            requestAnimationFrame(frame);
        };
        requestAnimationFrame(frame);
    }

    update(dt, t) {
        this.nations?.tick(t);
        const p = this.player;
        const manual = this.paused || this.positionActionPending ? { x: 0, y: 0 } : this.input.vector();
        if (manual.x || manual.y) this.clearMoveTarget();
        let v = manual;
        let targetDistance = null;
        if (!this.paused && !this.positionActionPending && !manual.x && !manual.y && this.moveTarget?.plane === p.plane) {
            while (this.movePath.length) {
                const toward = moveVectorToTarget(p, this.movePath[0]);
                if (toward.arrived) this.movePath.shift();
                else {
                    v = toward;
                    targetDistance = toward.distance;
                    break;
                }
            }
            if (!this.movePath.length) this.clearMoveTarget();
        }
        p.moving = v.x !== 0 || v.y !== 0;
        if (p.moving) {
            const speed = def.movement.walk_tiles_per_second * TILE
                * movementSpeedMultiplier(def.movement, this.spectator, this.input.dashing);
            const distance = Math.min(speed * dt, targetDistance ?? Number.POSITIVE_INFINITY);
            const dx = v.x * distance;
            const dy = v.y * distance;
            const beforeX = p.x;
            const beforeY = p.y;
            if (this.spectator) {
                p.x += dx;
                p.y += dy;
                this.clampSpectatorPosition();
            } else {
                if (dx && this.world.canOccupy(p.x + dx, p.y, p.x, p.y)) p.x += dx;
                if (dy && this.world.canOccupy(p.x, p.y + dy, p.x, p.y)) p.y += dy;
            }
            p.facing = Math.abs(v.x) > Math.abs(v.y) ? (v.x < 0 ? FACING.left : FACING.right) : v.y < 0 ? FACING.up : FACING.down;
            if (this.moveTarget && !this.spectator) {
                if (Math.hypot(p.x - beforeX, p.y - beforeY) < 0.01) this.moveBlockedFor += dt;
                else this.moveBlockedFor = 0;
                if (this.moveBlockedFor >= 0.25) {
                    this.clearMoveTarget();
                    this.toast('そこへは進めない。');
                }
            }
        }
        // 歩いている間は右上のボタン類を透かして視界をあける（止まって少ししたら戻す）
        if (p.moving) this.movedAt = t;
        const walking = t - (this.movedAt ?? -10) < 0.6;
        if (walking !== this.walkingUi) {
            this.walkingUi = walking;
            document.body.classList.toggle('is-walking', walking);
        }

        this.followCamera();
        this.world.prefetch(Math.floor(p.x / TILE), Math.floor(p.y / TILE), 2, 1);

        this.presence.update(dt);

        this.ambient.update(dt, p, t);
        if (!this.spectator && this.features.combat && !this.paused && !this.positionActionPending) {
            const half = Math.max(this.renderer.viewW, this.renderer.viewH) / TILE / 2;
            const contact = this.encounters.update(dt, p, t, Number.isFinite(half) && half > 0 ? half : 20);
            if (contact) {
                this.clearMoveTarget();
                this.battle.start(contact);
            }
        }

        this.interactable = this.spectator || this.paused ? null : this.findInteractable(p);
        this.updatePrompt();

        if (!this.positionActionPending && document.visibilityState !== 'hidden') {
            this.sync();
        }
        if (t - this.minimapAt > 1.2) {
            this.minimapAt = t;
            drawMinimap($('minimap'), this.gen, p);
            const tx = Math.floor(p.x / TILE);
            const ty = Math.floor(p.y / TILE);
            const place = this.world.placeName(tx, ty);
            if (place !== this.lastPlace) {
                this.lastPlace = place;
                $('hud-place').textContent = place;
            }
            $('hud-coords').textContent = p.plane === 'sky' ? `浮遊大陸 ${tx - this.gen.skyX0}, ${ty}` : `${tx}, ${ty}`;
        }
    }

    followCamera() {
        const p = this.player;
        const vw = this.renderer.viewW;
        const vh = this.renderer.viewH;
        let [x0, y0, x1, y1] = [0, 0, this.gen.worldW * TILE, this.gen.worldH * TILE];
        if (p.plane === 'sky') [x0, y0, x1, y1] = [this.gen.skyX0 * TILE, this.gen.skyY0 * TILE, (this.gen.skyX0 + this.gen.skyW) * TILE, (this.gen.skyY0 + this.gen.skyH) * TILE];
        this.camera.x = Math.max(x0, Math.min(x1 - vw, p.x - vw / 2));
        this.camera.y = Math.max(y0, Math.min(y1 - vh, p.y - 16 - vh / 2));
    }

    moveToScreenPoint(clientX, clientY) {
        if (this.paused || this.positionActionPending || this.battle?.active) return;
        const target = fieldPointFromClient(clientX, clientY, $('field-canvas').getBoundingClientRect(), this.camera, this.renderer.zoom);
        if (!target) return;
        if (this.spectator) {
            this.moveTarget = { ...target, plane: this.player.plane };
            this.movePath = [target];
            this.moveBlockedFor = 0;
            return;
        }
        const path = findTapPath(this.world, this.player, target);
        if (!path?.waypoints.length) {
            this.clearMoveTarget();
            this.toast('そこへ続く道が見つからない。');
            return;
        }
        this.moveTarget = { ...target, plane: this.player.plane };
        this.movePath = path.waypoints;
        this.moveBlockedFor = 0;
    }

    clearMoveTarget() {
        this.moveTarget = null;
        this.movePath = [];
        this.moveBlockedFor = 0;
    }

    draw(t) {
        this.renderer.render(this.camera, this.player, [...this.others.values()], t, {
            sprites: [...this.encounters.sprites(t), ...this.ambient.sprites(this.player, t)],
            overlay: (ctx) => {
                this.ambient.drawParticles(ctx, t);
                this.chat.drawBubbles(ctx, t, [this.player, ...this.others.values()]);
                this.drawMoveTarget(ctx, t);
            },
        });
        if (this.mapOpen) this.drawMap(t);
    }

    drawMoveTarget(ctx, t) {
        if (!this.moveTarget || this.moveTarget.plane !== this.player.plane) return;
        const scale = 1 / this.renderer.zoom;
        const radius = (9 + Math.sin(t * 7) * 2) * scale;
        ctx.save();
        ctx.strokeStyle = 'rgba(255, 230, 140, 0.95)';
        ctx.fillStyle = 'rgba(255, 220, 110, 0.18)';
        ctx.lineWidth = 2 * scale;
        ctx.beginPath();
        ctx.arc(this.moveTarget.x, this.moveTarget.y, radius, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();
        ctx.restore();
    }

    // ---- 調べる ------------------------------------------------------------------------

    // 宝箱・採取 > 話しかける > 建物・入口など
    findInteractable(p) {
        if (this.walkingOnly) return null;
        if (this.features.gathering) {
            const spot = this.encounters.spotNear(p);
            if (spot) return { type: 'spot', spot };
        }
        const person = this.ambient.talkable(p);
        if (person) return { type: 'npc', person };
        const object = this.world.interactableNear(p.x, p.y);
        if (!object) return null;
        if (object.nationId !== undefined && !this.features.nations) return null;
        if ((object.type === 'facility' || object.type === 'board') && !this.features.facilities) return null;
        if (object.type === 'entrance' && !this.features.areas) return null;
        if (object.type === 'teleporter' && !this.features.teleport) return null;
        return object;
    }

    loadWaystones() {
        try {
            return JSON.parse(localStorage.getItem(this.waystoneKey) ?? '[]');
        } catch {
            return [];
        }
    }

    prayAtWaystone(o) {
        const known = this.waystones.has(o.placeId);
        this.waystones.add(o.placeId);
        o.discovered = true;
        try {
            localStorage.setItem(this.waystoneKey, JSON.stringify([...this.waystones]));
        } catch {
            // 保存できない時は、この画面を開いている間だけ覚えている
        }
        this.toast(known
            ? `${o.placeName}の旅の祠。地図から、いつでもここへ飛べる。`
            : `旅の祠に祈りをささげた。\n「${o.placeName}」を覚えた。地図で水色の目印になった。`);
    }

    readSignpost(o) {
        const lines = [`【道しるべ】${o.placeName}`];
        for (const end of o.sign ?? []) lines.push(`${end.dir}へ ${end.distance.toLocaleString()}マス：${end.name}`);
        this.toast(lines.join('\n'));
    }

    updateVitals(v) {
        this.vitals = v;
        this.renderVitals();
    }

    renderVitals() {
        const v = this.vitals;
        if (!v) return;
        const hpRate = Math.max(0, Math.min(1, v.hp / Math.max(1, v.max_hp)));
        $('hud-hp-bar').style.width = `${hpRate * 100}%`;
        $('hud-hp-bar').classList.toggle('is-low', hpRate < 0.3);
        $('hud-hp-text').textContent = `HP ${v.hp} / ${v.max_hp}`;
        const st = v.stamina;
        $('hud-stamina').hidden = !st?.enabled;
        if (st?.enabled) $('hud-stamina').textContent = `探索力 ${st.current} / ${st.max}`;
    }

    updatePrompt() {
        const o = this.interactable;
        const el = $('prompt');
        if (!o) {
            el.hidden = true;
            return;
        }
        el.hidden = false;
        $('prompt-text').textContent = this.promptText(o);
    }

    promptText(o) {
        if (o.nationId !== undefined) return `${o.nationName}を調べる`;
        switch (o.type) {
            case 'spot':
                return o.spot.kind === 'chest' ? '宝箱を開ける' : o.spot.ore ? '鉱石を掘る' : '薬草を摘む';
            case 'npc':
                return `${o.person.name}と話す`;
            case 'waystone':
                return '旅の祠に祈る';
            case 'signpost':
                return '道しるべを読む';
            case 'facility':
            case 'board':
                return `${o.label}に入る`;
            case 'entrance':
                return o.enterable ? `${o.name}へ` : `${o.name}（封印中）`;
            case 'teleporter':
                return `${o.name}を使う`;
            case 'barrier':
            case 'guard_gate':
                return '調べる';
            default:
                return `${o.label ?? ''}を調べる`;
        }
    }

    action(a) {
        if (this.nationEditor?.isOpen) return;
        if (this.positionActionPending) return;
        if (this.battle?.active) {
            if (a === 'interact' || a === 'close') this.battle.advance();
            return;
        }
        this.clearMoveTarget();
        if (a === 'map') return this.toggleMap(!this.mapOpen);
        if (a === 'close') {
            if (this.mapOpen) this.toggleMap(false);
            this.closeDialog();
            return;
        }
        if (this.spectator) return;
        if (a !== 'interact' || this.paused) return;
        const o = this.interactable;
        if (!o) return;
        if (o.nationId !== undefined) return this.confirm(o.nationName, '国家の案内を開きますか？', '国家を見る', () => { window.location.href = `${boot.urls.nations}/${o.nationId}/community`; });
        switch (o.type) {
            case 'spot':
                return this.claimSpot(o.spot);
            case 'npc':
                return this.toast(`${o.person.name}「${o.person.line}」`);
            case 'waystone':
                return this.prayAtWaystone(o);
            case 'signpost':
                return this.readSignpost(o);
            case 'facility':
            case 'board': {
                if (!this.world.unlockedCities.has(o.cityId)) return this.toast('まだこの街の施設は使えない。');
                return this.confirm(o.label, `${o.label}に入りますか？`, '入る', () => this.post(`${boot.urls.facility}/${o.cityId}/facilities/${o.facility}`));
            }
            case 'entrance': {
                if (!o.enterable) return this.toast(`${o.name}は、まだ封印されている。`);
                const lv = o.level ? `推奨 Lv${o.level[0]}〜${o.level[1]}` : '';
                return this.confirm(o.name, `${lv}\nこの探索地へ出発しますか？`, '出発する', () => this.post(`${boot.urls.area}/${o.area_id}/enter`));
            }
            case 'teleporter': {
                if (!o.unlocked) return this.toast('転移陣は沈黙している。天空神殿へ至る者だけが使えるようだ。');
                const to = o.plane === 'land' ? '雲の上の浮遊大陸へ渡りますか？' : '地上の天空の転移塔へ戻りますか？';
                return this.confirm(o.name, to, '転移する', () => this.teleport(o.key));
            }
            case 'barrier':
                return this.toast(o.unlocked ? '魔王の結界は破られている。山頂の魔王城へ続く道が開いている。' : '禍々しい結界が道を塞いでいる。天空の試練を越えた者だけが通れるという……。');
            case 'guard_gate':
                return this.toast(`衛兵「この先は${o.label.replace('（未踏）', '')}。まだ通行の許可は出ていない。」`);
            default:
                return this.toast(LANDMARK_TEXT[o.type] ?? `${o.label}。`);
        }
    }

    // ---- 通信 ------------------------------------------------------------------------------

    positionPayload() {
        const p = this.player;
        return { plane: p.plane, x: Math.max(0, Math.round(p.x)), y: Math.max(0, Math.round(p.y)), facing: p.facing };
    }

    async sync() {
        if (this.spectator) {
            const data = await this.presence.poll(this.positionPayload());
            if (data) this.updateSpectatorRoster(data);
            return data;
        }
        // chat_since：これより後の、近くの人の発言を受け取る
        const data = await this.presence.poll({ ...this.positionPayload(), chat_since: this.chat.lastId });
        if (data?.messages?.length) this.toast(data.messages.join('\n'));
        if (data) this.chat.setZone(data.zone ?? null);
        if (data?.chat?.length) this.chat.receive(data.chat);
        return data;
    }

    beacon() {
        if (this.spectator || this.positionActionPending) return;
        const data = new FormData();
        const p = this.positionPayload();
        data.append('_token', boot.csrf);
        for (const [k, v] of Object.entries(p)) data.append(k, String(v));
        navigator.sendBeacon?.(boot.urls.sync, data);
    }

    async postJson(url, body = {}) {
        if (this.spectator || this.walkingOnly) return { ok: false, message: '現在は移動のみ利用できます。' };
        this.positionActionPending = true;
        await this.presence.pending;
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': boot.csrf },
                body: JSON.stringify({ ...this.positionPayload(), ...body }),
                credentials: 'same-origin',
            });
            let data = {};
            try {
                data = await res.json();
            } catch {
                data = {};
            }
            if (!res.ok && data.ok === undefined) data = { ok: false, message: data.message ?? (res.status === 429 ? '少し休んでから、もう一度。' : '通信に失敗しました。') };
            // 敗北でサーバーが帰還済みなら、結果表示中の同期も帰還先から行う。
            if (data.respawn) this.warpTo(data.respawn.plane, data.respawn.x, data.respawn.y);
            return data;
        } catch {
            return { ok: false, message: '通信に失敗しました。' };
        } finally {
            this.positionActionPending = false;
        }
    }

    async claimSpot(spot) {
        if (this.claiming) return;
        this.claiming = true;
        try {
            const data = await this.postJson(`${boot.urls.spot}/${encodeURIComponent(spot.key)}/claim`);
            if (data.ok || /空っぽ/.test(data.message ?? '')) this.encounters.markClaimed(spot.key);
            this.toast(data.message ?? '……');
        } finally {
            this.claiming = false;
        }
    }

    async post(url) {
        if (this.spectator) return;
        this.paused = true;
        this.positionActionPending = true;
        await this.presence.pending;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        const fields = { _token: boot.csrf, ...this.positionPayload() };
        for (const [k, v] of Object.entries(fields)) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = k;
            input.value = String(v);
            form.appendChild(input);
        }
        document.body.appendChild(form);
        $('loading').hidden = false;
        $('loading-text').textContent = '移動中…';
        form.submit();
    }

    async teleport(key) {
        if (this.spectator) return;
        this.closeDialog();
        this.paused = true;
        this.positionActionPending = true;
        const fade = $('fade');
        fade.classList.add('is-on');
        try {
            await this.presence.pending;
            const res = await fetch(`${boot.urls.teleport}/${key}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': boot.csrf },
                body: JSON.stringify(this.positionPayload()),
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (!res.ok) {
                this.toast(data.message ?? '転移できなかった。');
                return;
            }
            await new Promise((r) => setTimeout(r, 450));
            this.warpTo(data.plane, data.x, data.y);
            this.toast(`${data.name}に降り立った。`);
        } catch {
            this.toast('転移できなかった。');
        } finally {
            this.positionActionPending = false;
            setTimeout(() => fade.classList.remove('is-on'), 150);
            this.paused = false;
        }
    }

    warpTo(plane, x, y) {
        this.clearMoveTarget();
        this.player.plane = plane;
        if (this.spectator) {
            this.player.x = x;
            this.player.y = y;
            this.clampSpectatorPosition();
            this.spectatorPositions[plane] = { x: this.player.x, y: this.player.y };
            this.updateSpectatorPlaneButton();
        } else {
            [this.player.x, this.player.y] = this.world.nearestOpen(x, y);
        }
        this.presence.reset();
        this.minimapAt = 0;
        this.lastPlace = '';
        this.followCamera();
    }

    // ---- 世界地図 ----------------------------------------------------------------------------

    toggleMap(open) {
        if (open) this.clearMoveTarget();
        this.mapOpen = open;
        $('worldmap').hidden = !open;
        this.paused = open || !$('dialog').hidden;
        if (open) {
            this.resetMapCamera();
            const p = this.player;
            $('worldmap-here-name').textContent = this.world.placeName(Math.floor(p.x / TILE), Math.floor(p.y / TILE)) || 'ヴァルゼリア大陸';
            this.mapDestinations = mapDestinations(this.gen, this.world, p.plane, p);
            this.mapSelectedId = null;
            this.mapKind = 'all';
            $('worldmap-search').value = '';
            this.renderMapDestinations();
            this.drawMap(performance.now() / 1000);
        } else {
            this.mapPointers.clear();
            $('worldmap-canvas').classList.remove('is-dragging');
        }
    }

    mapGeometry() {
        const canvas = $('worldmap-canvas');
        const view = this.mapView();
        const [width, height] = view.baseSize ?? [view.base.width, view.base.height];
        return { viewport: { width: canvas.width, height: canvas.height }, image: { width, height } };
    }

    mapPointerPosition(e) {
        const canvas = $('worldmap-canvas');
        const rect = canvas.getBoundingClientRect();
        return {
            x: (e.clientX - rect.left - canvas.clientLeft) * canvas.width / canvas.clientWidth,
            y: (e.clientY - rect.top - canvas.clientTop) * canvas.height / canvas.clientHeight,
        };
    }

    resetMapCamera() {
        this.mapCamera = { zoom: 1, panX: 0, panY: 0 };
        $('worldmap-zoom-label').textContent = '100%';
    }

    zoomMap(factor, anchor = null) {
        if (!this.mapOpen) return;
        const { viewport, image } = this.mapGeometry();
        anchor ??= { x: viewport.width / 2, y: viewport.height / 2 };
        this.mapCamera = zoomMapCamera(this.mapCamera, factor, anchor, viewport, image);
        $('worldmap-zoom-label').textContent = `${Math.round(this.mapCamera.zoom * 100)}%`;
    }

    mapPointerDown(e) {
        if (!this.mapOpen || (e.pointerType === 'mouse' && e.button !== 0)) return;
        const point = this.mapPointerPosition(e);
        this.mapPointers.set(e.pointerId, { ...point, startX: point.x, startY: point.y });
        e.currentTarget.setPointerCapture(e.pointerId);
        if (this.mapPointers.size === 1) this.mapGestureMoved = false;
        if (this.mapPointers.size > 1) this.mapGestureMoved = true;
    }

    mapPointerMove(e) {
        const previous = this.mapPointers.get(e.pointerId);
        if (!previous) return;
        const before = [...this.mapPointers.values()];
        const point = this.mapPointerPosition(e);
        this.mapPointers.set(e.pointerId, { ...point, startX: previous.startX, startY: previous.startY });
        const after = [...this.mapPointers.values()];
        const { viewport, image } = this.mapGeometry();
        if (after.length >= 2) {
            this.mapCamera = pinchMapCamera(this.mapCamera, before, after, viewport, image);
            this.mapGestureMoved = true;
        } else {
            if (Math.hypot(point.x - previous.startX, point.y - previous.startY) > 6) this.mapGestureMoved = true;
            if (this.mapGestureMoved) this.mapCamera = panMapCamera(this.mapCamera, point.x - previous.x, point.y - previous.y, viewport, image);
        }
        if (this.mapGestureMoved) e.currentTarget.classList.add('is-dragging');
        $('worldmap-zoom-label').textContent = `${Math.round(this.mapCamera.zoom * 100)}%`;
    }

    mapPointerUp(e) {
        if (!this.mapPointers.has(e.pointerId)) return;
        this.mapPointers.delete(e.pointerId);
        if (this.mapGestureMoved) this.mapIgnoreClickUntil = performance.now() + 500;
        if (!this.mapPointers.size) {
            this.mapGestureMoved = false;
            e.currentTarget.classList.remove('is-dragging');
        }
    }

    renderMapDestinations() {
        const search = $('worldmap-search').value.trim().toLocaleLowerCase();
        const list = $('worldmap-list');
        list.replaceChildren();
        for (const button of document.querySelectorAll('.worldmap-filter')) {
            button.setAttribute('aria-pressed', String(button.dataset.kind === this.mapKind));
        }
        let shown = 0;
        for (const destination of this.mapDestinations) {
            if (this.mapKind !== 'all' && destination.kind !== this.mapKind) continue;
            if (search && !destination.name.toLocaleLowerCase().includes(search)) continue;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'worldmap-destination';
            button.textContent = destination.name;
            button.dataset.destinationId = destination.id;
            button.setAttribute('aria-current', String(destination.id === this.mapSelectedId));
            button.addEventListener('click', () => this.selectMapDestination(destination.id));
            list.append(button);
            shown++;
        }
        if (!shown) {
            const empty = document.createElement('p');
            empty.textContent = '行き先がありません';
            list.append(empty);
        }
        const selected = this.mapDestinations.find((d) => d.id === this.mapSelectedId);
        $('worldmap-selected').textContent = selected ? `行き先：${selected.name}` : '行き先を選択してください';
        $('worldmap-travel').disabled = !selected;
    }

    selectMapDestination(id, reveal = false) {
        if (!this.mapDestinations.some((destination) => destination.id === id)) return;
        this.mapSelectedId = id;
        if (reveal) {
            this.mapKind = 'all';
            $('worldmap-search').value = '';
        }
        this.renderMapDestinations();
        const button = $('worldmap-list').querySelector(`[data-destination-id="${id}"]`);
        if (reveal) button?.scrollIntoView({ block: 'nearest' });
        else button?.focus({ preventScroll: true });
    }

    mapView() {
        if (this.player.plane === 'sky') {
            this.skyMap ??= buildSkyMapImage(this.gen);
            return { plane: 'sky', base: this.skyMap, tilesPerPx: 16, originTx: this.gen.skyX0, originTy: this.gen.skyY0, waysides: this.gen.waysides, waystones: this.waystones, selectedDestinationId: this.mapSelectedId, camera: this.mapCamera };
        }
        // 大陸の一枚絵（マクロ地図と同じ座標）があればそれを下地にする
        const illustrated = this.worldMapImage?.complete && this.worldMapImage.naturalWidth > 0;
        this.landMap ??= buildLandMapImage(this.gen.macro);
        return {
            plane: 'land',
            base: illustrated ? this.worldMapImage : this.landMap,
            baseSize: [this.gen.macro.width, this.gen.macro.height],
            illustrated,
            tilesPerPx: def.map_scale,
            originTx: 0,
            originTy: 0,
            skyHint: [530 * def.map_scale, 282 * def.map_scale],
            waysides: this.gen.waysides,
            waystones: this.waystones,
            selectedDestinationId: this.mapSelectedId,
            camera: this.mapCamera,
        };
    }

    drawMap(t) {
        // 開いた直後はまだ大きさが決まっていないことがあるので、描くたびに合わせる
        const canvas = $('worldmap-canvas');
        if (Math.abs(canvas.width - canvas.clientWidth) > 1 || Math.abs(canvas.height - canvas.clientHeight) > 1) {
            canvas.width = canvas.clientWidth;
            canvas.height = canvas.clientHeight;
        }
        this.mapHits = drawWorldMap(canvas, this.gen, this.world, this.mapView(), this.player, t);
    }

    mapClick(e) {
        if (!this.mapOpen || performance.now() < this.mapIgnoreClickUntil) return;
        const { x, y } = this.mapPointerPosition(e);
        const hit = this.mapHits
            .map((h) => ({ ...h, distance: Math.hypot(h.x - x, h.y - y) }))
            .filter((h) => h.x >= 0 && h.x <= e.currentTarget.width && h.y >= 0 && h.y <= e.currentTarget.height && h.distance < 18)
            .sort((a, b) => a.distance - b.distance)[0];
        if (!hit) return;
        if (!hit.unlocked) return this.toast(`${hit.name}へは、まだ行けません。`);
        this.selectMapDestination(hit.destinationId, true);
    }

    travelToMapDestination() {
        const destination = this.mapDestinations.find((d) => d.id === this.mapSelectedId);
        if (!destination || destination.plane !== this.player.plane) return;
        if (this.spectator) {
            this.toggleMap(false);
            this.warpTo(destination.plane, destination.x, destination.y);
            this.toast(`${destination.name}へ移動しました。`);
            return;
        }
        this.confirm('転移の翼', `${destination.name}へ飛びますか？`, '飛ぶ', async () => {
            this.closeDialog();
            this.toggleMap(false);
            this.positionActionPending = true;
            this.paused = true;
            $('fade').classList.add('is-on');
            try {
                await this.presence.pending;
                const [x, y] = this.world.nearestOpen(destination.x, destination.y);
                if (!this.world.canOccupy(x, y, x, y)) {
                    this.toast('行き先に降り立てる場所がありません。');
                    return;
                }
                this.warpTo(destination.plane, x, y);
                const saved = await this.sync();
                this.toast(saved ? `${destination.name}に降り立った。` : '移動先の保存を確認できません。通信が戻ると再試行します。');
            } catch {
                this.toast('移動できませんでした。もう一度お試しください。');
            } finally {
                this.positionActionPending = false;
                this.paused = false;
                $('fade').classList.remove('is-on');
            }
        });
    }

    clampSpectatorPosition() {
        const p = this.player;
        let [x0, y0, x1, y1] = [0, 0, this.gen.worldW * TILE, this.gen.worldH * TILE];
        if (p.plane === 'sky') {
            [x0, y0, x1, y1] = [
                this.gen.skyX0 * TILE,
                this.gen.skyY0 * TILE,
                (this.gen.skyX0 + this.gen.skyW) * TILE,
                (this.gen.skyY0 + this.gen.skyH) * TILE,
            ];
        }
        p.x = Math.max(x0 + 1, Math.min(x1 - 1, p.x));
        p.y = Math.max(y0 + 1, Math.min(y1 - 1, p.y));
        this.spectatorPositions[p.plane] = { x: p.x, y: p.y };
    }

    toggleSpectatorPlane() {
        if (!this.spectator) return;
        const plane = this.player.plane === 'land' ? 'sky' : 'land';
        const saved = this.spectatorPositions[plane];
        const fallback = plane === 'sky'
            ? { x: def.sky.center[0] * TILE + TILE / 2, y: def.sky.center[1] * TILE + TILE / 2 }
            : { x: boot.player.position.x, y: boot.player.position.y };
        this.warpTo(plane, saved?.x ?? fallback.x, saved?.y ?? fallback.y);
        this.toast(plane === 'sky' ? '天空を観察します。' : '地上を観察します。');
    }

    updateSpectatorPlaneButton() {
        const button = $('btn-spectator-plane');
        if (button) button.textContent = this.player.plane === 'land' ? '天空へ' : '地上へ';
    }

    toggleSpectatorRoster(open) {
        const panel = $('spectator-roster');
        if (!panel) return;
        panel.hidden = !open;
    }

    updateSpectatorRoster(data) {
        const players = Array.isArray(data.all_players) ? data.all_players : [];
        const summary = data.summary ?? {};
        $('spectator-count').textContent = String(summary.total ?? players.length);
        $('spectator-summary').textContent = `滞在 ${summary.total ?? players.length}人（地上 ${summary.land ?? 0} / 天空 ${summary.sky ?? 0}）`;
        const list = $('spectator-list');
        list.replaceChildren();
        if (!players.length) {
            const empty = document.createElement('p');
            empty.textContent = '現在フィールドに滞在している冒険者はいません。';
            list.append(empty);
            return;
        }
        for (const player of players) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'spectator-player';
            const icon = document.createElement('img');
            icon.src = player.icon;
            icon.alt = '';
            icon.loading = 'lazy';
            const description = document.createElement('span');
            const name = document.createElement('strong');
            name.textContent = `${player.name}　Lv${player.level}`;
            const location = document.createElement('small');
            const place = this.world.placeName(Math.floor(player.x / TILE), Math.floor(player.y / TILE));
            location.textContent = `${player.plane === 'sky' ? '天空' : '地上'}${place ? `・${place}` : ''}`;
            description.append(name, location);
            const moved = document.createElement('time');
            moved.textContent = `${Math.max(0, Number(player.moved_seconds_ago) || 0)}秒前`;
            button.append(icon, description, moved);
            button.addEventListener('click', () => {
                this.warpTo(player.plane, player.x, player.y);
                this.toggleSpectatorRoster(false);
                this.toast(`${player.name}の位置へ移動しました。`);
            });
            list.append(button);
        }
    }

    // ---- 画面の部品 ----------------------------------------------------------------------------

    confirm(title, body, ok, onOk) {
        this.clearMoveTarget();
        $('dialog-title').textContent = title;
        $('dialog-body').textContent = body;
        const cancel = $('dialog-cancel');
        const btn = $('dialog-ok');
        cancel.textContent = 'やめる';
        btn.hidden = false;
        btn.textContent = ok;
        btn.onclick = onOk;
        $('dialog').hidden = false;
        this.paused = true;
        btn.focus();
    }

    notice(title, body) {
        this.clearMoveTarget();
        $('dialog-title').textContent = title;
        $('dialog-body').textContent = body;
        const cancel = $('dialog-cancel');
        const btn = $('dialog-ok');
        cancel.textContent = '閉じる';
        btn.hidden = true;
        btn.onclick = null;
        $('dialog').hidden = false;
        this.paused = true;
        cancel.focus();
    }

    closeDialog() {
        $('dialog').hidden = true;
        this.paused = this.mapOpen;
    }

    toast(text) {
        const el = $('toast');
        el.textContent = text;
        el.hidden = false;
        clearTimeout(this.toastTimer);
        this.toastTimer = setTimeout(() => {
            el.hidden = true;
        }, 3800);
    }
}

async function main() {
    try {
        const macro = await MacroMap.load(def.macro.url);
        $('loading-text').textContent = '大陸を形づくっています…';
        await new Promise((r) => setTimeout(r, 0));
        const gen = new FieldGenerator(def, macro);
        const world = new FieldWorld(gen, def, boot.player);
        const renderer = new FieldRenderer($('field-canvas'), world);
        renderer.loadImages(boot.assetKeys ?? [], boot.urls.assets, boot.assetVersions ?? {});
        renderer.loadTerrainAtlases(boot.terrainAtlases ?? {});
        const game = new FieldGame(world, renderer, gen);
        $('loading').hidden = true;
        game.start();
        for (const msg of boot.flash ?? []) game.toast(msg);
        window.__field = game;
    } catch (e) {
        console.error(e);
        $('loading-text').textContent = 'フィールドの読み込みに失敗しました。再読み込みしてください。';
    }
}

main();

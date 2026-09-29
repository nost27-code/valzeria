// 街と街道のにぎわい：街の住人・衛兵、街道を行く旅人と荷馬車、蝶・鳥・雪・火の粉などの空気感。
// 報酬や当たり判定には関わらない（見た目と、話しかけた時のひとことだけ）。

import { TILE } from './constants.js';
import { hashStr, mulberry } from './noise.js';
import { drawAdventurer } from './sprites.js';

// 街並みごとの住人と、話しかけた時のひとこと
const TOWNSFOLK = {
    common: [
        '宿屋で休めば、体力も元どおりさ。',
        '街の外には魔物がうろついている。近づくと追いかけてくるから気をつけな。',
        '地図（M）を見れば、行ったことのある街へ転移の翼で飛べるらしい。',
        '宝箱や薬草は、日がかわるとまた見つかるそうだよ。',
        'ダンジョンの入口のまわりには、その奥に棲む魔物が出てくるんだ。',
    ],
    royal_capital: ['ようこそ王都アークレアへ！ 冒険者はいつでも歓迎だ。', '王様は、北の魔王城のうわさに心を痛めておられる。', '南の草原なら、駆け出しの冒険者でも安心さ。', '城の庭園の噴水は、恋人たちの待ち合わせ場所なのよ。'],
    port: ['潮の香りがするだろう？ マリネスの魚は大陸一さ。', '沖の難破船には、幽霊船長が出るってうわさだ。', '灯台の灯りは、どんな嵐の夜も消えたことがない。', '北の岩山には、グランベルグの鍛冶師たちが住んでいる。'],
    tree_city: ['世界樹さまの声が聞こえる……？ あなたにも精霊の加護がありますように。', '夜になると、根のあいだで精霊たちが踊るのよ。', '森の奥の月光庭園は、とても美しいところだそうです。'],
    forge: ['カン、カン！ 鍛冶の音はこの街の子守唄さ。', '溶鉱炉の火は、もう三百年消えていないんだ。', '廃坑の奥には、黒いドワーフが住みついたらしい。', 'いい鉱石が手に入ったら、鍛冶屋に持っていくといい。'],
    snow: ['寒いだろう。温かいスープでも飲んでいきな。', '氷竜の巣には近づかないほうがいい……。', '大聖堂の氷は、夏でも溶けないんだ。'],
    oasis: ['砂漠を越えてきたのかい？ オアシスの水で喉をうるおしな。', '王家の墓には、ファラオの呪いがあるというよ。', '流砂に足をとられたら、あわてずゆっくり抜け出すんだ。'],
    academy: ['魔導の研究は、星の巡りを読むことから始まるのです。', '禁書庫には近づいてはいけません。本に食べられますよ。', '東の岬の転移塔は、天空へ通じていると言われています。'],
    necro: ['……生きた人間が来るのは、めずらしいねえ。', '夜の墓地は、にぎやかなんだよ。死者たちでね。', '南の瘴気の谷は、息をするだけで体が重くなる。'],
    sky_temple: ['ここは雲の上。地上の喧騒も届きません。', '神々の祭壇では、最後の試練が待っているそうです。', '天使の庭園の花は、地上では決して咲かないのです。'],
    demon_castle: ['ここは魔王城の目の前だ。前線基地で準備を整えていけ。', '山頂の城から、ずっと唸り声が聞こえている……。', '仲間たちの多くが、あの城から帰らなかった。'],
};

// 街道沿いの寄り道の人々
const WAYSIDE = {
    hamlet: ['こんな田舎まで、よく来たねえ。', '畑の作物は、街の市場へ運んで売るんだよ。', '夜になると、村の外で魔物の声がするんだ。', '旅の祠にお祈りしていくといい。道に迷わなくなるよ。'],
    inn: ['宿場町へようこそ！ 街と街のちょうど中間さ。', '馬を休ませたら、また出発だ。', 'この先の道しるべで、次の街までの道のりが分かるよ。', '行商人の荷は、遠い街の珍しい物ばかりさ。'],
    rest_stop: ['ここで一休みしていきな。焚き火は暖かいぞ。', '次の街まではまだ遠い。水は持ったか？', '行商人「いい品があるよ……と言いたいが、今日はもう売り切れさ。」'],
    windmill: ['風があるうちに、粉をひいてしまわないとね。', 'このあたりの麦は、王都のパン屋にも卸しているんだ。'],
    watchtower: ['異常なし！ ……と言いたいところだが、最近は魔物が多い。', '塔の上からは、ずっと遠くの街まで見えるんだ。', '街道を外れる時は、魔物の縄張りに気をつけろ。'],
    shrine: ['旅の無事を祈って、花を供えました。', 'この祠は、昔の旅人が建てたそうですよ。'],
};

const NPC_SHEET_FALLBACK = { soldier: 'npc_guard', merchant: 'npc_merchant', guard: 'npc_guard' };

const GUARD_LINES = ['異常なし！', '街の中では魔物は出ない。安心するといい。', '夜は門の外を見回っている。'];
const TRAVELER_LINES = [
    '次の街まで、まだ遠いなあ。',
    '街道を外れると、魔物の縄張りだ。気をつけて。',
    'この先の橋を渡ると、景色ががらりと変わるよ。',
    '荷を運ぶ仕事は楽じゃないが、旅は楽しいもんさ。',
    '北の火山のふもとには、魔王の結界があるらしい。',
];

// 住人の姿（髪・服の色を決める番号）
const ROLE_LOOK = { guard: 6, merchant: 4, villager: 0, scholar: 3, smith: 7, sailor: 5, pilgrim: 2, soldier: 1, ghost: 6 };

export class FieldAmbient {
    constructor(world, gen) {
        this.world = world;
        this.gen = gen;
        this.townsfolk = new Map(); // cityId → list
        this.travelers = [];
        this.particles = [];
        this.particleKind = null;
    }

    // ---- 街の住人 ----------------------------------------------------------------------------

    populate(layout) {
        const rand = mulberry(hashStr(`folk:${layout.city.key}`));
        const style = layout.city.style;
        const minor = !!layout.city.minor;
        const lines = minor ? [...(WAYSIDE[layout.city.kind] ?? []), ...TOWNSFOLK.common] : [...(TOWNSFOLK[style] ?? []), ...TOWNSFOLK.common];
        const people = [];
        const count = minor
            ? ({ hamlet: 5, inn: 9, rest_stop: 4, windmill: 2, watchtower: 3, shrine: 1 }[layout.city.kind] ?? 0)
            : Math.round((layout.w * layout.h) / 900);
        const extraRole = minor
            ? ({ watchtower: 'soldier', rest_stop: 'merchant', shrine: 'pilgrim' }[layout.city.kind])
            : { academy: 'scholar', forge: 'smith', port: 'sailor', sky_temple: 'pilgrim', demon_castle: 'soldier', necro: 'ghost' }[style];
        for (let i = 0; i < count * 6 && people.length < count; i++) {
            const lx = Math.floor(rand() * layout.w);
            const ly = Math.floor(rand() * layout.h);
            const tile = layout.grid[ly * layout.w + lx];
            if (!tile || layout.solid[ly * layout.w + lx] || !'spojoqeNDVKGm'.includes(tile)) continue;
            const role = rand() < 0.12 ? 'merchant' : extraRole && rand() < 0.3 ? extraRole : 'villager';
            people.push(this.person(layout, lx, ly, role, lines[Math.floor(rand() * lines.length)], people.length));
        }
        // 門の衛兵（門の内側の両脇に立つ。小さな集落は宿場町だけ）
        for (const g of minor && layout.city.kind !== 'inn' ? [] : layout.gates) {
            const inward = { n: [0, 5], s: [0, -5], w: [5, 0], e: [-5, 0] }[g.dir];
            for (const side of [-4, 4]) {
                const vertical = g.dir === 'n' || g.dir === 's';
                const lx = g.x + inward[0] + (vertical ? side : 0);
                const ly = g.y + inward[1] + (vertical ? 0 : side);
                const p = this.person(layout, lx, ly, 'guard', GUARD_LINES[Math.floor(rand() * GUARD_LINES.length)], people.length);
                p.still = true;
                people.push(p);
            }
        }
        return people;
    }

    person(layout, lx, ly, role, line, n) {
        const x = (layout.tx + lx + 0.5) * TILE;
        const y = (layout.ty + ly + 0.5) * TILE;
        return {
            id: hashStr(`${layout.city.key}:${n}`) % 97 + (ROLE_LOOK[role] ?? 0),
            role,
            name: { guard: '衛兵', merchant: '商人', scholar: '学徒', smith: '鍛冶師', sailor: '船乗り', pilgrim: '巡礼者', soldier: '兵士', ghost: '???', villager: '町の人' }[role],
            line,
            x,
            y,
            homeX: x,
            homeY: y,
            facing: 0,
            dir: { x: 0, y: 0 },
            turnIn: 0,
            moving: false,
            ghost: role === 'ghost',
        };
    }

    // ---- 街道の旅人 --------------------------------------------------------------------------

    buildTravelers() {
        const list = [];
        for (const road of this.gen.roads) {
            if (road.kind === 'spur' || road.restricted) continue;
            const pts = road.points;
            let length = 0;
            const cum = [0];
            for (let i = 1; i < pts.length; i++) {
                length += Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]);
                cum.push(length);
            }
            const n = Math.max(2, Math.round(length / 900));
            for (let i = 0; i < n; i++) {
                const seed = hashStr(`${road.key}:${i}`);
                list.push({
                    road,
                    pts,
                    cum,
                    length,
                    offset: (seed % 1000) / 1000,
                    speed: 1.6 + (seed % 7) * 0.15, // マス/秒
                    backward: seed % 2 === 0,
                    cart: seed % 3 === 0,
                    id: seed % 200,
                    name: seed % 3 === 0 ? '行商人' : '旅人',
                    line: TRAVELER_LINES[seed % TRAVELER_LINES.length],
                    x: 0,
                    y: 0,
                    facing: 0,
                    moving: true,
                });
            }
        }
        this.travelers = list;
    }

    placeTraveler(tr, time) {
        let s = ((tr.offset + (time * tr.speed) / tr.length) % 1) * tr.length;
        if (tr.backward) s = tr.length - s;
        let i = 1;
        while (i < tr.cum.length - 1 && tr.cum[i] < s) i++;
        const [ax, ay] = tr.pts[i - 1];
        const [bx, by] = tr.pts[i];
        const seg = tr.cum[i] - tr.cum[i - 1] || 1;
        const k = (s - tr.cum[i - 1]) / seg;
        const px = (ax + (bx - ax) * k + 0.5) * TILE;
        const py = (ay + (by - ay) * k + 0.5) * TILE;
        const dx = (bx - ax) * (tr.backward ? -1 : 1);
        const dy = (by - ay) * (tr.backward ? -1 : 1);
        tr.facing = Math.abs(dx) > Math.abs(dy) ? (dx < 0 ? 1 : 2) : dy < 0 ? 3 : 0;
        tr.x = px + (tr.backward ? 10 : -10);
        tr.y = py;
    }

    // ---- 更新 ------------------------------------------------------------------------------

    update(dt, player, time) {
        const ptx = player.x / TILE;
        const pty = player.y / TILE;
        // 近くの都市にだけ住人を置く
        for (const layout of this.gen.cities) {
            const near = layout.plane === player.plane
                && ptx > layout.tx - 60 && ptx < layout.tx + layout.w + 60 && pty > layout.ty - 60 && pty < layout.ty + layout.h + 60;
            if (near && !this.townsfolk.has(layout.city.id)) this.townsfolk.set(layout.city.id, this.populate(layout));
            if (!near) this.townsfolk.delete(layout.city.id);
        }
        for (const people of this.townsfolk.values()) for (const p of people) this.walk(p, dt, player);

        if (!this.travelers.length) this.buildTravelers();
        for (const tr of this.travelers) {
            if (tr.road.plane !== player.plane) continue;
            this.placeTraveler(tr, time);
        }

        this.updateParticles(dt, player, time);
    }

    walk(p, dt, player) {
        if (p.still) {
            const dx = player.x - p.x;
            const dy = player.y - p.y;
            if (Math.hypot(dx, dy) < TILE * 3) p.facing = Math.abs(dx) > Math.abs(dy) ? (dx < 0 ? 1 : 2) : dy < 0 ? 3 : 0;
            return;
        }
        p.turnIn -= dt;
        if (p.turnIn <= 0) {
            p.turnIn = 1.5 + Math.random() * 3;
            const back = Math.hypot(p.x - p.homeX, p.y - p.homeY) > TILE * 8;
            if (back) {
                const d = Math.hypot(p.homeX - p.x, p.homeY - p.y) || 1;
                p.dir = { x: (p.homeX - p.x) / d, y: (p.homeY - p.y) / d };
            } else if (Math.random() < 0.4) {
                p.dir = { x: 0, y: 0 };
            } else {
                const a = [0, Math.PI / 2, Math.PI, -Math.PI / 2][Math.floor(Math.random() * 4)];
                p.dir = { x: Math.round(Math.cos(a)), y: Math.round(Math.sin(a)) };
            }
        }
        // 話しかけられている間は立ち止まる
        if (Math.hypot(player.x - p.x, player.y - p.y) < TILE * 1.5) {
            p.moving = false;
            return;
        }
        const speed = 1.8 * TILE;
        const nx = p.x + p.dir.x * speed * dt;
        const ny = p.y + p.dir.y * speed * dt;
        if (p.dir.x && this.world.canOccupy(nx, p.y, p.x, p.y, 16, 10)) p.x = nx;
        else if (p.dir.x) p.turnIn = 0;
        if (p.dir.y && this.world.canOccupy(p.x, ny, p.x, p.y, 16, 10)) p.y = ny;
        else if (p.dir.y) p.turnIn = 0;
        p.moving = p.dir.x !== 0 || p.dir.y !== 0;
        if (p.moving) p.facing = Math.abs(p.dir.x) > Math.abs(p.dir.y) ? (p.dir.x < 0 ? 1 : 2) : p.dir.y < 0 ? 3 : 0;
    }

    // 話しかけられる人（目の前 1.6 マス以内）
    talkable(player) {
        let best = null;
        const consider = (p) => {
            const d = Math.hypot(p.x - player.x, p.y - player.y);
            if (d < TILE * 1.6 && (!best || d < best.d)) best = { p, d };
        };
        for (const people of this.townsfolk.values()) people.forEach(consider);
        for (const tr of this.travelers) if (tr.road.plane === player.plane) consider(tr);
        return best?.p ?? null;
    }

    // ---- 空気感 ------------------------------------------------------------------------------

    updateParticles(dt, player, time) {
        const tile = this.world.tileAt(Math.floor(player.x / TILE), Math.floor(player.y / TILE));
        let kind = 'butterfly';
        if ('NnIJ'.includes(tile)) kind = 'snow';
        else if ('VvXYc'.includes(tile)) kind = 'ember';
        else if ('Ddd'.includes(tile)) kind = 'dust';
        else if ('KZj'.includes(tile)) kind = 'wisp';
        else if (tile === 'F' || tile === 'U') kind = 'firefly';
        else if (player.plane === 'sky') kind = 'feather';
        if (kind !== this.particleKind) {
            this.particleKind = kind;
            this.particles = [];
        }
        const want = { snow: 70, ember: 40, dust: 30, wisp: 20, firefly: 26, butterfly: 10, feather: 16 }[kind];
        while (this.particles.length < want) {
            this.particles.push({
                x: player.x + (Math.random() - 0.5) * 1400,
                y: player.y + (Math.random() - 0.5) * 900,
                v: Math.random(),
                p: Math.random() * 10,
            });
        }
        for (const q of this.particles) {
            q.p += dt;
            switch (kind) {
                case 'snow': q.y += (30 + q.v * 40) * dt; q.x += Math.sin(q.p + q.v * 6) * 12 * dt; break;
                case 'ember': q.y -= (25 + q.v * 30) * dt; q.x += Math.sin(q.p * 2) * 10 * dt; break;
                case 'dust': q.x += (60 + q.v * 80) * dt; q.y += Math.sin(q.p) * 8 * dt; break;
                case 'feather': q.y += (12 + q.v * 10) * dt; q.x += Math.sin(q.p * 0.8) * 22 * dt; break;
                default: q.x += Math.sin(q.p * (1 + q.v)) * 30 * dt; q.y += Math.cos(q.p * 1.3) * 20 * dt;
            }
            // 画面から大きく離れたら反対側から入れ直す
            if (Math.abs(q.x - player.x) > 760) q.x = player.x - Math.sign(q.x - player.x) * 740;
            if (Math.abs(q.y - player.y) > 500) q.y = player.y - Math.sign(q.y - player.y) * 480;
        }
    }

    drawParticles(ctx, t) {
        const kind = this.particleKind;
        for (const q of this.particles) {
            switch (kind) {
                case 'snow':
                    ctx.fillStyle = 'rgba(255,255,255,0.85)';
                    ctx.fillRect(q.x, q.y, 3, 3);
                    break;
                case 'ember':
                    ctx.fillStyle = `rgba(255,${120 + q.v * 100},40,${0.5 + 0.5 * Math.sin(t * 6 + q.p)})`;
                    ctx.fillRect(q.x, q.y, 3, 3);
                    break;
                case 'dust':
                    ctx.fillStyle = 'rgba(230,200,140,0.35)';
                    ctx.fillRect(q.x, q.y, 6, 2);
                    break;
                case 'wisp':
                case 'firefly': {
                    const a = 0.3 + 0.7 * Math.abs(Math.sin(t * 2 + q.p));
                    ctx.fillStyle = kind === 'wisp' ? `rgba(180,140,255,${a * 0.7})` : `rgba(200,255,140,${a})`;
                    ctx.beginPath();
                    ctx.arc(q.x, q.y, kind === 'wisp' ? 4 : 2.5, 0, Math.PI * 2);
                    ctx.fill();
                    break;
                }
                case 'feather':
                    ctx.fillStyle = 'rgba(255,255,255,0.9)';
                    ctx.beginPath();
                    ctx.ellipse(q.x, q.y, 5, 2, Math.sin(q.p) * 0.8, 0, Math.PI * 2);
                    ctx.fill();
                    break;
                default: {
                    const flap = Math.abs(Math.sin(t * 14 + q.p)) * 4 + 1;
                    ctx.fillStyle = ['#f4e36a', '#f39ac0', '#ffffff', '#9ad8ff'][Math.floor(q.v * 4)];
                    ctx.fillRect(q.x - flap, q.y, flap, 3);
                    ctx.fillRect(q.x + 1, q.y, flap, 3);
                }
            }
        }
    }

    // 奥行き順に描く人々
    sprites(player, t) {
        const out = [];
        for (const people of this.townsfolk.values()) {
            for (const p of people) {
                const near = Math.hypot(p.x - player.x, p.y - player.y) < TILE * 4;
                out.push({ y: p.y, draw: (ctx, images) => this.drawPerson(ctx, p, t, near, images) });
            }
        }
        for (const tr of this.travelers) {
            if (tr.road.plane !== player.plane || Math.abs(tr.x - player.x) > 1600 || Math.abs(tr.y - player.y) > 1200) continue;
            const near = Math.hypot(tr.x - player.x, tr.y - player.y) < TILE * 4;
            out.push({ y: tr.y, draw: (ctx) => this.drawTraveler(ctx, tr, t, near) });
        }
        return out;
    }

    drawPerson(ctx, p, t, near, images) {
        ctx.save();
        if (p.ghost) ctx.globalAlpha = 0.6;
        // 役割専用の絵が無ければ、近い住人の絵で代用する（学徒・鍛冶師などは町の人、兵士は衛兵）
        const own = `npc_${p.role}`;
        const sheet = images?.has?.(own) ? own : NPC_SHEET_FALLBACK[p.role] ?? 'npc_villager';
        const usedSheet = drawAdventurer(ctx, { ...p, name: near ? p.name : '' }, t, false, images, sheet);
        ctx.restore();
        // 専用シートには兜と槍が含まれる。欠落時だけ従来の装飾を足す。
        if (p.role === 'guard' && !usedSheet) {
            ctx.fillStyle = '#9aa0a8';
            ctx.fillRect(p.x - 8, p.y - 44, 16, 6);
            ctx.fillStyle = '#8a8a8a';
            ctx.fillRect(p.x + 12, p.y - 50, 2, 50);
        }
    }

    drawTraveler(ctx, tr, t, near) {
        if (tr.cart) {
            const w = tr.facing === 1 || tr.facing === 2 ? 44 : 28;
            const cx = tr.x + (tr.facing === 1 ? 26 : tr.facing === 2 ? -26 : 0);
            const cy = tr.y + (tr.facing === 3 ? 26 : tr.facing === 0 ? -26 : 0);
            ctx.fillStyle = 'rgba(0,0,0,0.25)';
            ctx.fillRect(cx - w / 2, cy + 2, w, 6);
            ctx.fillStyle = '#8a6034';
            ctx.fillRect(cx - w / 2, cy - 18, w, 18);
            ctx.fillStyle = '#e8dcc0';
            ctx.beginPath();
            ctx.ellipse(cx, cy - 20, w / 2, 12, 0, Math.PI, 0);
            ctx.fill();
            ctx.fillStyle = '#3a2a1a';
            ctx.beginPath();
            ctx.arc(cx - w / 3, cy, 5, 0, Math.PI * 2);
            ctx.arc(cx + w / 3, cy, 5, 0, Math.PI * 2);
            ctx.fill();
        }
        drawAdventurer(ctx, { ...tr, name: near ? tr.name : '' }, t, false);
    }
}

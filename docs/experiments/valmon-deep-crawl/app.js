(function () {
    "use strict";

    const config = window.ValmonDeepConfig;
    const storageKey = "valmonDeepCrawlExperiment:v1";
    const screens = ["prepare", "departure", "run", "ranking", "status"];
    const statKeys = ["attack", "defense", "detect", "evasion"];
    const staminaMax = 265;
    const deckSlotDefault = config.deck?.initialSlotLimit || 20;

    const $ = (id) => document.getElementById(id);
    const fmt = (value) => Number(value || 0).toLocaleString("ja-JP");
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
    const nowIso = () => new Date().toISOString();

    let state = loadState();
    let currentScreen = "prepare";
    let isAdvancing = false;

    function defaultState() {
        const season = currentSeason();
        const selectedValmonId = config.valmons[0].id;
        return {
            selectedValmonId,
            draftValmonId: selectedValmonId,
            settings: Object.fromEntries(config.valmons.map((valmon) => [
                String(valmon.id),
                {
                    tuning: defaultTuningFor(valmon),
                    orbCodes: [],
                },
            ])),
            season,
            tower: defaultTowerState(season),
            activeRun: null,
            lastResult: null,
            stamina: { current: staminaMax, max: staminaMax },
            rankings: {},
        };
    }

    function loadState() {
        try {
            const raw = localStorage.getItem(storageKey);
            if (raw) {
                const parsed = JSON.parse(raw);
                return migrateState(parsed);
            }
        } catch (error) {
            console.warn("failed to load experiment state", error);
        }
        return defaultState();
    }

    function migrateState(source) {
        const base = defaultState();
        const merged = { ...base, ...source };
        merged.season = currentSeason();
        merged.tower = normalizeTowerState(source.tower, merged.season);
        merged.settings = { ...base.settings, ...(source.settings || {}) };
        merged.rankings = source.rankings || {};
        merged.stamina = {
            current: clamp(Number(source.stamina?.current ?? base.stamina.current), 0, staminaMax),
            max: staminaMax,
        };
        if (!config.valmons.some((valmon) => valmon.id === Number(merged.selectedValmonId))) {
            merged.selectedValmonId = base.selectedValmonId;
        }
        if (!config.valmons.some((valmon) => valmon.id === Number(merged.draftValmonId))) {
            merged.draftValmonId = merged.selectedValmonId;
        }
        merged.selectedValmonId = merged.draftValmonId;
        if (merged.activeRun) {
            normalizeRunEvents(merged.activeRun);
        }
        if (merged.lastResult) {
            normalizeRunEvents(merged.lastResult);
        }
        return merged;
    }

    function defaultTowerState(season) {
        const activeCodes = activeSeasonCardCodes(season);
        return {
            seasonCode: season.code,
            ownedCardCodes: [],
            deckCardCodes: [],
            dormantCardCodes: dormantSeasonCardCodes(season),
            claimedCoinCount: 0,
            usedCoinCount: 0,
            paidAdvancedCoinCount: 0,
            bp: 0,
            spentBp: 0,
            upgrades: { attack: 0, defense: 0, detect: 0, evasion: 0, hp: 0 },
            deckSlotLimit: deckSlotDefault,
            currentFloorToChallenge: 1,
            highestClearedFloor: 0,
            score: 0,
            currentHp: config.baseStats.hp,
            maxHp: config.baseStats.hp,
            attemptCount: 0,
            clearedRewardFloors: [],
            pendingCardChoice: null,
            activeCardCodes: activeCodes,
        };
    }

    function normalizeTowerState(source, season) {
        if (!source || source.seasonCode !== season.code) return defaultTowerState(season);
        const base = defaultTowerState(season);
        const tower = { ...base, ...source };
        tower.upgrades = { ...base.upgrades, ...(source.upgrades || {}) };
        tower.ownedCardCodes = uniqueCodes(source.ownedCardCodes || []).filter(cardByCode);
        tower.deckSlotLimit = clamp(Number(source.deckSlotLimit || deckSlotDefault), deckSlotDefault, config.deck.maxSlotLimit);
        tower.deckCardCodes = uniqueCodes(source.deckCardCodes || source.orbCodes || [])
            .filter((code) => tower.ownedCardCodes.includes(code))
            .slice(0, tower.deckSlotLimit);
        tower.dormantCardCodes = dormantSeasonCardCodes(season);
        tower.activeCardCodes = activeSeasonCardCodes(season);
        tower.bp = Math.max(0, Number(tower.bp || 0));
        tower.spentBp = Math.max(0, Number(tower.spentBp || 0));
        tower.usedCoinCount = clamp(Number(tower.usedCoinCount || 0), 0, config.season.naturalCoinMax);
        tower.claimedCoinCount = clamp(
            Number(source.claimedCoinCount ?? source.usedCoinCount ?? 0),
            tower.usedCoinCount,
            config.season.naturalCoinMax
        );
        tower.currentFloorToChallenge = clamp(Number(tower.currentFloorToChallenge || 1), 1, config.season.maxFloorTarget);
        tower.highestClearedFloor = clamp(Number(tower.highestClearedFloor || 0), 0, config.season.maxFloorTarget);
        tower.score = Math.max(0, Number(tower.score || 0));
        tower.currentHp = clamp(Number(tower.currentHp || tower.maxHp), 1, Number(tower.maxHp || config.baseStats.hp));
        tower.clearedRewardFloors = uniqueCodes(source.clearedRewardFloors || []).map(Number);
        return tower;
    }

    function uniqueCodes(codes) {
        return [...new Set((codes || []).filter(Boolean))];
    }

    function normalizeRunEvents(run) {
        run.events = (run.events || [])
            .filter((event) => event && event.logText)
            .sort((a, b) => String(a.createdAt || "").localeCompare(String(b.createdAt || "")));
        run.activeFloorNumber = run.activeFloorNumber || null;
        run.currentFloorEventIndex = Number(run.currentFloorEventIndex || 0);
        run.currentFloorEventTotal = Number(run.currentFloorEventTotal || 0);
        run.floorEventPlans = run.floorEventPlans || {};
        run.routeChoiceResult = run.routeChoiceResult || null;
        run.tuningChoiceResult = run.tuningChoiceResult || null;
        return run;
    }

    function saveState() {
        localStorage.setItem(storageKey, JSON.stringify(state));
    }

    function currentSeason() {
        const now = new Date();
        const jstNow = new Date(now.getTime() + (9 * 60 * 60 * 1000));
        const day = jstNow.getUTCDay();
        const hour = jstNow.getUTCHours();
        const daysSinceFriday = (day + 2) % 7;
        let startJstDayOffset = jstNow.getUTCDate() - daysSinceFriday;
        if (day === 5 && hour < 9) {
            startJstDayOffset -= 7;
        }
        const start = new Date(Date.UTC(
            jstNow.getUTCFullYear(),
            jstNow.getUTCMonth(),
            startJstDayOffset,
            0,
            0,
            0
        ));
        const end = new Date(start.getTime() + (3 * 24 * 60 * 60 * 1000) - 1);
        const startJst = new Date(start.getTime() + (9 * 60 * 60 * 1000));
        const code = `${startJst.getUTCFullYear()}-${String(startJst.getUTCMonth() + 1).padStart(2, "0")}-${String(startJst.getUTCDate()).padStart(2, "0")}`;
        return {
            code,
            name: config.season.name,
            seed: `valmon_deep_card:${code}`,
            startsAt: start.toISOString(),
            endsAt: end.toISOString(),
        };
    }

    function allCards() {
        const defined = config.cards || config.orbs || [];
        if (defined.length >= 100) return defined.slice(0, 100);
        const categories = ["attack", "defense", "heal", "explore", "evasion", "special"];
        const icons = ["icon_244.webp", "icon_245.webp", "icon_246.webp", "icon_247.webp", "icon_248.webp", "icon_249.webp", "icon_250.webp", "icon_251.webp"];
        const generated = [];
        for (let index = defined.length + 1; index <= 100; index += 1) {
            const category = categories[index % categories.length];
            generated.push({
                code: `season_card_${String(index).padStart(3, "0")}`,
                name: `${categoryLabel(category)}カード ${index}`,
                category,
                rarity: index % 10 === 0 ? "R" : "N",
                icon: icons[index % icons.length],
                description: categoryDescription(category),
                effect: generatedCardEffect(category, index),
                sort: index,
            });
        }
        return [...defined, ...generated];
    }

    function categoryLabel(category) {
        return config.cardCategoryLabels?.[category] || category || "カード";
    }

    function categoryDescription(category) {
        return {
            attack: "与ダメージが少し伸びる",
            defense: "受けるダメージを少し抑える",
            heal: "HP回復の機会が少し強くなる",
            explore: "宝箱やスコアの伸びがよくなる",
            evasion: "罠や黒風を少しかわしやすくなる",
            special: "節目の報酬や深層BPに少し効く",
        }[category] || "今シーズンだけ有効なカード";
    }

    function generatedCardEffect(category, index) {
        return {
            attack: { damageDealtRate: 1 + ((index % 4) + 3) / 100 },
            defense: { damageTakenRate: 1 - ((index % 4) + 3) / 100 },
            heal: { milestoneHealBonus: ((index % 3) + 3) / 100 },
            explore: { eventScoreRate: 1 + ((index % 4) + 4) / 100 },
            evasion: { trapAvoidBonus: ((index % 4) + 3) / 100 },
            special: { bossBpBonus: index % 20 === 0 ? 1 : 0 },
        }[category] || {};
    }

    function cardByCode(code) {
        return allCards().find((card) => card.code === code);
    }

    function activeSeasonCardCodes(season) {
        return seasonCardPool(season).active.map((card) => card.code);
    }

    function dormantSeasonCardCodes(season) {
        return seasonCardPool(season).dormant.map((card) => card.code);
    }

    function seasonCardPool(season) {
        const rng = seededRandom(`${season.seed}:card-pool`);
        const shuffled = allCards().map((card) => ({ card, roll: rng() })).sort((a, b) => a.roll - b.roll).map((row) => row.card);
        const activeCount = config.season.activeCardCount || 80;
        return {
            active: shuffled.slice(0, activeCount),
            dormant: shuffled.slice(activeCount, 100),
        };
    }

    function hashString(text) {
        let hash = 2166136261;
        for (let i = 0; i < text.length; i += 1) {
            hash ^= text.charCodeAt(i);
            hash = Math.imul(hash, 16777619);
        }
        return hash >>> 0;
    }

    function seededRandom(seed) {
        let value = hashString(seed);
        return function () {
            value += 0x6D2B79F5;
            let t = value;
            t = Math.imul(t ^ (t >>> 15), t | 1);
            t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
            return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
        };
    }

    function defaultTuningFor(valmon) {
        const limit = tuningPointLimit(valmon.level);
        const base = { attack: 0, defense: 0, detect: 0, evasion: 0 };
        const order = ["attack", "defense", "detect", "evasion"];
        for (let i = 0; i < limit; i += 1) {
            base[order[i % order.length]] += 1;
        }
        return base;
    }

    function tuningPointLimit(level) {
        return config.tuning.pointsByLevel.reduce((points, rule) => (
            level >= rule.level ? rule.points : points
        ), config.tuning.pointsByLevel[0].points);
    }

    function selectedValmon() {
        return config.valmons.find((valmon) => valmon.id === Number(state.selectedValmonId)) || config.valmons[0];
    }

    function draftValmon() {
        return config.valmons.find((valmon) => valmon.id === Number(state.draftValmonId)) || selectedValmon();
    }

    function settingForValmon(valmon) {
        const key = String(valmon.id);
        if (!state.settings[key]) {
            state.settings[key] = {
                tuning: defaultTuningFor(valmon),
                orbCodes: [],
            };
        }
        return state.settings[key];
    }

    function selectedSetting() {
        return settingForValmon(selectedValmon());
    }

    function draftSetting() {
        return settingForValmon(draftValmon());
    }

    function activeSeasonKey() {
        return state.season.code;
    }

    function showScreen(screen) {
        currentScreen = screen;
        screens.forEach((name) => {
            $(`screen-${name}`).classList.toggle("active", name === screen);
        });
        render();
        scrollPageTop();
    }

    function showAppTab(tab) {
        if (tab === "ranking") {
            showScreen("ranking");
            return;
        }
        if (tab === "status") {
            showScreen("status");
            return;
        }
        showScreen(state.activeRun ? explorationScreenForRun(state.activeRun) : "prepare");
    }

    function scrollPageTop() {
        window.requestAnimationFrame(() => {
            window.scrollTo({ top: 0, behavior: "auto" });
        });
    }

    function displayRun() {
        return state.activeRun || state.lastResult || null;
    }

    function latestPageEvent(run) {
        if (!run?.events?.length) return null;
        return [...run.events].reverse().find((event) => event.eventType !== "log") || null;
    }

    function explorationScreenForRun(run) {
        return latestPageEvent(run) ? "run" : "departure";
    }

    function flash(message, type = "info") {
        const node = $("statusMessage");
        node.textContent = message;
        node.classList.toggle("error", type === "error");
        node.hidden = false;
        window.setTimeout(() => {
            node.hidden = true;
        }, 3600);
    }

    function openRulesModal() {
        $("rulesModal").hidden = false;
    }

    function closeRulesModal() {
        $("rulesModal").hidden = true;
    }

    function openRetireModal(event) {
        if (event) event.preventDefault();
        if (!state.activeRun) {
            flash("撤退できる挑戦がありません。", "error");
            return;
        }
        $("retireModal").hidden = false;
    }

    function closeRetireModal() {
        $("retireModal").hidden = true;
    }

    function openCoinModal() {
        $("coinModal").hidden = false;
    }

    function closeCoinModal() {
        $("coinModal").hidden = true;
    }

    function validateSetting(valmon = selectedValmon()) {
        const setting = settingForValmon(valmon);
        const limit = tuningPointLimit(valmon.level);
        const total = statKeys.reduce((sum, key) => sum + Number(setting.tuning[key] || 0), 0);
        const capOver = statKeys.find((key) => Number(setting.tuning[key] || 0) > config.tuning.statCap);
        if (total < limit) {
            return { ok: false, target: "tuning", message: "調律ポイントが余っています。" };
        }
        if (total > limit) {
            return { ok: false, target: "tuning", message: "調律ポイントが上限を超えています。" };
        }
        if (capOver) {
            return { ok: false, target: "tuning", message: `${config.tuning.labels[capOver]}が項目上限を超えています。` };
        }
        return { ok: true };
    }

    function canStartRun() {
        if (state.activeRun && state.activeRun.status === "active") {
            return { ok: true, action: "resume" };
        }
        const settingValidation = validateSetting(draftValmon());
        if (!settingValidation.ok) {
            return { ok: false, message: settingValidation.message };
        }
        return { ok: true, action: "start" };
    }

    function startRun() {
        const check = canStartRun();
        if (!check.ok) {
            flash(check.message, "error");
            render();
            return;
        }
        if (check.action === "resume") {
            showScreen(explorationScreenForRun(state.activeRun));
            return;
        }

        state.selectedValmonId = state.draftValmonId;
        const valmon = selectedValmon();
        const setting = selectedSetting();
        const tower = state.tower;
        const baseTuning = { ...setting.tuning };
        const deckCodes = tower.deckCardCodes || [];
        const stats = calculateStats(valmon, baseTuning, emptyTuning(), deckCodes, null);
        const run = {
            id: `run_${Date.now()}`,
            characterName: "試験冒険者",
            valmonId: valmon.id,
            valmonName: valmon.name,
            valmonLevel: valmon.level,
            seasonCode: state.season.code,
            mode: "ranking",
            isRanked: true,
            status: "active",
            currentFloor: Math.max(0, Number(tower.currentFloorToChallenge || 1) - 1),
            bestClearedFloor: Number(tower.highestClearedFloor || 0),
            maxHp: stats.maxHp,
            currentHp: clamp(Number(tower.currentHp || stats.maxHp), 1, stats.maxHp),
            baseTuning,
            tempTuning: emptyTuning(),
            equippedOrbCodes: [...deckCodes],
            tempOrbCode: null,
            activeRouteType: "safe",
            routeUntilFloor: 3,
            activeFloorNumber: null,
            currentFloorEventIndex: 0,
            currentFloorEventTotal: 0,
            floorEventPlans: {},
            pendingChoices: [],
            score: 0,
            killCount: 0,
            bossKillCount: 0,
            treasureCount: 0,
            trapAvoidCount: 0,
            blackRouteClearCount: 0,
            restFoundCount: 0,
            startedAt: nowIso(),
            endedAt: null,
            events: [],
        };

        state.activeRun = run;
        tower.attemptCount = Number(tower.attemptCount || 0) + 1;
        addRunLog(run, "酒場裏の小穴へ、ヴァルモンがひとりで潜っていった。");
        saveState();
        flash("挑戦を開始しました。");
        showScreen("departure");
    }

    function cycleDraftValmon(delta) {
        const currentIndex = config.valmons.findIndex((valmon) => valmon.id === Number(state.draftValmonId));
        const safeIndex = currentIndex >= 0 ? currentIndex : 0;
        const nextIndex = (safeIndex + Number(delta) + config.valmons.length) % config.valmons.length;
        state.draftValmonId = config.valmons[nextIndex].id;
        state.selectedValmonId = state.draftValmonId;
        saveState();
        render();
    }

    function toggleOrb(orbCode) {
        const tower = state.tower;
        if (!tower.ownedCardCodes.includes(orbCode)) return;
        const codes = (tower.deckCardCodes || []).filter(Boolean);
        if (codes.includes(orbCode)) {
            tower.deckCardCodes = codes.filter((code) => code !== orbCode);
        } else if (codes.length >= tower.deckSlotLimit) {
            flash(`装備できるカードは${tower.deckSlotLimit}枚までです。`, "error");
            return;
        } else {
            tower.deckCardCodes = [...codes, orbCode];
        }
        saveState();
        render();
    }

    function emptyTuning() {
        return { attack: 0, defense: 0, detect: 0, evasion: 0 };
    }

    function allRunOrbs(run) {
        const codes = [...(run.equippedOrbCodes || [])];
        if (run.tempOrbCode) codes.push(run.tempOrbCode);
        return codes
            .map((code) => cardByCode(code) || config.tempOrbs.find((orb) => orb.code === code))
            .filter(Boolean);
    }

    function orbMultiplier(run, key, defaultValue = 1) {
        return allRunOrbs(run).reduce((value, orb) => {
            const effectValue = orb.effect?.[key];
            return typeof effectValue === "number" ? value * effectValue : value;
        }, defaultValue);
    }

    function orbBonus(run, key) {
        return allRunOrbs(run).reduce((value, orb) => {
            const effectValue = orb.effect?.[key];
            return typeof effectValue === "number" ? value + effectValue : value;
        }, 0);
    }

    function calculateStats(valmon, baseTuning, tempTuning, orbCodes, tempOrbCode) {
        const total = {};
        statKeys.forEach((key) => {
            total[key] = Number(baseTuning[key] || 0) + Number(tempTuning[key] || 0) + Number(state.tower?.upgrades?.[key] || 0);
        });
        const cards = [...(orbCodes || []), tempOrbCode].filter(Boolean).map(cardByCode).filter(Boolean);
        const hpCardBonus = cards.reduce((sum, card) => sum + Number(card.effect?.hpBonus || 0), 0);
        return {
            tuning: total,
            maxHp: config.baseStats.hp + (Number(state.tower?.upgrades?.hp || 0) * 10) + (total.defense * 5) + hpCardBonus,
            atk: config.baseStats.atk + (total.attack * 4),
            def: config.baseStats.def + (total.defense * 3),
            detect: config.baseStats.detect + total.detect,
            evasion: config.baseStats.evasion + total.evasion,
            orbCodes,
            tempOrbCode,
        };
    }

    function runStats(run) {
        const valmon = config.valmons.find((row) => row.id === Number(run.valmonId)) || selectedValmon();
        return calculateStats(valmon, run.baseTuning, run.tempTuning, run.equippedOrbCodes, run.tempOrbCode);
    }

    function departureMessageForRun(run, stats) {
        const name = run.valmonName;
        const tuning = stats.tuning;
        const values = statKeys.map((key) => Number(tuning[key] || 0));
        const maxValue = Math.max(...values);
        const minValue = Math.min(...values);
        const topKeys = statKeys.filter((key) => Number(tuning[key] || 0) === maxValue);
        const hasOrb = (code) => (run.equippedOrbCodes || []).includes(code) || run.tempOrbCode === code;

        if (maxValue >= 2 && maxValue - minValue <= 1) {
            return `${name}は全身の調律をなじませ、落ち着いた足取りで入口を見つめています。`;
        }
        if (tuning.attack >= 4 && tuning.detect >= 4) {
            return `${name}は奥の気配を追いながら、飛び出す相手を迎え撃つ構えです。`;
        }
        if (tuning.defense >= 4 && tuning.evasion >= 4) {
            return `${name}は身を低くして、危ない風を受け流す準備をしています。`;
        }
        if (tuning.detect >= 4 && tuning.evasion >= 4) {
            return `${name}は足音を消し、印や罠の気配を慎重に探っています。`;
        }
        if (tuning.attack >= 4 && tuning.defense >= 4) {
            return `${name}は前へ出る力と踏みとどまる力を確かめています。`;
        }
        if (hasOrb("mouka_orb") && hasOrb("renga_orb")) {
            return `${name}は小さく爪を鳴らし、畳みかける合図を待っています。`;
        }
        if (hasOrb("goshin_orb") && hasOrb("taihi_orb")) {
            return `${name}はカードの導きを背に、無理せず深く潜る姿勢を整えています。`;
        }
        if (hasOrb("tanpou_orb") && hasOrb("sozai_orb")) {
            return `${name}は耳を澄ませ、奥に眠る小さな宝の気配を探しています。`;
        }
        if (hasOrb("tanpou_orb") && hasOrb("taihi_orb")) {
            return `${name}は見つけるべきものと避けるべきものを、静かに見極めています。`;
        }
        if (hasOrb("mouka_orb") && hasOrb("goshin_orb")) {
            return `${name}は攻める瞬間と守る瞬間を、入口の風で測っています。`;
        }
        if (hasOrb("renga_orb") && hasOrb("goshin_orb")) {
            return `${name}は守りを固めたまま、連続で踏み込む間合いを探っています。`;
        }
        if (hasOrb("mouka_orb") && hasOrb("taihi_orb")) {
            return `${name}は一気に踏み込み、危なくなれば退く準備もできています。`;
        }
        if (topKeys.length === 1 && topKeys[0] === "attack") {
            return `${name}は目をきらりと光らせ、最初の一撃に力を集めています。`;
        }
        if (topKeys.length === 1 && topKeys[0] === "defense") {
            return `${name}は胸を張り、暗い地下道の衝撃に備えています。`;
        }
        if (topKeys.length === 1 && topKeys[0] === "detect") {
            return `${name}は耳と鼻をぴんと立て、見えない気配を追っています。`;
        }
        if (topKeys.length === 1 && topKeys[0] === "evasion") {
            return `${name}は軽く跳ね、狭い道でもすぐ動ける姿勢です。`;
        }
        if (topKeys.includes("attack") && topKeys.includes("evasion")) {
            return `${name}は鋭く踏み込み、すぐ身をひるがえせる間合いで待っています。`;
        }
        if (topKeys.includes("defense") && topKeys.includes("detect")) {
            return `${name}は慎重に周囲を確かめながら、堅い姿勢で待機しています。`;
        }
        return `${name}は調律とデッキカードの感触を確かめ、静かに探索の合図を待っています。`;
    }

    function floorEventRange(floorNumber) {
        if (floorNumber <= 10) return [2, 2];
        if (floorNumber <= 20) return [2, 3];
        if (floorNumber <= 30) return [2, 4];
        if (floorNumber <= 40) return [3, 5];
        return [4, 5];
    }

    function plannedEventCountForFloor(run, floorNumber) {
        run.floorEventPlans = run.floorEventPlans || {};
        if (run.floorEventPlans[floorNumber]) return run.floorEventPlans[floorNumber];

        const [min, max] = floorEventRange(floorNumber);
        const rng = seededRandom(`${run.id}:floor-events:${floorNumber}`);
        let count = min + Math.floor(rng() * (max - min + 1));
        const detect = runStats(run).tuning.detect;
        const reductionChance = clamp(detect * 0.08, 0, 0.55);
        if (count > min && rng() < reductionChance) count -= 1;
        if (count > min && detect >= 5 && rng() < reductionChance * 0.55) count -= 1;
        run.floorEventPlans[floorNumber] = clamp(count, min, max);
        return run.floorEventPlans[floorNumber];
    }

    function nextRunStep(run) {
        const activeFloor = run.activeFloorNumber || run.currentFloor + 1;
        const total = run.currentFloorEventTotal || plannedEventCountForFloor(run, activeFloor);
        const index = (run.activeFloorNumber ? run.currentFloorEventIndex : 0) + 1;
        if (index <= total) {
            return { floorNumber: activeFloor, eventIndex: index, eventTotal: total };
        }
        const floorNumber = run.currentFloor + 1;
        const eventTotal = plannedEventCountForFloor(run, floorNumber);
        return { floorNumber, eventIndex: 1, eventTotal };
    }

    function eventWeightsForRun(run) {
        const weights = { ...config.eventWeights };
        const detect = runStats(run).tuning.detect;
        if (detect <= 0) return weights;

        weights.treasure = Math.round(weights.treasure + (detect * 3));
        weights.walk = Math.max(4, Math.round(weights.walk - (detect * 0.8)));
        weights.omen = Math.max(3, Math.round(weights.omen - (detect * 0.5)));
        return weights;
    }

    function seededFloor(run, floorNumber, eventIndex = 1, eventTotal = 1) {
        const rng = seededRandom(`${state.season.seed}:floor:${floorNumber}:event:${eventIndex}`);
        const isBossEvent = floorNumber % 10 === 0 && eventIndex >= eventTotal;
        if (isBossEvent) {
            return { floorNumber, eventIndex, eventTotal, eventType: "boss", boss: true, enemy: bossForFloor(floorNumber) };
        }
        const weights = eventWeightsForRun(run);
        const eventType = weightedPick(weights, rng());
        const enemy = eventType === "combat" ? enemyForFloor(floorNumber, rng()) : null;
        return { floorNumber, eventIndex, eventTotal, eventType, boss: false, enemy };
    }

    function weightedPick(weights, roll) {
        const entries = Object.entries(weights);
        const total = entries.reduce((sum, [, weight]) => sum + weight, 0);
        let cursor = roll * total;
        for (const [key, weight] of entries) {
            cursor -= weight;
            if (cursor <= 0) return key;
        }
        return entries[0][0];
    }

    function enemyForFloor(floorNumber, rng) {
        const normal = config.enemies.filter((enemy) => !enemy.boss);
        return normal[Math.floor(rng * normal.length)] || normal[0];
    }

    function bossForFloor(floorNumber) {
        if (floorNumber >= 40) return config.enemies.find((enemy) => enemy.code === "abyss_biter_larva");
        if (floorNumber >= 20) return config.enemies.find((enemy) => enemy.code === "black_wind_mouse");
        return config.enemies.find((enemy) => enemy.code === "small_guardian");
    }

    function activeRoute(run) {
        return config.routes[run.activeRouteType] || config.routes.safe;
    }

    function advanceRun() {
        const run = state.activeRun;
        if (!run || run.status !== "active") {
            flash("進行中の挑戦がありません。", "error");
            return;
        }
        if (run.pendingChoices.length > 0) {
            if (!confirmPendingChoice(run)) {
                return;
            }
            if (run.pendingChoices.length > 0) {
                render();
                return;
            }
        }
        if (!consumeStamina()) {
            flash("探索力が足りません。", "error");
            render();
            return;
        }
        run.routeChoiceResult = null;
        run.tuningChoiceResult = null;

        const step = nextRunStep(run);
        const floor = seededFloor(run, step.floorNumber, step.eventIndex, step.eventTotal);
        const hpBefore = run.currentHp;
        let result;
        if (floor.eventType === "boss") {
            result = resolveCombat(run, floor, true);
        } else if (floor.eventType === "combat") {
            result = resolveCombat(run, floor, false);
        } else if (floor.eventType === "trap") {
            result = resolveTrap(run, floor);
        } else if (floor.eventType === "treasure") {
            result = resolveTreasure(run, floor);
        } else if (floor.eventType === "rest") {
            result = resolveRest(run, floor);
        } else if (floor.eventType === "walk") {
            result = resolveWalk(run, floor);
        } else {
            result = resolveOmen(run, floor);
        }

        const floorCleared = run.currentHp > 0 && result.cleared && step.eventIndex >= step.eventTotal;
        if (run.currentHp > 0 && result.cleared) {
            if (floorCleared) {
                run.currentFloor = step.floorNumber;
                run.bestClearedFloor = Math.max(run.bestClearedFloor, step.floorNumber);
                run.activeFloorNumber = null;
                run.currentFloorEventIndex = 0;
                run.currentFloorEventTotal = 0;
                if (run.activeRouteType === "black_wind") {
                    run.blackRouteClearCount += 1;
                }
                applyFloorClearRewards(run, step.floorNumber);
                applyFloorClearHealing(run, step.floorNumber);
                state.tower.currentHp = clamp(run.currentHp, 1, run.maxHp);
                enqueueMilestoneChoices(run, step.floorNumber);
            } else {
                run.activeFloorNumber = step.floorNumber;
                run.currentFloorEventIndex = step.eventIndex;
                run.currentFloorEventTotal = step.eventTotal;
            }
        }

        const hpAfter = run.currentHp;
        const event = {
            floorNumber: step.floorNumber,
            eventIndex: step.eventIndex,
            eventTotal: step.eventTotal,
            floorCleared,
            eventType: floor.eventType,
            routeType: run.activeRouteType,
            result: result.result,
            hpBefore,
            hpAfter,
            scoreDelta: result.scoreDelta || 0,
            logText: result.logText,
            payload: result.payload || {},
            createdAt: nowIso(),
        };
        appendRunEvent(run, event);

        if (run.currentHp <= 0 || result.finishReason) {
            finishRun(run, result.finishReason || "defeated");
            saveState();
            return;
        }

        saveState();
        showScreen("run");
    }

    function requestAdvanceRun() {
        if (isAdvancing) return;

        const run = state.activeRun;
        const currentChoice = run?.pendingChoices?.[0] || null;
        if (!run || run.status !== "active" || (currentChoice && !currentChoice.selected)) {
            advanceRun();
            return;
        }
        if (Number(state.stamina?.current || 0) <= 0) {
            advanceRun();
            return;
        }

        isAdvancing = true;
        updateActionStates();
        window.setTimeout(() => {
            try {
                advanceRun();
            } finally {
                isAdvancing = false;
                updateActionStates();
            }
        }, 180);
    }

    function consumeStamina() {
        state.stamina = state.stamina || { current: staminaMax, max: staminaMax };
        state.stamina.max = staminaMax;
        if (Number(state.stamina.current || 0) <= 0) return false;
        state.stamina.current = clamp(Number(state.stamina.current || 0) - 1, 0, staminaMax);
        return true;
    }

    function resolveCombat(run, floor, isBoss) {
        const stats = runStats(run);
        const route = activeRoute(run);
        const enemyScale = 1 + (floor.floorNumber * 0.055);
        const bossScale = isBoss ? 1.26 : 1;
        const enemy = {
            ...floor.enemy,
            hp: Math.round(floor.enemy.hp * enemyScale * bossScale),
            atk: Math.round(floor.enemy.atk * enemyScale * bossScale),
            def: Math.round(floor.enemy.def * enemyScale * bossScale),
        };
        let enemyHp = enemy.hp;
        let hp = run.currentHp;
        let turns = 0;
        let extraHits = 0;
        const logs = [`地下${floor.floorNumber}階。${enemy.name}が飛び出してきた！`];
        const frames = [{
            title: "遭遇",
            text: `地下${floor.floorNumber}階。${enemy.name}が飛び出してきた！`,
            playerHp: hp,
            playerMaxHp: run.maxHp,
            enemyHp: enemy.hp,
            enemyMaxHp: enemy.hp,
            enemy,
        }];
        const evasionRate = clamp(0.05 + (stats.tuning.evasion * 0.03), 0, 0.45);
        const damageDealtRate = orbMultiplier(run, "damageDealtRate") * (isBoss ? orbMultiplier(run, "bossDamageDealtRate") : 1);
        const damageTakenRate = orbMultiplier(run, "damageTakenRate");
        const extraAttackRate = orbBonus(run, "extraAttackRate");

        while (turns < 8 && enemyHp > 0 && hp > 0) {
            turns += 1;
            const turnLines = [];
            const dealt = Math.max(1, Math.round((stats.atk - (enemy.def * 0.4)) * randomRange(0.9, 1.1) * damageDealtRate));
            enemyHp -= dealt;
            turnLines.push(`${run.valmonName}の攻撃。${enemy.name}に${dealt}ダメージ。`);
            if (enemyHp > 0 && Math.random() < extraAttackRate) {
                const extra = Math.max(1, Math.round(dealt * 0.55));
                enemyHp -= extra;
                extraHits += 1;
                turnLines.push(`連牙カードが反応し、追加で${extra}ダメージ。`);
            }
            if (enemyHp > 0) {
                if (Math.random() < evasionRate) {
                    turnLines.push(`${run.valmonName}はひらりとかわした。`);
                } else {
                    const lowHpRate = hp <= run.maxHp * 0.5 ? orbMultiplier(run, "lowHpDamageTakenRate") : 1;
                    const taken = Math.max(1, Math.round((enemy.atk - (stats.def * 0.4)) * randomRange(0.9, 1.1) * route.enemyAtkRate * damageTakenRate * lowHpRate));
                    hp -= taken;
                    turnLines.push(`${run.valmonName}は${taken}ダメージを受けた。`);
                }
            }
            logs.push(`--- ターン ${turns} ---`, ...turnLines);
            frames.push({
                title: `ターン ${turns}`,
                text: turnLines.join("\n"),
                playerHp: Math.max(0, hp),
                playerMaxHp: run.maxHp,
                enemyHp: Math.max(0, enemyHp),
                enemyMaxHp: enemy.hp,
                enemy,
            });
        }

        run.currentHp = Math.max(0, hp);
        if (enemyHp <= 0) {
            run.killCount += 1;
            if (isBoss) run.bossKillCount += 1;
            const baseScore = isBoss ? 100 : 20;
            const scoreDelta = scoreWithRoute(run, baseScore);
            run.score += scoreDelta;
            const finishText = isBoss ? "階層主を撃破した。" : `${enemy.name}を追い払った。`;
            logs.push(finishText);
            frames.push({
                title: "突破",
                text: finishText,
                playerHp: run.currentHp,
                playerMaxHp: run.maxHp,
                enemyHp: 0,
                enemyMaxHp: enemy.hp,
                enemy,
            });
            return {
                result: "clear",
                cleared: true,
                scoreDelta,
                logText: logs.join("\n"),
                payload: { enemy, turns, extraHits, frames },
            };
        }

        if (isBoss) {
            const finishText = "階層主を倒しきれず、深層から押し戻された。";
            logs.push(finishText);
            run.currentHp = 0;
            frames.push({
                title: "敗退",
                text: finishText,
                playerHp: 0,
                playerMaxHp: run.maxHp,
                enemyHp: Math.max(0, enemyHp),
                enemyMaxHp: enemy.hp,
                enemy,
            });
            return {
                result: "boss_timeout",
                cleared: false,
                scoreDelta: 0,
                finishReason: "defeated",
                logText: logs.join("\n"),
                payload: { enemy, turns, extraHits, frames },
            };
        }

        const fatigueDamage = Math.max(1, Math.round(enemy.atk * 0.35));
        const scoreDelta = scoreWithRoute(run, 8);
        run.score += scoreDelta;
        run.currentHp = Math.max(0, run.currentHp - fatigueDamage);
        const finishText = `時間切れ。押し負けて${fatigueDamage}ダメージを受けたが、階層は抜けた。`;
        logs.push(finishText);
        frames.push({
            title: "辛勝",
            text: finishText,
            playerHp: run.currentHp,
            playerMaxHp: run.maxHp,
            enemyHp: Math.max(0, enemyHp),
            enemyMaxHp: enemy.hp,
            enemy,
        });
        return {
            result: "timeout_clear",
            cleared: true,
            scoreDelta,
            logText: logs.join("\n"),
            payload: { enemy, turns, extraHits, frames },
        };
    }

    function resolveTrap(run, floor) {
        const stats = runStats(run);
        const route = activeRoute(run);
        const avoidRate = clamp(0.1 + (stats.tuning.evasion * 0.04) + orbBonus(run, "trapAvoidBonus"), 0, 0.8);
        if (Math.random() < avoidRate) {
            run.trapAvoidCount += 1;
            const scoreDelta = scoreWithRoute(run, 10);
            run.score += scoreDelta;
            return {
                result: "avoided",
                cleared: true,
                scoreDelta,
                logText: `床石が崩れた！\n${run.valmonName}はひらりとかわした。\n罠回避成功。`,
                payload: { avoidRate },
            };
        }

        const baseDamage = (14 + (floor.floorNumber * 2)) * route.trapDamageRate * orbMultiplier(run, "trapDamageTakenRate");
        const reduction = Math.min(0.5, stats.tuning.defense * 0.04);
        const damage = Math.max(1, Math.round(baseDamage * (1 - reduction)));
        run.currentHp = Math.max(0, run.currentHp - damage);
        return {
            result: "hit",
            cleared: run.currentHp > 0,
            scoreDelta: 0,
            logText: `黒風の吹き溜まりに巻き込まれた。\n${run.valmonName}は${damage}ダメージを受けた。`,
            payload: { avoidRate, damage },
        };
    }

    function resolveTreasure(run, floor) {
        const stats = runStats(run);
        const route = activeRoute(run);
        const successRate = clamp((0.2 + (stats.tuning.detect * 0.05) + orbBonus(run, "treasureRateBonus")) * route.treasureRateModifier, 0, 0.85);
        if (Math.random() < successRate) {
            run.treasureCount += 1;
            const scoreDelta = scoreWithRoute(run, Math.round(30 * orbMultiplier(run, "eventScoreRate")));
            run.score += scoreDelta;
            if (Math.random() < 0.25) {
                healRun(run, 0.06);
            }
            return {
                result: "opened",
                cleared: true,
                scoreDelta,
                logText: `光る小箱を見つけた。\n探知が冴え、宝箱を開けた。\nスコア +${scoreDelta}`,
                payload: { successRate },
            };
        }
        return {
            result: "missed",
            cleared: true,
            scoreDelta: 0,
            logText: "光る小箱の気配があったが、奥へ沈んでいった。",
            payload: { successRate },
        };
    }

    function resolveRest(run) {
        const stats = runStats(run);
        const healRate = 0.08 + (stats.tuning.detect * 0.01) + orbBonus(run, "restHealBonus");
        const healed = healRun(run, healRate);
        run.restFoundCount += 1;
        const scoreDelta = scoreWithRoute(run, Math.round(10 * orbMultiplier(run, "eventScoreRate")));
        run.score += scoreDelta;
        return {
            result: "rested",
            cleared: true,
            scoreDelta,
            logText: `白風の休憩所を見つけた。\n${run.valmonName}は羽を休めた。\nHPが${healed}回復した。`,
            payload: { healRate, healed },
        };
    }

    function resolveWalk(run, floor) {
        const scoreDelta = scoreWithRoute(run, Math.round(8 * orbMultiplier(run, "eventScoreRate")));
        run.score += scoreDelta;
        return {
            result: "walked",
            cleared: true,
            scoreDelta,
            logText: `細い地下道を進んだ。\n大きな出来事はないが、足跡は奥へ続いている。\nスコア +${scoreDelta}`,
            payload: { floorNumber: floor.floorNumber },
        };
    }

    function resolveOmen(run, floor) {
        const dangerAvoid = orbBonus(run, "dangerAvoidBonus");
        const avoided = Math.random() < dangerAvoid;
        const foundOnlyMark = !avoided && Math.random() < 0.45;
        const scoreBase = avoided ? 35 : foundOnlyMark ? 6 : 18;
        const scoreDelta = scoreWithRoute(run, Math.round(scoreBase * orbMultiplier(run, "eventScoreRate")));
        run.score += scoreDelta;
        return {
            result: avoided ? "avoided_omen" : foundOnlyMark ? "mark_trace" : "scored",
            cleared: true,
            scoreDelta,
            logText: avoided
                ? `黒い風が足元をすり抜ける……\n退避カードが反応し、危険な気配をかわした。\nスコア +${scoreDelta}`
                : foundOnlyMark
                    ? `奥の闇に何かが光った気がした。\n${run.valmonName}が慎重に近づくと、古い印が壁に残っているだけだった。\nスコア +${scoreDelta}`
                : `黒い風が足元をすり抜ける……\n深層の気配を読み、スコア +${scoreDelta}`,
            payload: { floorNumber: floor.floorNumber },
        };
    }

    function scoreWithRoute(run, base) {
        let value = base * activeRoute(run).scoreRate;
        if (run.activeRouteType === "black_wind") {
            value *= orbMultiplier(run, "blackRouteScoreRate");
        }
        if (run.activeRouteType === "glow") {
            value *= orbMultiplier(run, "glowRouteScoreRate");
        }
        return Math.max(0, Math.round(value));
    }

    function randomRange(min, max) {
        return min + (Math.random() * (max - min));
    }

    function healRun(run, rate) {
        const amount = Math.max(1, Math.round(run.maxHp * rate));
        const before = run.currentHp;
        run.currentHp = Math.min(run.maxHp, run.currentHp + amount);
        return run.currentHp - before;
    }

    function applyFloorClearHealing(run, floorNumber) {
        const normal = healRun(run, config.healing.floorClearRate);
        let milestone = 0;
        if (floorNumber % 10 === 0) {
            milestone = healRun(run, config.healing.tenFloorRate + orbBonus(run, "milestoneHealBonus"));
        } else if (floorNumber % 5 === 0) {
            milestone = healRun(run, config.healing.fiveFloorRate + orbBonus(run, "milestoneHealBonus"));
        }
        if (normal > 0 || milestone > 0) {
            addRunLog(run, `階層を抜け、HPが${normal + milestone}回復した。`);
        }
    }

    function applyFloorClearRewards(run, floorNumber) {
        const tower = state.tower;
        tower.currentFloorToChallenge = Math.min(config.season.maxFloorTarget, floorNumber + 1);
        tower.highestClearedFloor = Math.max(Number(tower.highestClearedFloor || 0), floorNumber);
        tower.score = Math.max(Number(tower.score || 0), run.score);
        tower.currentHp = clamp(run.currentHp, 1, run.maxHp);
        tower.maxHp = run.maxHp;

        if (!tower.clearedRewardFloors.includes(floorNumber)) {
            tower.clearedRewardFloors.push(floorNumber);
            let bp = floorNumber % 10 === 0
                ? (floorNumber >= 50 ? config.bp.bossFloorClearAfter50 : config.bp.bossFloorClear)
                : config.bp.normalFloorClear;
            if (run.activeRouteType === "black_wind") bp += 1;
            bp += floorNumber % 10 === 0 ? orbBonus(run, "bossBpBonus") : 0;
            tower.bp += bp;
            addRunLog(run, `地下${floorNumber}階を初めて突破した。深層BP +${bp}`);

            if (floorNumber <= config.season.floorRewardCardUntilFloor) {
                run.pendingChoices.push({
                    type: "card_reward",
                    choiceType: "floor_reward",
                    sourceFloor: floorNumber,
                    options: cardChoiceOptions(`floor:${floorNumber}`),
                });
            }
        }
    }

    function enqueueMilestoneChoices(run, floorNumber) {
        const choices = [];
        if (floorNumber % 3 === 0) {
            choices.push({ type: "route", options: ["safe", "black_wind", "glow"] });
        }
        if (floorNumber % 5 === 0) {
            choices.push({ type: "temporary_tuning", points: 1 });
        }
        run.pendingChoices.push(...choices);
    }

    function cardChoiceOptions(seedSuffix) {
        const tower = state.tower;
        const rng = seededRandom(`${state.season.seed}:card-choice:${seedSuffix}`);
        const pool = allCards()
            .filter((card) => tower.activeCardCodes.includes(card.code))
            .filter((card) => !tower.ownedCardCodes.includes(card.code));
        const options = [];
        const categoryGroups = [
            ["attack", "defense", "heal"],
            ["explore", "evasion", "special"],
            null,
        ];
        categoryGroups.forEach((categories) => {
            const candidates = pool.filter((card) => !options.includes(card.code) && (!categories || categories.includes(card.category)));
            if (!candidates.length) return;
            const picked = candidates[Math.floor(rng() * candidates.length)];
            options.push(picked.code);
        });
        while (options.length < 3 && pool.length > 0) {
            const picked = pool[Math.floor(rng() * pool.length)];
            if (picked && !options.includes(picked.code)) options.push(picked.code);
            if (options.length >= pool.length) break;
        }
        return options;
    }

    function tempOrbOptions(floorNumber) {
        const rng = seededRandom(`${state.season.seed}:temp-card:${floorNumber}`);
        const pool = [...config.tempOrbs];
        const unique = [];
        while (unique.length < 3 && pool.length > 0) {
            const index = Math.floor(rng() * pool.length);
            const [picked] = pool.splice(index, 1);
            if (picked && !unique.some((orb) => orb.code === picked.code)) unique.push(picked);
        }
        return unique.map((orb) => orb.code);
    }

    function addRunLog(run, text, payload = {}) {
        appendRunEvent(run, {
            floorNumber: run.currentFloor,
            eventType: "log",
            routeType: run.activeRouteType,
            result: "log",
            hpBefore: run.currentHp,
            hpAfter: run.currentHp,
            scoreDelta: 0,
            logText: text,
            payload,
            createdAt: nowIso(),
        });
    }

    function appendRunEvent(run, event) {
        run.events = run.events || [];
        run.events.push(event);
        run.events = run.events.slice(-80);
    }

    function finishRun(run, reason) {
        run.status = reason === "retired" ? "retired" : "ended";
        run.endedAt = nowIso();
        const hpBonus = run.currentHp > 0 ? Math.floor((run.currentHp / run.maxHp) * 50) : 0;
        const latest = latestPageEvent(run);
        const displayFloor = latest?.floorNumber || run.activeFloorNumber || run.currentFloor;
        run.score += hpBonus;
        state.tower.score = Math.max(Number(state.tower.score || 0), run.score);
        state.tower.highestClearedFloor = Math.max(Number(state.tower.highestClearedFloor || 0), run.bestClearedFloor);
        state.tower.currentFloorToChallenge = reason === "defeated"
            ? Math.max(1, displayFloor)
            : Math.max(1, run.bestClearedFloor + 1);
        state.tower.maxHp = run.maxHp;
        state.tower.currentHp = reason === "defeated" ? run.maxHp : clamp(run.currentHp, 1, run.maxHp);
        addRunLog(run, reason === "retired"
            ? `${run.valmonName}は地下${displayFloor}階で撤退した。記録は${run.bestClearedFloor}階。`
            : `${run.valmonName}は地下${displayFloor}階で力尽きた。HPは回復し、同じ階層から再挑戦できます。`);
        updateRanking(run);
        state.lastResult = { ...run };
        state.activeRun = null;
        flash(`記録: ${run.bestClearedFloor}階 / スコア ${fmt(run.score)}`);
        showScreen(reason === "retired" ? "prepare" : "run");
    }

    function retireRun() {
        const run = state.activeRun;
        if (!run || run.status !== "active") {
            flash("撤退できる挑戦がありません。", "error");
            return;
        }
        finishRun(run, "retired");
        saveState();
    }

    function updateRanking(run) {
        const seasonKey = activeSeasonKey();
        const rows = state.rankings[seasonKey] || seedRankingRows();
        const ownName = "試験冒険者";
        const existingIndex = rows.findIndex((row) => row.characterName === ownName);
        const candidate = {
            characterName: ownName,
            valmonName: run.valmonName,
            bestRunId: run.id,
            bestFloor: run.bestClearedFloor,
            bestScore: run.score,
            attemptCount: state.tower.attemptCount || 1,
            achievedAt: run.endedAt || nowIso(),
        };
        if (existingIndex >= 0) {
            const current = rows[existingIndex];
            if (isBetterRanking(candidate, current)) {
                rows[existingIndex] = candidate;
            }
        } else {
            rows.push(candidate);
        }
        state.rankings[seasonKey] = sortRanking(rows).slice(0, 100);
    }

    function isBetterRanking(next, current) {
        if (next.bestFloor !== current.bestFloor) return next.bestFloor > current.bestFloor;
        if (next.bestScore !== current.bestScore) return next.bestScore > current.bestScore;
        if ((next.attemptCount || 0) !== (current.attemptCount || 0)) return (next.attemptCount || 0) < (current.attemptCount || 0);
        return new Date(next.achievedAt).getTime() < new Date(current.achievedAt).getTime();
    }

    function sortRanking(rows) {
        return [...rows].sort((a, b) => {
            if (b.bestFloor !== a.bestFloor) return b.bestFloor - a.bestFloor;
            if (b.bestScore !== a.bestScore) return b.bestScore - a.bestScore;
            if ((a.attemptCount || 0) !== (b.attemptCount || 0)) return (a.attemptCount || 0) - (b.attemptCount || 0);
            return new Date(a.achievedAt).getTime() - new Date(b.achievedAt).getTime();
        });
    }

    function seedRankingRows() {
        const rng = seededRandom(`${state.season.seed}:ranking`);
        const names = ["ミナト", "ルカ", "セナ", "ハル", "リオ", "アヤメ", "ナギ"];
        return sortRanking(names.map((name, index) => {
            const floor = 28 + Math.floor(rng() * 45) + index;
            return {
                characterName: name,
                valmonName: config.valmons[index % config.valmons.length].name,
                bestRunId: `seed_${index}`,
                bestFloor: floor,
                bestScore: (floor * 100) + Math.floor(rng() * 900),
                attemptCount: 10 + Math.floor(rng() * 60),
                achievedAt: new Date(Date.now() - (index * 4300000)).toISOString(),
            };
        }));
    }

    function selectPendingChoice(type, value) {
        const run = state.activeRun;
        if (!run) return;
        const index = run.pendingChoices.findIndex((choice) => choice.type === type);
        if (index < 0) return;
        run.pendingChoices[index].selected = value;
        saveState();
        render();
    }

    function confirmPendingChoice(run) {
        const choice = run.pendingChoices[0];
        if (!choice) return true;
        if (!choice.selected) {
            flash("先に選択肢を選んでください。", "error");
            render();
            return false;
        }

        if (choice.type === "route") {
            applyRouteChoice(run, choice, choice.selected);
        } else if (choice.type === "temporary_tuning") {
            applyTemporaryTuningChoice(run, choice.selected);
        } else if (choice.type === "card_reward") {
            applyCardChoice(run, choice, choice.selected);
        } else if (choice.type === "temporary_orb") {
            applyTemporaryOrbChoice(run, choice.selected);
        }

        run.pendingChoices.shift();
        saveState();
        return true;
    }

    function chooseRoute(routeType) {
        selectPendingChoice("route", routeType);
    }

    function applyRouteChoice(run, choice, routeType) {
        const route = config.routes[routeType];
        run.activeRouteType = routeType;
        run.routeUntilFloor = run.currentFloor + 3;
        run.routeChoiceResult = null;
        addRunLog(run, routeChoiceText(run, routeType), {
            choiceType: "route",
            routeType,
            routeName: route.name,
            routeDesc: route.desc,
        });
    }

    function routeChoiceText(run, routeType) {
        return {
            safe: `${run.valmonName}は穏やかな風の抜ける細道へ、静かに歩き出した。`,
            black_wind: `${run.valmonName}は黒い風の渦へ鼻先を向け、奥へ踏み込んだ。`,
            glow: `${run.valmonName}は壁の隙間にまたたく淡い光を追い始めた。`,
        }[routeType] || `${run.valmonName}は気配の強い道を選んだ。`;
    }

    function chooseTemporaryTuning(stat) {
        selectPendingChoice("temporary_tuning", stat);
    }

    function applyTemporaryTuningChoice(run, stat) {
        run.tempTuning[stat] = Number(run.tempTuning[stat] || 0) + 1;
        run.tuningChoiceResult = null;
        addRunLog(run, tuningChoiceText(run, stat), {
            choiceType: "temporary_tuning",
            stat,
            statName: config.tuning.labels[stat],
        });
    }

    function tuningChoiceText(run, stat) {
        return {
            attack: `${run.valmonName}は爪先に力を集め、攻めの調律を強めた。`,
            defense: `${run.valmonName}は体を低く構え、守りの調律を強めた。`,
            detect: `${run.valmonName}は耳を澄ませ、探知の調律を強めた。`,
            evasion: `${run.valmonName}は足取りを軽くし、回避の調律を強めた。`,
        }[stat] || `${run.valmonName}は白風の加護を受け、調律を整えた。`;
    }

    function chooseTemporaryOrb(orbCode) {
        selectPendingChoice("temporary_orb", orbCode);
    }

    function chooseCard(cardCode) {
        selectPendingChoice("card_reward", cardCode);
    }

    function beginCoinCardChoice() {
        const run = state.activeRun;
        if (run?.pendingChoices?.length) {
            flash("先に表示中の選択を決めてください。", "error");
            return;
        }
        if (availableCoinCount() <= 0) {
            flash("使用できる金貨がありません。", "error");
            return;
        }
        const choice = {
            type: "card_reward",
            choiceType: "coin",
            options: cardChoiceOptions(`coin:${state.tower.usedCoinCount + 1}`),
            selected: null,
        };
        state.tower.usedCoinCount += 1;
        ensureChoiceCarrier().pendingChoices.push(choice);
        saveState();
        showScreen("run");
    }

    function beginBpCardChoice() {
        const run = state.activeRun;
        if (run?.pendingChoices?.length) {
            flash("先に表示中の選択を決めてください。", "error");
            return;
        }
        if (state.tower.bp < config.bp.cardChoiceCost) {
            flash("深層BPが足りません。", "error");
            return;
        }
        state.tower.bp -= config.bp.cardChoiceCost;
        state.tower.spentBp += config.bp.cardChoiceCost;
        ensureChoiceCarrier().pendingChoices.push({
            type: "card_reward",
            choiceType: "bp",
            options: cardChoiceOptions(`bp:${state.tower.spentBp}`),
            selected: null,
        });
        saveState();
        showScreen("run");
    }

    function ensureChoiceCarrier() {
        if (state.activeRun) return state.activeRun;
        const valmon = selectedValmon();
        const stats = calculateStats(valmon, draftSetting().tuning, emptyTuning(), state.tower.deckCardCodes, null);
        state.activeRun = {
            id: `choice_${Date.now()}`,
            characterName: "試験冒険者",
            valmonId: valmon.id,
            valmonName: valmon.name,
            valmonLevel: valmon.level,
            seasonCode: state.season.code,
            mode: "ranking",
            isRanked: false,
            status: "active",
            currentFloor: Math.max(0, Number(state.tower.currentFloorToChallenge || 1) - 1),
            bestClearedFloor: Number(state.tower.highestClearedFloor || 0),
            maxHp: stats.maxHp,
            currentHp: clamp(Number(state.tower.currentHp || stats.maxHp), 1, stats.maxHp),
            baseTuning: { ...draftSetting().tuning },
            tempTuning: emptyTuning(),
            equippedOrbCodes: [...state.tower.deckCardCodes],
            tempOrbCode: null,
            activeRouteType: "safe",
            routeUntilFloor: state.tower.currentFloorToChallenge + 2,
            activeFloorNumber: null,
            currentFloorEventIndex: 0,
            currentFloorEventTotal: 0,
            floorEventPlans: {},
            pendingChoices: [],
            score: Number(state.tower.score || 0),
            killCount: 0,
            bossKillCount: 0,
            treasureCount: 0,
            trapAvoidCount: 0,
            blackRouteClearCount: 0,
            restFoundCount: 0,
            startedAt: nowIso(),
            endedAt: null,
            events: [],
        };
        addRunLog(state.activeRun, "カード選択のため、白風が手元に集まった。");
        return state.activeRun;
    }

    function spendBp(action) {
        const tower = state.tower;
        const costs = {
            attack: config.bp.atkUpgradeCost,
            defense: config.bp.defUpgradeCost,
            detect: config.bp.detectUpgradeCost,
            evasion: config.bp.evasionUpgradeCost,
            hp: config.bp.hpUpgradeCost,
            heal: config.bp.healCost,
        };
        if (action === "slot") {
            const nextSlot = tower.deckSlotLimit + 1;
            const cost = config.deck.expandCosts[nextSlot];
            if (!cost || tower.bp < cost) return;
            tower.bp -= cost;
            tower.spentBp += cost;
            tower.deckSlotLimit = nextSlot;
            flash(`装備枠が${nextSlot}枚になりました。`);
        } else if (action === "heal") {
            const cost = costs.heal;
            if (tower.bp < cost) return;
            tower.bp -= cost;
            tower.spentBp += cost;
            tower.currentHp = tower.maxHp || config.baseStats.hp;
            if (state.activeRun) state.activeRun.currentHp = state.activeRun.maxHp;
            flash("HPを全回復しました。");
        } else if (costs[action]) {
            if (tower.bp < costs[action]) return;
            tower.bp -= costs[action];
            tower.spentBp += costs[action];
            tower.upgrades[action] = Number(tower.upgrades[action] || 0) + 1;
            flash(`${config.tuning.labels[action] || "最大HP"}を強化しました。`);
        }
        saveState();
        render();
    }

    function applyCardChoice(run, choice, cardCode) {
        const tower = state.tower;
        const card = cardByCode(cardCode);
        if (!card || tower.ownedCardCodes.includes(cardCode)) return;
        tower.ownedCardCodes.push(cardCode);
        if (tower.deckCardCodes.length < tower.deckSlotLimit) {
            tower.deckCardCodes.push(cardCode);
        }
        addRunLog(run, `${run.valmonName}は「${card.name}」を見つけた。デッキ候補に加わった。`, {
            choiceType: choice.choiceType || "card_reward",
            cardCode,
            cardName: card.name,
            sourceFloor: choice.sourceFloor || null,
        });
    }

    function applyTemporaryOrbChoice(run, orbCode) {
        if (orbCode !== "keep") {
            run.tempOrbCode = orbCode;
            const orb = config.tempOrbs.find((row) => row.code === orbCode);
            addRunLog(run, `深層の「${orb.name}」を一時カードにした。`, {
                choiceType: "temporary_orb",
                orbCode,
                orbName: orb.name,
            });
        } else {
            addRunLog(run, "現在の一時カードを維持した。", {
                choiceType: "temporary_orb",
                orbCode,
                orbName: "維持する",
            });
        }
    }

    function render() {
        ensureSeason();
        renderStamina();
        renderTowerSummary();
        renderPrepare();
        renderDeparture();
        renderRun();
        renderRanking();
        renderChoices();
        renderStatus();
        updateActionStates();
    }

    function renderStamina() {
        state.stamina = state.stamina || { current: staminaMax, max: staminaMax };
        state.stamina.max = staminaMax;
        state.stamina.current = clamp(Number(state.stamina.current || 0), 0, staminaMax);
        $("staminaCurrentText").textContent = fmt(state.stamina.current);
        $("staminaMaxText").textContent = `/${fmt(state.stamina.max)}`;
    }

    function naturalUnlockedCoinCount() {
        const elapsed = Math.max(0, Date.now() - new Date(state.season.startsAt).getTime());
        const intervals = Math.floor(elapsed / (config.season.naturalCoinIntervalHours * 60 * 60 * 1000));
        return clamp(intervals * config.season.naturalCoinAmountPerInterval, 0, config.season.naturalCoinMax);
    }

    function claimableCoinCount() {
        return Math.max(0, naturalUnlockedCoinCount() - Number(state.tower.claimedCoinCount || 0));
    }

    function availableCoinCount() {
        return clamp(Number(state.tower.claimedCoinCount || 0) + Number(state.tower.paidAdvancedCoinCount || 0), 0, config.season.naturalCoinMax)
            - Number(state.tower.usedCoinCount || 0);
    }

    function nextCoinRemainingMs() {
        const claimed = Number(state.tower.claimedCoinCount || 0);
        if (claimed >= config.season.naturalCoinMax) return 0;
        const intervalMs = config.season.naturalCoinIntervalHours * 60 * 60 * 1000;
        const amount = config.season.naturalCoinAmountPerInterval;
        const nextBatchIndex = Math.floor(claimed / amount) + 1;
        const nextAt = new Date(state.season.startsAt).getTime() + (nextBatchIndex * intervalMs);
        return Math.max(0, nextAt - Date.now());
    }

    function formatDuration(ms) {
        const totalSeconds = Math.max(0, Math.ceil(ms / 1000));
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
    }

    function renderCoinClaimStatus() {
        const claimable = claimableCoinCount();
        const button = $("coinClaimButton");
        if (claimable > 0) {
            $("coinClaimText").textContent = `受取可能な金貨 ${fmt(claimable)}枚`;
            button.hidden = false;
            button.disabled = false;
        } else if (Number(state.tower.claimedCoinCount || 0) >= config.season.naturalCoinMax) {
            $("coinClaimText").textContent = "今週分の金貨はすべて取得済み";
            button.hidden = true;
        } else {
            $("coinClaimText").textContent = `次の金貨取得まであと ${formatDuration(nextCoinRemainingMs())}`;
            button.hidden = true;
        }
        if ($("towerCoinText")) {
            $("towerCoinText").textContent = fmt(availableCoinCount());
        }
    }

    function claimCoin() {
        if (claimableCoinCount() <= 0) {
            renderCoinClaimStatus();
            return;
        }
        state.tower.claimedCoinCount = clamp(Number(state.tower.claimedCoinCount || 0) + 1, 0, config.season.naturalCoinMax);
        saveState();
        renderCoinClaimStatus();
        renderTowerSummary();
        openCoinModal();
    }

    function renderTowerSummary() {
        const tower = state.tower;
        $("seasonPeriodText").textContent = `${formatDate(state.season.startsAt)}〜${formatDate(state.season.endsAt)}`;
        $("towerCurrentFloorText").textContent = `${fmt(tower.currentFloorToChallenge)}階`;
        $("towerBestFloorText").textContent = `${fmt(tower.highestClearedFloor)}階`;
        $("towerCoinText").textContent = fmt(availableCoinCount());
        $("towerBpText").textContent = fmt(tower.bp);
        $("towerActiveCardText").textContent = `${tower.activeCardCodes.length}/100`;
        $("towerDormantCardText").textContent = `${tower.dormantCardCodes.length}/100`;
        renderCoinClaimStatus();
        $("dormantCardList").innerHTML = tower.dormantCardCodes.map(cardByCode).filter(Boolean).map((card) => (
            `<span class="run-chip">${orbIcon(card)}${escapeHtml(card.name)}</span>`
        )).join("");
    }

    function ensureSeason() {
        const season = currentSeason();
        if (state.season.code !== season.code) {
            state.season = season;
            state.tower = defaultTowerState(season);
            state.activeRun = null;
            saveState();
        }
    }

    function renderPrepare() {
        const valmon = draftValmon();
        const setting = draftSetting();
        const limit = tuningPointLimit(valmon.level);
        const total = statKeys.reduce((sum, key) => sum + Number(setting.tuning[key] || 0), 0);
        $("selectedValmonImage").src = `../../../public/images/valmon/${valmon.image}`;
        $("selectedValmonName").textContent = valmon.name;
        $("selectedValmonMeta").textContent = `Lv${valmon.level} / 調律ポイント ${limit}`;
        $("tuningRemainingText").textContent = String(limit - total);
        $("tuningLimitLabel").textContent = `上限 ${config.tuning.statCap} / 合計 ${limit}`;
        renderTuningList();
        renderOrbList();
        const validation = validateSetting(valmon);
        $("tuningError").textContent = !validation.ok && validation.target === "tuning" ? validation.message : "";
        $("orbError").textContent = !validation.ok && validation.target === "orb" ? validation.message : "";
    }

    function renderTuningList() {
        const setting = draftSetting();
        $("tuningList").innerHTML = statKeys.map((key) => `
            <div class="tuning-row">
                <label>${statIcon(key)}<span>${config.tuning.labels[key]}</span></label>
                <button type="button" class="step-button" data-tuning="${key}" data-delta="-1">-</button>
                <div class="tuning-value">${setting.tuning[key] || 0}</div>
                <button type="button" class="step-button" data-tuning="${key}" data-delta="1">+</button>
            </div>
        `).join("");
    }

    function statIcon(key) {
        const icon = config.tuning.icons?.[key];
        if (!icon) return "";
        return `<img class="stat-icon" src="../../../public/images/icon/${icon}" alt="" aria-hidden="true">`;
    }

    function renderOrbList() {
        const tower = state.tower;
        $("selectedOrbText").textContent = `${tower.deckCardCodes.length} / ${tower.deckSlotLimit}`;
        const ownedCards = tower.ownedCardCodes.map(cardByCode).filter(Boolean);
        $("orbList").innerHTML = ownedCards.length ? ownedCards.map((orb) => {
            const active = tower.deckCardCodes.includes(orb.code) ? " active" : "";
            return `<button type="button" class="orb-card${active}" data-orb-code="${orb.code}">
                ${orbIcon(orb)}
                <span class="orb-card-body">
                    <strong>${orb.name}</strong>
                    <span>${categoryLabel(orb.category)} / ${orb.rarity || "N"}</span>
                    <p class="muted">${orb.description}</p>
                </span>
            </button>`;
        }).join("") : `<p class="empty-note">まだカードを所持していません。金貨か1〜10階の初回突破報酬でカードを得ます。</p>`;
        renderBpActions();
    }

    function renderBpActions() {
        const tower = state.tower;
        $("bpUpgradeLabel").textContent = `未使用BP ${fmt(tower.bp)}`;
        const nextSlot = tower.deckSlotLimit + 1;
        const slotCost = config.deck.expandCosts[nextSlot] || null;
        const actions = [
            { key: "attack", label: "攻撃 +1", cost: config.bp.atkUpgradeCost },
            { key: "defense", label: "防御 +1", cost: config.bp.defUpgradeCost },
            { key: "detect", label: "探索 +1", cost: config.bp.detectUpgradeCost },
            { key: "evasion", label: "回避 +1", cost: config.bp.evasionUpgradeCost },
            { key: "hp", label: "最大HP +10", cost: config.bp.hpUpgradeCost },
            { key: "heal", label: "HP全回復", cost: config.bp.healCost },
            { key: "slot", label: nextSlot <= config.deck.maxSlotLimit ? `装備枠 +1` : "装備枠 最大", cost: slotCost },
        ];
        $("bpActionGrid").innerHTML = actions.map((action) => {
            const disabled = !action.cost || tower.bp < action.cost;
            return `<button type="button" class="bp-action" data-bp-action="${action.key}" ${disabled ? "disabled" : ""}>
                <strong>${action.label}</strong>
                <span>${action.cost ? `${action.cost}BP` : "上限"}</span>
            </button>`;
        }).join("");
    }

    function orbIcon(orb) {
        if (!orb?.icon) return "";
        return `<img class="orb-icon" src="../../../public/images/icon/${orb.icon}" alt="" aria-hidden="true">`;
    }

    function renderDeparture() {
        const run = state.activeRun;
        if (!run) {
            $("departureValmonImage").src = `../../../public/images/valmon/${selectedValmon().image}`;
            $("departureStatChips").innerHTML = "";
            $("departureOrbChips").innerHTML = "";
            $("departureMessage").textContent = "準備画面から挑戦を開始してください。";
            return;
        }

        const valmon = config.valmons.find((row) => row.id === Number(run.valmonId)) || selectedValmon();
        const stats = runStats(run);
        $("departureValmonImage").src = `../../../public/images/valmon/${valmon.image}`;
        $("departureStatChips").innerHTML = statKeys.map((key) => `
            <span class="run-chip">${statIcon(key)}${config.tuning.labels[key]} ${stats.tuning[key]}</span>
        `).join("");
        $("departureOrbChips").innerHTML = allRunOrbs(run).map((orb) => `
            <span class="run-chip">${orbIcon(orb)}${escapeHtml(orb.name)}</span>
        `).join("");
        $("departureMessage").textContent = departureMessageForRun(run, stats);
    }

    function renderRun() {
        const run = displayRun();
        if (!run) {
            $("runHpText").textContent = "-";
            setHpBar("runHpBar", 0, 1);
            $("runScoreText").textContent = state.lastResult ? fmt(state.lastResult.score) : "0";
            $("runSummary").innerHTML = "";
            $("runLog").innerHTML = "";
            $("runLogCount").textContent = "0件";
            $("runStatChips").innerHTML = "";
            $("runOrbChips").innerHTML = "";
            renderDepthProgress(null);
            $("battleValmonName").textContent = selectedValmon().name;
            $("battleValmonSub").textContent = `Lv${selectedValmon().level}`;
            $("lastEvent").textContent = state.lastResult
                ? `${state.lastResult.valmonName} / 記録 ${state.lastResult.bestClearedFloor}階 / スコア ${fmt(state.lastResult.score)}`
                : "まだ進行していません。";
            renderEventStage(null);
            $("runValmonImage").src = `../../../public/images/valmon/${selectedValmon().image}`;
            return;
        }

        const valmon = config.valmons.find((row) => row.id === Number(run.valmonId)) || selectedValmon();
        const stats = runStats(run);
        const isActive = state.activeRun?.id === run.id;
        const pageEvent = latestPageEvent(run);
        const stageHp = pageEvent ? Number(pageEvent.hpBefore || run.currentHp) : run.currentHp;
        $("runValmonImage").src = `../../../public/images/valmon/${valmon.image}`;
        $("battleValmonName").textContent = valmon.name;
        $("battleValmonSub").textContent = `Lv${valmon.level}`;
        $("runHpText").textContent = `${fmt(stageHp)} / ${fmt(run.maxHp)}`;
        setHpBar("runHpBar", stageHp, run.maxHp);
        $("runScoreText").textContent = fmt(run.score);
        $("runStatChips").innerHTML = statKeys.map((key) => `
            <span class="run-chip">${statIcon(key)}${config.tuning.labels[key]} ${stats.tuning[key]}</span>
        `).join("");
        $("runOrbChips").innerHTML = allRunOrbs(run).map((orb) => `
            <span class="run-chip">${orbIcon(orb)}${escapeHtml(orb.name)}</span>
        `).join("");
        $("runSummary").innerHTML = summaryItems(run).map((item) => (
            `<div class="summary-item"><span>${item.label}</span><strong>${item.value}</strong></div>`
        )).join("");
        normalizeRunEvents(run);
        $("runLogCount").textContent = pageEvent
            ? `地下${pageEvent.floorNumber}階`
            : "入口";
        renderDepthProgress(run, pageEvent);
        $("runLog").innerHTML = pageEvent
            ? renderLeadingChoiceLogEvents(run, pageEvent) + renderBattleTimeline(run, [pageEvent]) + renderTrailingLogEvents(run, pageEvent)
            : `<p class="empty-note">${escapeHtml(run.valmonName)}が地下入口で待機しています。</p>`;
        const last = pageEvent;
        $("lastEvent").innerHTML = last ? escapeHtml(last.logText).replace(/\n/g, "<br>") : "まだ進行していません。";
        renderEventStage(run);
    }

    function renderDepthProgress(run, pageEvent = null) {
        const displayFloor = pageEvent?.floorNumber || run?.activeFloorNumber || run?.currentFloor || 0;
        if (!run || !displayFloor) {
            $("depthProgressRange").textContent = "地下1〜10階";
            $("depthProgressFloor").textContent = "地下入口";
            $("depthProgressFill").style.width = "0%";
            $("depthProgressBar").setAttribute("aria-valuenow", "0");
            return;
        }

        const rangeStart = Math.floor((displayFloor - 1) / 10) * 10 + 1;
        const rangeEnd = rangeStart + 9;
        const stepInRange = clamp(displayFloor - rangeStart + 1, 1, 10);
        $("depthProgressRange").textContent = `地下${rangeStart}〜${rangeEnd}階`;
        $("depthProgressFloor").textContent = `地下${displayFloor}階`;
        $("depthProgressFill").style.width = `${stepInRange * 10}%`;
        $("depthProgressBar").setAttribute("aria-valuenow", String(stepInRange));
    }

    function renderEventStage(run) {
        const latest = latestPageEvent(run);
        $("eventStage").classList.toggle("event-only", Boolean(latest && !isCombatEvent(latest)));
        renderEnemyPanel(run, latest);
        if (!run) {
            $("eventTypeLabel").textContent = "待機中";
            $("encounterTitle").textContent = state.lastResult ? "前回の探索結果" : "小穴の奥へ進みます。";
            $("eventTitle").textContent = state.lastResult ? "前回の挑戦終了" : "地下入口";
            $("eventLead").textContent = state.lastResult
                ? `${state.lastResult.valmonName} / 記録 ${state.lastResult.bestClearedFloor}階 / スコア ${fmt(state.lastResult.score)}`
                : "「進む」を押すと、ヴァルモンが次の階へ向かいます。";
            $("eventImpact").innerHTML = "";
            return;
        }
        if (!latest) {
            const nextStep = nextRunStep(run);
            $("eventTypeLabel").textContent = "準備完了";
            $("encounterTitle").textContent = `${run.valmonName}が地下入口で耳を澄ませています。`;
            $("eventTitle").textContent = "地下入口";
            $("eventLead").textContent = `${run.valmonName}が小穴の奥を見つめています。`;
            $("eventImpact").innerHTML = `<span>次: 地下${nextStep.floorNumber}階</span><span>ルート: ${activeRoute(run).name}</span>`;
            return;
        }
        $("eventTypeLabel").textContent = eventTypeLabel(latest.eventType);
        $("encounterTitle").textContent = encounterTitleForEvent(latest);
        $("eventTitle").textContent = `地下${latest.floorNumber}階`;
        $("eventLead").innerHTML = escapeHtml(latest.logText).replace(/\n/g, "<br>");
        const hpDelta = Number(latest.hpAfter || 0) - Number(latest.hpBefore || 0);
        $("eventImpact").innerHTML = [
            `<span>結果: ${resultLabel(latest.result)}</span>`,
            `<span>${latest.floorCleared ? "階層突破" : "探索継続"}</span>`,
            `<span>HP ${hpDelta >= 0 ? "+" : ""}${hpDelta}</span>`,
            `<span>スコア +${fmt(latest.scoreDelta)}</span>`,
        ].join("");
    }

    function encounterTitleForEvent(event) {
        const enemy = event?.payload?.enemy;
        if (enemy) return `${enemy.name}が現れた！`;
        return {
            trap: "足元に黒風の罠が走った！",
            treasure: "奥で小さな光がまたたいた。",
            rest: "白風の休憩所を見つけた。",
            walk: "細い地下道が奥へ続いている。",
            omen: "黒い風の気配が流れてきた。",
        }[event?.eventType] || "地下の気配が近づいている。";
    }

    function renderEnemyPanel(run, latest) {
        if (!run) {
            setEnemyPanel({
                name: "地下の気配",
                sub: "挑戦開始待ち",
                hp: "-",
                atk: "-",
                def: "-",
                type: "idle",
            });
            return;
        }
        const enemy = latest?.payload?.enemy;
        if (enemy) {
            setEnemyPanel({
                name: enemy.name,
                sub: latest.eventType === "boss" ? "階層主" : "地下の敵",
                hp: fmt(enemy.hp),
                atk: fmt(enemy.atk),
                def: fmt(enemy.def),
                type: latest.eventType === "boss" ? "boss" : "enemy",
            });
            return;
        }
        const fallback = {
            trap: ["黒風の罠", "罠"],
            treasure: ["光る小箱", "探索"],
            rest: ["白風の休憩所", "小休止"],
            walk: ["細い地下道", "移動探索"],
            omen: ["黒い風", "気配"],
        }[latest?.eventType] || ["地下の気配", `次: 地下${run.currentFloor + 1}階`];
        setEnemyPanel({
            name: fallback[0],
            sub: fallback[1],
            hp: "-",
            atk: "-",
            def: "-",
            type: latest?.eventType || "idle",
        });
    }

    function setEnemyPanel({ name, sub, hp, atk, def, type }) {
        $("runEnemyName").textContent = name;
        $("runEnemySub").textContent = sub;
        $("runEnemyHpText").textContent = hp;
        $("runEnemyAtkText").textContent = atk;
        $("runEnemyDefText").textContent = def;
        setHpBar("runEnemyHpBar", hp === "-" ? 0 : 1, 1);
        $("runEnemyVisual").className = `enemy-visual enemy-${type || "idle"}`;
    }

    function renderBattleTimeline(run, events) {
        const valmon = config.valmons.find((row) => row.id === Number(run.valmonId)) || selectedValmon();
        return events.map((event) => {
            if (event.payload?.frames?.length) {
                return event.payload.frames.map((frame) => renderBattleFrame(run, valmon, event, frame)).join("");
            }
            if (!isCombatEvent(event)) {
                return renderEventFrame(run, valmon, event);
            }
            const frame = {
                title: eventTypeLabel(event.eventType),
                text: event.logText,
                playerHp: event.hpAfter,
                playerMaxHp: run.maxHp,
                enemyHp: null,
                enemyMaxHp: null,
                enemy: event.payload?.enemy || null,
            };
            return renderBattleFrame(run, valmon, event, frame);
        }).join("");
    }

    function renderTrailingLogEvents(run, pageEvent) {
        const events = run.events || [];
        const pageIndex = events.lastIndexOf(pageEvent);
        if (pageIndex < 0) return "";
        return events
            .slice(pageIndex + 1)
            .filter((event) => event.eventType === "log")
            .map((event) => renderRunLogNote(event))
            .join("");
    }

    function renderLeadingChoiceLogEvents(run, pageEvent) {
        const events = run.events || [];
        const pageIndex = events.lastIndexOf(pageEvent);
        if (pageIndex <= 0) return "";
        let previousPageIndex = -1;
        for (let index = pageIndex - 1; index >= 0; index -= 1) {
            if (events[index].eventType !== "log") {
                previousPageIndex = index;
                break;
            }
        }
        return events
            .slice(previousPageIndex + 1, pageIndex)
            .filter((event) => event.eventType === "log" && event.payload?.choiceType)
            .map((event) => renderRunLogNote(event))
            .join("");
    }

    function renderRunLogNote(event) {
        return `<p class="is-note">${formatBattleLogText(event)}</p>`;
    }

    function isCombatEvent(event) {
        return ["combat", "boss"].includes(event.eventType) || Boolean(event.payload?.enemy);
    }

    function eventVisualMeta(event) {
        if (event.result === "mark_trace") {
            return { title: "古い印", label: "印", type: "mark" };
        }
        return {
            trap: { title: "黒風の罠", label: "罠", type: "trap" },
            treasure: { title: "光る小箱", label: "宝箱", type: "treasure" },
            rest: { title: "白風の休憩所", label: "小休止", type: "rest" },
            walk: { title: "細い地下道", label: "移動探索", type: "walk" },
            omen: { title: "黒い風の気配", label: "気配", type: "omen" },
        }[event.eventType] || { title: "地下の気配", label: "出来事", type: "idle" };
    }

    function renderEventFrame(run, valmon, event) {
        const meta = eventVisualMeta(event);
        return `
            <article class="event-turn event-${meta.type}">
                <div class="event-visual event-visual-${meta.type}">
                    <span>${escapeHtml(meta.label)}</span>
                    <strong>${escapeHtml(meta.title)}</strong>
                </div>
                <div class="event-valmon-status">
                    <img src="../../../public/images/valmon/${valmon.image}" alt="">
                    <div>
                        <strong>${escapeHtml(run.valmonName)}</strong>
                        <small>Lv${run.valmonLevel}</small>
                        <div class="combatant-hp">
                            <span>HP</span>
                            <strong>${fmt(event.hpAfter)} / ${fmt(run.maxHp)}</strong>
                            <div class="bar"><div class="${hpFillClass(event.hpAfter, run.maxHp)}" style="width:${hpPercent(event.hpAfter, run.maxHp)}%"></div></div>
                        </div>
                    </div>
                </div>
                <div class="event-log">
                    <strong>--- 地下${event.floorNumber}階 ${escapeHtml(meta.label)} ---</strong>
                    <p>${formatBattleLogText({ logText: event.logText })}</p>
                </div>
            </article>
        `;
    }

    function renderBattleFrame(run, valmon, event, frame) {
        const enemy = frame.enemy;
        const enemyName = enemy?.name || frame.title || "地下の気配";
        const enemySub = enemy ? (event.eventType === "boss" ? "階層主" : "地下の敵") : eventTypeLabel(event.eventType);
        const frameTitle = frame.title || eventTypeLabel(event.eventType);
        if (["突破", "敗退", "辛勝"].includes(frameTitle)) {
            return `
                <article class="battle-turn battle-turn-result">
                    <div class="turn-log">
                        <strong>--- 地下${event.floorNumber}階 ${escapeHtml(frameTitle)} ---</strong>
                        <p>${formatBattleLogText({ logText: frame.text })}</p>
                    </div>
                </article>
            `;
        }
        const enemyHpText = frame.enemyMaxHp ? `${fmt(frame.enemyHp)} / ${fmt(frame.enemyMaxHp)}` : "-";
        const enemyType = enemy ? (event.eventType === "boss" ? "boss" : "enemy") : event.eventType;
        return `
            <article class="battle-turn">
                <div class="battle-arena turn-arena">
                    <div class="combatant-card combatant-player">
                        <div class="combatant-hp">
                            <span>HP</span>
                            <strong>${fmt(frame.playerHp)} / ${fmt(frame.playerMaxHp)}</strong>
                            <div class="bar"><div class="${hpFillClass(frame.playerHp, frame.playerMaxHp)}" style="width:${hpPercent(frame.playerHp, frame.playerMaxHp)}%"></div></div>
                        </div>
                        <img src="../../../public/images/valmon/${valmon.image}" alt="">
                        <strong>${escapeHtml(run.valmonName)}</strong>
                        <small>Lv${run.valmonLevel}</small>
                    </div>
                    <div class="battle-vs">VS</div>
                    <div class="combatant-card combatant-enemy">
                        <div class="combatant-hp">
                            <span>HP</span>
                            <strong>${enemyHpText}</strong>
                            <div class="bar"><div class="${hpFillClass(frame.enemyHp, frame.enemyMaxHp)}" style="width:${hpPercent(frame.enemyHp, frame.enemyMaxHp)}%"></div></div>
                        </div>
                        <div class="enemy-visual enemy-${enemyType || "idle"}" aria-hidden="true"></div>
                        <strong>${escapeHtml(enemyName)}</strong>
                        <small>${escapeHtml(enemySub)}</small>
                    </div>
                </div>
                <div class="turn-log">
                    <strong>--- 地下${event.floorNumber}階 ${escapeHtml(frameTitle)} ---</strong>
                    <p>${formatBattleLogText({ logText: frame.text })}</p>
                </div>
            </article>
        `;
    }

    function hpPercent(current, max) {
        if (!max || current === null || current === undefined) return 0;
        return clamp(Math.round((Math.max(0, current) / max) * 100), 0, 100);
    }

    function hpFillClass(current, max) {
        const percent = hpPercent(current, max);
        const tone = percent <= 20 ? "danger" : percent <= 50 ? "warn" : "safe";
        return `bar-fill hp ${tone}`;
    }

    function setHpBar(id, current, max) {
        const node = $(id);
        node.style.width = `${hpPercent(current, max)}%`;
        node.className = hpFillClass(current, max);
    }

    function formatBattleLogText(event) {
        return escapeHtml(event.logText)
            .replace(/(\d+)(ダメージ)/g, `<strong class="damage-number">$1</strong>$2`)
            .replace(/\n/g, "<br>");
    }

    function eventTypeLabel(type) {
        return {
            boss: "階層主",
            combat: "戦闘",
            trap: "罠",
            treasure: "宝箱",
            rest: "小休止",
            walk: "移動探索",
            omen: "気配",
        }[type] || "出来事";
    }

    function resultLabel(result) {
        return {
            clear: "突破",
            timeout_clear: "辛勝",
            boss_timeout: "敗退",
            avoided: "回避",
            hit: "被弾",
            opened: "発見",
            missed: "見送り",
            rested: "回復",
            walked: "移動",
            mark_trace: "印",
            avoided_omen: "回避",
            scored: "読破",
        }[result] || result;
    }

    function summaryItems(run) {
        return [
            { label: "記録", value: `${fmt(run.bestClearedFloor)}階` },
            { label: "撃破", value: fmt(run.killCount + run.bossKillCount) },
            { label: "宝箱", value: fmt(run.treasureCount) },
            { label: "待機選択", value: run.pendingChoices.length ? `${run.pendingChoices.length}件` : "なし" },
        ];
    }

    function nextMultipleAfter(current, step) {
        return Math.ceil((current + 1) / step) * step;
    }

    function renderStatus() {
        const run = displayRun();
        const isActive = Boolean(state.activeRun);
        const valmon = run
            ? (config.valmons.find((row) => row.id === Number(run.valmonId)) || selectedValmon())
            : draftValmon();
        const setting = run ? null : settingForValmon(valmon);
        const stats = run ? runStats(run) : calculateStats(valmon, setting.tuning, emptyTuning(), state.tower.deckCardCodes, null);
        $("statusStateLabel").textContent = isActive ? "探索中" : run ? "探索終了" : "準備中";
        $("statusValmonName").textContent = run ? run.valmonName : valmon.name;
        $("statusValmonMeta").textContent = `Lv${run ? run.valmonLevel : valmon.level}`;
        $("statusHpText").textContent = run ? `${fmt(run.currentHp)} / ${fmt(run.maxHp)}` : `${fmt(stats.maxHp)} / ${fmt(stats.maxHp)}`;
        setHpBar("statusHpBar", run ? run.currentHp : stats.maxHp, run ? run.maxHp : stats.maxHp);
        $("statusFloorText").textContent = `${fmt(state.tower.highestClearedFloor)}階`;
        $("statusRouteText").textContent = run ? activeRoute(run).name : "挑戦前";
        $("statusScoreText").textContent = fmt(Math.max(Number(state.tower.score || 0), Number(run?.score || 0)));
        $("statusNextText").textContent = `次は地下${fmt(state.tower.currentFloorToChallenge)}階`;
        $("statusTuningChips").innerHTML = statKeys.map((key) => `
            <span class="run-chip">${statIcon(key)}${config.tuning.labels[key]} ${stats.tuning[key]}</span>
        `).join("");
        const orbs = run ? allRunOrbs(run) : state.tower.deckCardCodes.map(cardByCode).filter(Boolean);
        $("statusOrbChips").innerHTML = orbs.map((orb) => `
            <span class="run-chip">${orbIcon(orb)}${escapeHtml(orb.name)}</span>
        `).join("") || `<span class="muted">カード未装備</span>`;
    }

    function renderChoices() {
        const run = state.activeRun;
        const panel = $("choicePanel");
        if (!run || !run.pendingChoices.length) {
            panel.hidden = true;
            return;
        }
        const choice = run.pendingChoices[0];
        panel.hidden = false;
        if (choice.type === "route") {
            $("choiceTitle").textContent = `${run.valmonName}が分かれ道で足を止めた。`;
            $("choiceLead").textContent = `地下${run.currentFloor + 1}階の奥から、いくつもの気配が流れてくる。`;
            $("choiceOptions").innerHTML = choice.options.map((code) => {
                const route = config.routes[code];
                const isSelected = choice.selected === code;
                return `<button type="button" class="choice-card${isSelected ? " selected" : ""}" data-choice-route="${code}" aria-pressed="${isSelected ? "true" : "false"}">
                    <strong>${route.name}</strong>
                    <span>${escapeHtml(route.desc)}</span>
                </button>`;
            }).join("");
        } else if (choice.type === "temporary_tuning") {
            $("choiceTitle").textContent = "白風の加護を得た。";
            $("choiceLead").textContent = "今回の挑戦中だけ、調律を1つ強化できます。";
            $("choiceOptions").innerHTML = statKeys.map((key) => `
                <button type="button" class="choice-card${choice.selected === key ? " selected" : ""}" data-choice-tuning="${key}" aria-pressed="${choice.selected === key ? "true" : "false"}">
                    ${statIcon(key)}
                    <span class="choice-card-body">
                        <strong>${config.tuning.labels[key]} +1</strong>
                        <span>このrun中だけ有効</span>
                    </span>
                </button>
            `).join("");
        } else if (choice.type === "card_reward") {
            $("choiceTitle").textContent = choice.choiceType === "coin"
                ? "金貨がカードを呼んだ。"
                : choice.choiceType === "bp"
                    ? "深層BPでカードを引き寄せた。"
                    : `${run.valmonName}が階層の奥でカードを見つけた。`;
            $("choiceLead").textContent = "次の3枚から1枚を選べます。選ぶまで候補は固定されます。";
            $("choiceOptions").innerHTML = choice.options.map((code) => {
                const card = cardByCode(code);
                const isSelected = choice.selected === code;
                return `<button type="button" class="choice-card${isSelected ? " selected" : ""}" data-choice-card="${code}" aria-pressed="${isSelected ? "true" : "false"}">
                    ${orbIcon(card)}
                    <span class="choice-card-body">
                        <strong>${escapeHtml(card.name)}</strong>
                        <span>${categoryLabel(card.category)} / ${card.rarity || "N"}</span>
                        <p class="muted">${escapeHtml(card.description)}</p>
                    </span>
                </button>`;
            }).join("");
        } else {
            $("choiceTitle").textContent = "地下の一時カードが反応している。";
            $("choiceLead").textContent = run.tempOrbCode
                ? `現在の一時カード: ${config.tempOrbs.find((orb) => orb.code === run.tempOrbCode)?.name || "なし"}`
                : "今回の挑戦中だけ、1つ選べます。";
            const keep = run.tempOrbCode
                ? `<button type="button" class="choice-card${choice.selected === "keep" ? " selected" : ""}" data-choice-orb="keep" aria-pressed="${choice.selected === "keep" ? "true" : "false"}"><strong>維持する</strong><span>今の一時カードを使い続ける</span></button>`
                : "";
            $("choiceOptions").innerHTML = keep + choice.options.map((code) => {
                const orb = config.tempOrbs.find((row) => row.code === code);
                const isSelected = choice.selected === code;
                return `<button type="button" class="choice-card${isSelected ? " selected" : ""}" data-choice-orb="${code}" aria-pressed="${isSelected ? "true" : "false"}">
                    ${orbIcon(orb)}
                    <span class="choice-card-body">
                        <strong>${orb.name}</strong>
                        <span>${orb.description}</span>
                    </span>
                </button>`;
            }).join("");
        }
    }

    function renderRanking() {
        const seasonKey = activeSeasonKey();
        if (!state.rankings[seasonKey]) {
            state.rankings[seasonKey] = seedRankingRows();
            saveState();
        }
        $("rankingSeasonLabel").textContent = `${state.season.name} / 金曜09:00〜月曜09:00`;
        const rows = sortRanking(state.rankings[seasonKey]);
        renderTopRanking(rows);
        $("rankingTable").innerHTML = rows.map((row, index) => `
            <div class="ranking-row">
                <div class="rank-no">${index + 1}位</div>
                <div><strong>${escapeHtml(row.characterName)}</strong><br><span class="muted">${escapeHtml(row.valmonName)}</span></div>
                <div><span class="muted">記録</span><br><strong>${row.bestFloor}階</strong></div>
                <div><span class="muted">スコア</span><br><strong>${fmt(row.bestScore)}</strong></div>
                <div><span class="muted">挑戦</span><br>${fmt(row.attemptCount || 0)}回</div>
                <div><span class="muted">達成</span><br>${formatDate(row.achievedAt)}</div>
            </div>
        `).join("");
    }

    function renderTopRanking(rows) {
        const html = rows.slice(0, 3).map((row, index) => `
            <div class="ranking-mini-row">
                <strong>${index + 1}位</strong>
                <span>${escapeHtml(row.characterName)}</span>
                <span>${escapeHtml(row.valmonName)}</span>
                <b>${row.bestFloor}階</b>
            </div>
        `).join("");
        $("rankingTop3").innerHTML = html;
    }

    function updateActionStates() {
        const startCheck = canStartRun();
        const hasStamina = Number(state.stamina?.current || 0) > 0;
        const run = state.activeRun;
        const currentChoice = run?.pendingChoices?.[0] || null;
        const waitsForChoiceSelection = Boolean(currentChoice && !currentChoice.selected);
        $("startRunButton").disabled = !startCheck.ok;
        $("startRunButton").textContent = startCheck.action === "resume" ? "挑戦へ戻る" : "挑戦開始";
        $("departureStartButton").disabled = isAdvancing || !run || !hasStamina;
        $("departureStartButton").classList.toggle("loading", isAdvancing);
        $("departureStartButton").textContent = isAdvancing ? "探索中..." : hasStamina ? "探索へ" : "探索力不足";
        $("advanceButton").disabled = isAdvancing || !run || waitsForChoiceSelection || !hasStamina;
        $("advanceButton").classList.toggle("loading", isAdvancing);
        $("deckEditButton").disabled = isAdvancing || !run;
        ["retireLink", "departureRetireLink"].forEach((id) => {
            $(id).classList.toggle("disabled", isAdvancing || !run);
            $(id).setAttribute("aria-disabled", !isAdvancing && run ? "false" : "true");
        });
        $("advanceButton").textContent = isAdvancing
            ? "探索中..."
            : !run
            ? "探索終了"
            : waitsForChoiceSelection
                ? "選択待ち"
            : !hasStamina
                ? "探索力不足"
                : "さらに奥に進む";
        $("coinCardButton").disabled = Boolean(state.activeRun?.pendingChoices?.length) || availableCoinCount() <= 0;
        $("bpCardButton").disabled = Boolean(state.activeRun?.pendingChoices?.length) || state.tower.bp < config.bp.cardChoiceCost;
        $("navExplore").classList.toggle("active", currentScreen === "prepare" || currentScreen === "departure" || currentScreen === "run");
        $("navRanking").classList.toggle("active", currentScreen === "ranking");
        $("navStatus").classList.toggle("active", currentScreen === "status");
    }

    function formatDate(iso) {
        const date = new Date(iso);
        return `${date.getMonth() + 1}/${date.getDate()} ${String(date.getHours()).padStart(2, "0")}:${String(date.getMinutes()).padStart(2, "0")}`;
    }

    function escapeHtml(value) {
        return String(value || "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    document.addEventListener("click", (event) => {
        const target = event.target.closest("button");
        if (!target) return;
        if (target.dataset.appTab) showAppTab(target.dataset.appTab);
        if (target.dataset.screen) showScreen(target.dataset.screen);
        if (target.dataset.modalClose !== undefined) closeRulesModal();
        if (target.dataset.retireCancel !== undefined) closeRetireModal();
        if (target.dataset.coinClose !== undefined) closeCoinModal();
        if (target.dataset.valmonCycle) cycleDraftValmon(target.dataset.valmonCycle);
        if (target.dataset.orbCode) toggleOrb(target.dataset.orbCode);
        if (target.dataset.tuning) {
            const setting = draftSetting();
            const key = target.dataset.tuning;
            const delta = Number(target.dataset.delta);
            setting.tuning[key] = clamp(Number(setting.tuning[key] || 0) + delta, 0, config.tuning.statCap);
            saveState();
            render();
        }
        if (target.dataset.choiceRoute) chooseRoute(target.dataset.choiceRoute);
        if (target.dataset.choiceTuning) chooseTemporaryTuning(target.dataset.choiceTuning);
        if (target.dataset.choiceCard) chooseCard(target.dataset.choiceCard);
        if (target.dataset.choiceOrb) chooseTemporaryOrb(target.dataset.choiceOrb);
        if (target.dataset.bpAction) spendBp(target.dataset.bpAction);
    });

    $("rulesButton").addEventListener("click", openRulesModal);
    $("rulesModal").addEventListener("click", (event) => {
        if (event.target.id === "rulesModal") closeRulesModal();
    });
    $("retireModal").addEventListener("click", (event) => {
        if (event.target.id === "retireModal") closeRetireModal();
    });
    $("coinModal").addEventListener("click", (event) => {
        if (event.target.id === "coinModal") closeCoinModal();
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && !$("rulesModal").hidden) closeRulesModal();
        if (event.key === "Escape" && !$("retireModal").hidden) closeRetireModal();
        if (event.key === "Escape" && !$("coinModal").hidden) closeCoinModal();
    });
    $("startRunButton").addEventListener("click", startRun);
    $("coinCardButton").addEventListener("click", beginCoinCardChoice);
    $("coinClaimButton").addEventListener("click", claimCoin);
    $("bpCardButton").addEventListener("click", beginBpCardChoice);
    $("departureStartButton").addEventListener("click", requestAdvanceRun);
    $("advanceButton").addEventListener("click", requestAdvanceRun);
    $("deckEditButton").addEventListener("click", () => showScreen("prepare"));
    $("retireLink").addEventListener("click", openRetireModal);
    $("departureRetireLink").addEventListener("click", openRetireModal);
    $("retireConfirmButton").addEventListener("click", () => {
        closeRetireModal();
        retireRun();
    });
    $("detailsRankingButton").addEventListener("click", () => showScreen("ranking"));
    $("rankingBackButton").addEventListener("click", () => showScreen(state.activeRun ? explorationScreenForRun(state.activeRun) : "prepare"));
    render();
    window.setInterval(renderCoinClaimStatus, 1000);
})();

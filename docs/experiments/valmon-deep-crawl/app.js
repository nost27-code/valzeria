(function () {
    "use strict";

    const config = window.ValmonDeepConfig;
    const storageKey = "valmonDeepCrawlExperiment:v1";
    const screens = ["prepare", "departure", "run", "result", "card-choice", "ranking", "status"];
    const statKeys = ["attack", "defense", "detect", "evasion"];
    const staminaMax = 265;
    const deckSlotDefault = config.deck?.initialSlotLimit || 20;
    const hpRecoveryIntervalMs = (config.hpRecovery?.intervalMinutes || 5) * 60 * 1000;
    const hpRecoveryRate = config.hpRecovery?.rate || 0.1;
    const goldRestHealRate = config.gold?.restHealRate || 0.3;
    const goldRestCostPerFloor = config.gold?.restCostPerFloor || 150;
    const cardArtNumberByCategory = {
        attack: 1,
        defense: 2,
        explore: 3,
        evasion: 4,
        一時攻撃: 1,
        一時防御: 2,
        一時探索: 3,
        一時安全: 4,
        一時挑戦: 4,
    };
    const cardArtRarityOffset = {
        N: 0,
        R: 4,
        SR: 8,
        SSR: 8,
        UR: 12,
    };

    const $ = (id) => document.getElementById(id);
    const fmt = (value) => Number(value || 0).toLocaleString("ja-JP");
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
    const nowIso = () => new Date().toISOString();

    let state = loadState();
    let currentScreen = "prepare";
    let isAdvancing = false;
    let stageEnterTimer = null;
    let cardDealTimer = null;
    let pendingBpAction = null;
    let pendingTuningUpgradeStat = null;
    let deckFilter = "all";
    let deckSort = "equipped";

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
        // 旧モデル（連続ラン方式）のランは1階層=1出撃モデルと互換がないため破棄する。
        // 未確定のカード3択だけはタワー側に引き継ぐ。
        if (merged.activeRun && !merged.activeRun.sortie) {
            const oldChoice = (merged.activeRun.pendingChoices || []).find((choice) => choice.type === "card_reward");
            if (oldChoice && !merged.tower.pendingCardChoice) {
                merged.tower.pendingCardChoice = normalizePendingCardChoice({
                    choiceType: oldChoice.choiceType || "coin",
                    sourceFloor: oldChoice.sourceFloor || null,
                    options: oldChoice.options || [],
                    selected: oldChoice.selected || null,
                    origin: "prepare",
                });
            }
            merged.activeRun = null;
        }
        if (merged.lastResult && !merged.lastResult.sortie) {
            merged.lastResult = null;
        }
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
            defeatCount: 0,
            bp: 0,
            spentBp: 0,
            bpActionCount: 0,
            deckSlotExpandCount: 0,
            seasonTuningBonusPoints: 0,
            coinTuningPointCount: 0,
            bpTuningPointCount: 0,
            upgrades: { attack: 0, defense: 0, detect: 0, evasion: 0, hp: 0 },
            deckSlotLimit: deckSlotDefault,
            currentFloorToChallenge: 1,
            highestClearedFloor: 0,
            score: 0,
            currentHp: config.baseStats.hp,
            maxHp: config.baseStats.hp,
            lastHpRecoveredAt: nowIso(),
            gold: config.gold?.initial || 0,
            attemptCount: 0,
            clearedRewardFloors: [],
            bpRewardFloors: [],
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
        tower.bpActionCount = Math.max(0, Number(tower.bpActionCount || 0));
        tower.deckSlotExpandCount = Math.max(0, Number(tower.deckSlotExpandCount || Math.max(0, tower.deckSlotLimit - deckSlotDefault)));
        tower.seasonTuningBonusPoints = Math.max(0, Number(tower.seasonTuningBonusPoints || 0));
        tower.coinTuningPointCount = Math.max(0, Number(tower.coinTuningPointCount || 0));
        tower.bpTuningPointCount = Math.max(0, Number(tower.bpTuningPointCount || 0));
        tower.usedCoinCount = clamp(Number(tower.usedCoinCount || 0), 0, config.season.naturalCoinMax);
        tower.claimedCoinCount = clamp(
            Number(source.claimedCoinCount ?? source.usedCoinCount ?? 0),
            tower.usedCoinCount,
            config.season.naturalCoinMax
        );
        tower.currentFloorToChallenge = clamp(Number(tower.currentFloorToChallenge || 1), 1, config.season.maxFloorTarget);
        tower.highestClearedFloor = clamp(Number(tower.highestClearedFloor || 0), 0, config.season.maxFloorTarget);
        tower.score = Math.max(0, Number(tower.score || 0));
        tower.defeatCount = Math.max(0, Number(tower.defeatCount || 0));
        tower.maxHp = Math.max(1, Number(tower.maxHp || config.baseStats.hp));
        tower.currentHp = clamp(Number(tower.currentHp ?? tower.maxHp), 0, tower.maxHp);
        tower.lastHpRecoveredAt = source.lastHpRecoveredAt || nowIso();
        tower.gold = Math.max(0, Number(tower.gold ?? config.gold?.initial ?? 0));
        tower.clearedRewardFloors = uniqueCodes(source.clearedRewardFloors || []).map(Number);
        tower.bpRewardFloors = uniqueCodes(source.bpRewardFloors || []).map(Number);
        tower.pendingCardChoice = normalizePendingCardChoice(source.pendingCardChoice);
        return tower;
    }

    function normalizePendingCardChoice(source) {
        if (!source || !Array.isArray(source.options)) return null;
        const options = uniqueCodes(source.options).filter(cardByCode);
        if (!options.length) return null;
        return {
            choiceType: source.choiceType || "coin",
            sourceFloor: source.sourceFloor || null,
            options,
            selected: options.includes(source.selected) ? source.selected : null,
            origin: source.origin === "result" ? "result" : "prepare",
        };
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
        const categories = ["attack", "defense", "heal", "explore", "evasion", "explore"];
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
            heal: "自然回復や休息の戻りが少し強くなる",
            explore: "探知が冴え、宝箱やスコアの伸びがよくなる",
            evasion: "罠や黒風を少しかわしやすくなる",
        }[category] || "今シーズンだけ有効なカード";
    }

    function generatedCardEffect(category, index) {
        return {
            attack: { damageDealtRate: 1 + ((index % 4) + 3) / 100 },
            defense: { damageTakenRate: 1 - ((index % 4) + 3) / 100 },
            heal: { milestoneHealBonus: ((index % 3) + 3) / 100 },
            explore: { eventScoreRate: 1 + ((index % 4) + 4) / 100 },
            evasion: { trapAvoidBonus: ((index % 4) + 3) / 100 },
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

    function defaultTuningFor() {
        // 調律は振り直し不可のため、初期状態は未割り振り。プレイヤーが自分で1点ずつ振る。
        return { attack: 0, defense: 0, detect: 0, evasion: 0 };
    }

    function tuningPointLimit(level, bonusPoints = 0) {
        const base = config.tuning.pointsByLevel.reduce((points, rule) => (
            level >= rule.level ? rule.points : points
        ), config.tuning.pointsByLevel[0].points);
        return base + Number(bonusPoints || 0);
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
        playScreenEffects(screen);
        scrollPageTop();
    }

    function playScreenEffects(screen) {
        if (screen === "card-choice") {
            const row = $("cardChoiceOptions");
            row.classList.remove("dealing");
            void row.offsetWidth;
            row.classList.add("dealing");
            window.clearTimeout(cardDealTimer);
            cardDealTimer = window.setTimeout(() => row.classList.remove("dealing"), 1000);
        } else if (screen === "run") {
            const stage = $("eventStage");
            stage.classList.remove("stage-enter");
            void stage.offsetWidth;
            stage.classList.add("stage-enter");
            window.clearTimeout(stageEnterTimer);
            stageEnterTimer = window.setTimeout(() => stage.classList.remove("stage-enter"), 1900);
        }
    }

    function pendingCardChoice() {
        return state.tower.pendingCardChoice || null;
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
        if (pendingCardChoice()) return "card-choice";
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

    function openCoinModal() {
        $("coinModal").hidden = false;
    }

    function closeCoinModal() {
        $("coinModal").hidden = true;
    }

    function openGoldRestModal() {
        applyHpRecovery();
        renderGoldRestModal();
        $("goldRestModal").hidden = false;
    }

    function closeGoldRestModal() {
        $("goldRestModal").hidden = true;
    }

    function renderGoldRestModal() {
        const tower = state.tower;
        const cost = goldRestCost();
        const heal = goldRestHealAmount();
        const hp = clamp(Number(tower.currentHp || 0), 0, Number(tower.maxHp || config.baseStats.hp));
        const maxHp = Math.max(1, Number(tower.maxHp || config.baseStats.hp));
        $("goldRestText").textContent = `地下${fmt(tower.currentFloorToChallenge || 1)}階の休息費用は${fmt(cost)}Gです。HPを最大${fmt(heal)}回復します。現在HP ${fmt(hp)} / ${fmt(maxHp)}、所持Gold ${fmt(tower.gold || 0)}G。`;
        $("goldRestConfirmButton").disabled = hp >= maxHp || Number(tower.gold || 0) < cost;
    }

    function confirmGoldRest() {
        applyHpRecovery();
        const tower = state.tower;
        const cost = goldRestCost();
        const maxHp = Math.max(1, Number(tower.maxHp || config.baseStats.hp));
        if (Number(tower.currentHp || 0) >= maxHp) {
            flash("HPはすでに満タンです。");
            closeGoldRestModal();
            render();
            return;
        }
        if (Number(tower.gold || 0) < cost) {
            flash(`Goldが足りません。必要Gold: ${fmt(cost)}G`, "error");
            renderGoldRestModal();
            return;
        }
        const before = Number(tower.currentHp || 0);
        tower.gold = Math.max(0, Number(tower.gold || 0) - cost);
        tower.currentHp = clamp(before + goldRestHealAmount(), 0, maxHp);
        tower.lastHpRecoveredAt = nowIso();
        saveState();
        closeGoldRestModal();
        flash(`${fmt(cost)}Gで休息し、HPが${fmt(tower.currentHp - before)}回復しました。`);
        render();
    }

    function openBpConfirmModal(action) {
        const meta = bpActionMeta(action);
        if (!meta) return;
        if (meta.disabled) {
            flash(meta.disabledMessage || "TPが足りません。", "error");
            return;
        }
        pendingBpAction = action;
        $("bpConfirmTitle").textContent = meta.title;
        $("bpConfirmText").textContent = meta.text;
        $("bpConfirmButton").textContent = meta.confirmLabel || "TPを使う";
        $("bpConfirmModal").hidden = false;
    }

    function closeBpConfirmModal() {
        $("bpConfirmModal").hidden = true;
        pendingBpAction = null;
    }

    function openTuningUpgradeModal(statKey) {
        if (!statKeys.includes(statKey)) return;
        const setting = draftSetting();
        if (Number(setting.tuning[statKey] || 0) >= config.tuning.statCap) {
            flash(`${config.tuning.labels[statKey]}は項目上限です。`, "error");
            return;
        }
        pendingTuningUpgradeStat = statKey;
        renderTuningUpgradeModal();
        $("tuningUpgradeModal").hidden = false;
    }

    function closeTuningUpgradeModal() {
        $("tuningUpgradeModal").hidden = true;
        pendingTuningUpgradeStat = null;
    }

    function openValmonSelectModal() {
        renderValmonSelectModal();
        $("valmonSelectModal").hidden = false;
    }

    function closeValmonSelectModal() {
        $("valmonSelectModal").hidden = true;
    }

    function renderValmonSelectModal() {
        const currentId = Number(state.draftValmonId);
        $("valmonSelectList").innerHTML = config.valmons.map((valmon) => {
            const limit = tuningPointLimit(valmon.level, state.tower?.seasonTuningBonusPoints || 0);
            const isSelected = Number(valmon.id) === currentId;
            return `<button type="button" class="valmon-select-card${isSelected ? " selected" : ""}" data-valmon-pick="${valmon.id}" aria-pressed="${isSelected ? "true" : "false"}">
                <img src="../../../public/images/valmon/${valmon.image}" alt="">
                <span>
                    <strong>${escapeHtml(valmon.name)}</strong>
                    <small>Lv${fmt(valmon.level)} / 基礎能力値 ${fmt(limit)}</small>
                </span>
                <b>${isSelected ? "選択中" : "選ぶ"}</b>
            </button>`;
        }).join("");
    }

    function pickDraftValmon(valmonId) {
        const valmon = config.valmons.find((row) => row.id === Number(valmonId));
        if (!valmon) return;
        state.draftValmonId = valmon.id;
        state.selectedValmonId = valmon.id;
        saveState();
        closeValmonSelectModal();
        flash(`${valmon.name}に入れ替えました。`);
        render();
    }

    function openCardDrawModal() {
        if (pendingCardChoice()) {
            flash("先に表示中のカード3択を決めてください。", "error");
            showScreen("card-choice");
            return;
        }
        renderCardDrawModal();
        $("cardDrawModal").hidden = false;
    }

    function closeCardDrawModal() {
        $("cardDrawModal").hidden = true;
    }

    function renderCardDrawModal() {
        const pendingChoice = Boolean(pendingCardChoice());
        const coinCost = config.coin.cardChoiceCost || 1;
        const normalCost = nextBpActionCost();
        const coinAvailable = availableCoinCount();
        $("cardDrawCoinButton").innerHTML = `
            <span class="draw-cost-label">金貨${coinCost}枚を消費して引く</span>
            <span class="draw-balance-label">残 ${fmt(coinAvailable)} 枚</span>
        `;
        $("cardDrawCoinButton").disabled = pendingChoice || coinAvailable < coinCost;
        $("cardDrawTpButton").innerHTML = `
            <span class="draw-cost-label">TPを${fmt(normalCost)}消費して引く</span>
            <span class="draw-balance-label">残 ${fmt(state.tower.bp)} TP</span>
        `;
        $("cardDrawTpButton").disabled = pendingChoice || state.tower.bp < normalCost;
    }

    function beginCardChoiceFromModal(source) {
        closeCardDrawModal();
        if (source === "coin") {
            beginCoinCardChoice();
        } else if (source === "tp") {
            beginBpCardChoice();
        }
    }

    function renderTuningUpgradeModal() {
        const statKey = pendingTuningUpgradeStat;
        if (!statKey) return;
        const normalCost = nextBpActionCost();
        const coinCost = config.coin.tuningPointCost || 1;
        const coinAvailable = availableCoinCount();
        const coinLimitReached = state.tower.coinTuningPointCount >= (config.coin.tuningPointPurchaseLimit || 10);
        $("tuningUpgradeTitle").textContent = `${config.tuning.labels[statKey]}を1上げますか`;
        $("tuningUpgradeText").textContent = `追加TPを1つ得て、${config.tuning.labels[statKey]}に振り分けます。`;
        $("tuningUpgradeCoinButton").textContent = `金貨${coinCost}枚を消費`;
        $("tuningUpgradeCoinButton").disabled = coinAvailable < coinCost || coinLimitReached;
        $("tuningUpgradeTpButton").textContent = `TPを${normalCost}消費`;
        $("tuningUpgradeTpButton").disabled = state.tower.bp < normalCost;
    }

    function applyPurchasedTuningPoint(source) {
        const statKey = pendingTuningUpgradeStat;
        if (!statKey) return;
        const setting = draftSetting();
        if (Number(setting.tuning[statKey] || 0) >= config.tuning.statCap) {
            flash(`${config.tuning.labels[statKey]}は項目上限です。`, "error");
            closeTuningUpgradeModal();
            return;
        }
        if (source === "coin") {
            if (state.tower.coinTuningPointCount >= (config.coin.tuningPointPurchaseLimit || 10)) {
                flash("金貨で買えるTPは上限です。", "error");
                renderTuningUpgradeModal();
                return;
            }
            if (!consumeCoin(config.coin.tuningPointCost || 1)) {
                flash("使用できる金貨がありません。", "error");
                renderTuningUpgradeModal();
                return;
            }
            state.tower.coinTuningPointCount += 1;
        } else if (source === "tp") {
            const cost = nextBpActionCost();
            if (!consumeBpNormalAction(cost)) {
                flash("TPが足りません。", "error");
                renderTuningUpgradeModal();
                return;
            }
            state.tower.bpTuningPointCount += 1;
        } else {
            return;
        }
        state.tower.seasonTuningBonusPoints += 1;
        setting.tuning[statKey] = Number(setting.tuning[statKey] || 0) + 1;
        syncActiveRunLoadout();
        saveState();
        flash(`${config.tuning.labels[statKey]}が1上がりました。`);
        closeTuningUpgradeModal();
        render();
    }

    function confirmBpAction() {
        const action = pendingBpAction;
        if (!action) return;
        closeBpConfirmModal();
        if (action === "card") {
            beginBpCardChoice();
        } else if (action === "tuning") {
            buyBpTuningPoint();
        } else {
            spendBp(action);
        }
    }

    function validateSetting(valmon = selectedValmon()) {
        const setting = settingForValmon(valmon);
        const limit = tuningPointLimit(valmon.level, state.tower?.seasonTuningBonusPoints || 0);
        const total = statKeys.reduce((sum, key) => sum + Number(setting.tuning[key] || 0), 0);
        const capOver = statKeys.find((key) => Number(setting.tuning[key] || 0) > config.tuning.statCap);
        if (total < limit) {
            return { ok: false, target: "tuning", message: "TPが余っています。" };
        }
        if (total > limit) {
            return { ok: false, target: "tuning", message: "TPが上限を超えています。" };
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
        applyHpRecovery();
        if (Number(state.tower.currentHp || 0) <= 0) {
            return { ok: false, target: "hp", message: "HPが足りません。自然回復を待つか、Goldで休息してください。" };
        }
        if (pendingCardChoice()) {
            return { ok: false, target: "card-choice", message: "先に表示中のカード3択を決めてください。" };
        }
        const settingValidation = validateSetting(draftValmon());
        if (!settingValidation.ok) {
            return { ok: false, message: settingValidation.message };
        }
        return { ok: true, action: "start" };
    }

    function startRun(fromResult = false) {
        const check = canStartRun();
        if (!check.ok) {
            flash(check.message, "error");
            if (check.target === "card-choice") {
                showScreen("card-choice");
                return;
            }
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
        syncTowerHpMax(stats.maxHp);
        applyHpRecovery();
        if (Number(tower.currentHp || 0) <= 0) {
            flash("HPが足りません。自然回復を待つか、Goldで休息してください。", "error");
            render();
            return;
        }
        const targetFloor = clamp(Number(tower.currentFloorToChallenge || 1), 1, config.season.maxFloorTarget);
        const run = {
            id: `run_${Date.now()}`,
            sortie: true,
            characterName: "試験冒険者",
            valmonId: valmon.id,
            valmonName: valmon.name,
            valmonLevel: valmon.level,
            seasonCode: state.season.code,
            mode: "ranking",
            isRanked: true,
            status: "active",
            sortieResult: null,
            targetFloor,
            currentFloor: targetFloor - 1,
            bestClearedFloor: Number(tower.highestClearedFloor || 0),
            maxHp: stats.maxHp,
            currentHp: clamp(Number(tower.currentHp || 0), 0, stats.maxHp),
            baseTuning,
            tempTuning: emptyTuning(),
            equippedOrbCodes: [...deckCodes],
            tempOrbCode: null,
            activeRouteType: "safe",
            activeFloorNumber: null,
            currentFloorEventIndex: 0,
            currentFloorEventTotal: 0,
            floorEventPlans: {},
            pendingChoices: [],
            score: 0,
            rewardBp: 0,
            firstClear: false,
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
        addRunLog(run, `${valmon.name}は地下${targetFloor}階へ出撃した。HP ${fmt(run.currentHp)} / ${fmt(run.maxHp)}。`);
        saveState();
        if (fromResult) {
            // 結果画面からの再出撃はワンタップで最初のイベントまで進める
            showScreen("run");
            requestAdvanceRun();
        } else {
            flash(`地下${targetFloor}階へ出撃しました。`);
            showScreen("departure");
        }
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
            flash(`装備上限です。カードは${tower.deckSlotLimit}枚まで装備できます。`, "error");
            return;
        } else {
            tower.deckCardCodes = [...codes, orbCode];
        }
        syncActiveRunLoadout();
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

    function towerDeckBonus(key) {
        return (state.tower?.deckCardCodes || []).map(cardByCode).filter(Boolean).reduce((value, card) => {
            const effectValue = card.effect?.[key];
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
            return `${name}は全身の力配分をなじませ、落ち着いた足取りで入口を見つめています。`;
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
        return `${name}はTP配分とデッキカードの感触を確かめ、静かに探索の合図を待っています。`;
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
            flash("進行中の出撃がありません。", "error");
            return;
        }
        if (pendingCardChoice()) {
            showScreen("card-choice");
            return;
        }
        if (!consumeStamina()) {
            flash("探索力が足りません。", "error");
            render();
            return;
        }

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

        if (floorCleared) {
            applyFloorClearRewards(run, step.floorNumber);
            finishSortie(run, "victory");
            saveState();
            showScreen("run");
            return;
        }

        if (run.currentHp <= 0 || result.finishReason) {
            finishSortie(run, "defeated");
            saveState();
            showScreen("run");
            return;
        }

        saveState();
        showScreen("run");
    }

    function requestAdvanceRun() {
        if (isAdvancing) return;

        const run = state.activeRun;
        if (!run && state.lastResult?.status === "ended") {
            goToResult();
            return;
        }
        if (!run || run.status !== "active") {
            advanceRun();
            return;
        }
        if (pendingCardChoice()) {
            showScreen("card-choice");
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

    function goToResult() {
        if (pendingCardChoice()) {
            showScreen("card-choice");
            return;
        }
        showScreen("result");
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
        const damage = Math.max(1, Math.round(baseDamage));
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
        run.restFoundCount += 1;
        const scoreDelta = scoreWithRoute(run, Math.round(10 * orbMultiplier(run, "eventScoreRate")));
        run.score += scoreDelta;
        return {
            result: "rested",
            cleared: true,
            scoreDelta,
            logText: `白風が抜ける小さな広場に出た。\n${run.valmonName}は耳を澄ませ、奥へ続く気配を確かめた。`,
            payload: {},
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

    function applyFloorClearRewards(run, floorNumber) {
        const tower = state.tower;
        tower.currentFloorToChallenge = Math.min(config.season.maxFloorTarget, floorNumber + 1);
        tower.highestClearedFloor = Math.max(Number(tower.highestClearedFloor || 0), floorNumber);
        tower.maxHp = run.maxHp;
        tower.currentHp = clamp(Number(run.currentHp || 0), 0, run.maxHp);
        tower.lastHpRecoveredAt = nowIso();

        const firstClear = !tower.clearedRewardFloors.includes(floorNumber);
        run.firstClear = firstClear;
        run.rewardBp = 0;
        if (!firstClear) return;

        tower.clearedRewardFloors.push(floorNumber);
        const bp = floorNumber % 10 === 0
            ? (floorNumber >= 50 ? config.bp.bossFloorClearAfter50 : config.bp.bossFloorClear)
            : config.bp.normalFloorClear;
        tower.bp += bp;
        run.rewardBp = bp;
        // スコアは初回突破分だけシーズン累計に加算する（同じ階層の周回では稼げない）
        tower.score = Number(tower.score || 0) + Number(run.score || 0);

        if (floorNumber <= config.season.floorRewardCardUntilFloor) {
            tower.pendingCardChoice = {
                choiceType: "floor_reward",
                sourceFloor: floorNumber,
                options: cardChoiceOptions(`floor:${floorNumber}`),
                selected: null,
                origin: "result",
            };
        }
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
            ["explore", "evasion"],
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

    function finishSortie(run, reason) {
        run.status = "ended";
        run.sortieResult = reason;
        run.endedAt = nowIso();
        const tower = state.tower;
        if (reason === "victory") {
            addRunLog(run, `地下${run.currentFloor}階を突破した！${run.valmonName}は意気揚々と主人のもとへ帰っていく。`);
        } else {
            tower.defeatCount = Number(tower.defeatCount || 0) + 1;
            addRunLog(run, `${run.valmonName}は地下${run.targetFloor}階で力尽き、主人の待つ入口へ戻った。カードやTPを見直して、同じ階層に再挑戦できます。`);
        }
        tower.maxHp = run.maxHp;
        tower.currentHp = clamp(Number(run.currentHp || 0), 0, run.maxHp);
        tower.lastHpRecoveredAt = nowIso();
        updateRanking(run);
        state.lastResult = { ...run };
        state.activeRun = null;
    }

    function updateRanking(run) {
        const seasonKey = activeSeasonKey();
        const rows = state.rankings[seasonKey] || seedRankingRows();
        const ownName = "試験冒険者";
        const existingIndex = rows.findIndex((row) => row.characterName === ownName);
        // 自分の行はシーズン累計の現在値で常に更新する（本番はサーバー集計を想定）
        const candidate = {
            characterName: ownName,
            valmonName: run.valmonName,
            bestRunId: run.id,
            bestFloor: Number(state.tower.highestClearedFloor || 0),
            bestScore: Number(state.tower.score || 0),
            defeatCount: Number(state.tower.defeatCount || 0),
            attemptCount: state.tower.attemptCount || 1,
            achievedAt: run.endedAt || nowIso(),
        };
        if (existingIndex >= 0) {
            rows[existingIndex] = candidate;
        } else {
            rows.push(candidate);
        }
        state.rankings[seasonKey] = sortRanking(rows).slice(0, 100);
    }

    function sortRanking(rows) {
        return [...rows].sort((a, b) => {
            if (b.bestFloor !== a.bestFloor) return b.bestFloor - a.bestFloor;
            if (b.bestScore !== a.bestScore) return b.bestScore - a.bestScore;
            if ((a.defeatCount || 0) !== (b.defeatCount || 0)) return (a.defeatCount || 0) - (b.defeatCount || 0);
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
                defeatCount: Math.floor(rng() * 12),
                attemptCount: 10 + Math.floor(rng() * 60),
                achievedAt: new Date(Date.now() - (index * 4300000)).toISOString(),
            };
        }));
    }

    function chooseCard(cardCode) {
        const choice = pendingCardChoice();
        if (!choice || !choice.options.includes(cardCode)) return;
        choice.selected = cardCode;
        saveState();
        render();
    }

    function confirmCardChoice() {
        const choice = pendingCardChoice();
        if (!choice) {
            flash("選択中のカードがありません。", "error");
            showScreen("prepare");
            return;
        }
        if (!choice.selected) {
            flash("カードを1枚選んでください。", "error");
            renderCardChoice();
            return;
        }
        applyCardChoice(choice.selected);
        const origin = choice.origin;
        state.tower.pendingCardChoice = null;
        saveState();
        render();
        if (state.activeRun?.status === "active") {
            showScreen(explorationScreenForRun(state.activeRun));
        } else if (origin === "result" && state.lastResult?.status === "ended") {
            showScreen("result");
        } else {
            showScreen("prepare");
        }
    }

    function cardChoiceOrigin() {
        return currentScreen === "result" ? "result" : "prepare";
    }

    function beginCoinCardChoice() {
        if (pendingCardChoice()) {
            flash("先に表示中のカード3択を決めてください。", "error");
            return;
        }
        const coinChoiceNo = Number(state.tower.usedCoinCount || 0) + 1;
        if (!consumeCoin(config.coin.cardChoiceCost || 1)) {
            flash("使用できる金貨がありません。", "error");
            return;
        }
        state.tower.pendingCardChoice = {
            choiceType: "coin",
            sourceFloor: null,
            options: cardChoiceOptions(`coin:${coinChoiceNo}`),
            selected: null,
            origin: cardChoiceOrigin(),
        };
        saveState();
        showScreen("card-choice");
    }

    function beginBpCardChoice() {
        if (pendingCardChoice()) {
            flash("先に表示中のカード3択を決めてください。", "error");
            return;
        }
        const cost = nextBpActionCost();
        if (!consumeBpNormalAction(cost)) {
            flash("TPが足りません。", "error");
            return;
        }
        state.tower.pendingCardChoice = {
            choiceType: "bp",
            sourceFloor: null,
            options: cardChoiceOptions(`bp:${state.tower.spentBp}`),
            selected: null,
            origin: cardChoiceOrigin(),
        };
        saveState();
        showScreen("card-choice");
    }

    function consumeCoin(amount = 1) {
        const cost = Number(amount || 1);
        if (availableCoinCount() < cost) return false;
        state.tower.usedCoinCount += cost;
        return true;
    }

    function nextBpActionCost() {
        return Number(state.tower.bpActionCount || 0) + 1;
    }

    function nextDeckSlotExpandCost() {
        return (Number(state.tower.deckSlotExpandCount || 0) * 2) + 1;
    }

    function deckSlotExpandCostForSlot(slotNumber) {
        const expandNumber = Number(slotNumber || deckSlotDefault) - deckSlotDefault;
        return Math.max(1, (expandNumber * 2) - 1);
    }

    function consumeBpNormalAction(cost = nextBpActionCost()) {
        const amount = Number(cost || 1);
        if (state.tower.bp < amount) return false;
        state.tower.bp -= amount;
        state.tower.spentBp += amount;
        state.tower.bpActionCount += 1;
        return true;
    }

    function bpActionMeta(action) {
        const normalCost = nextBpActionCost();
        const nextSlot = state.tower.deckSlotLimit + 1;
        const slotCost = nextDeckSlotExpandCost();
        const table = {
            card: {
                title: "TPでカードを引きますか？",
                text: `TPを${normalCost}消費して、カード3択を引きます。候補は保存され、引き直しはできません。`,
                cost: normalCost,
                confirmLabel: "カードを引く",
            },
            tuning: {
                title: "TPを増やしますか？",
                text: `TPを${normalCost}消費して、シーズンTPを+1します。消費回数は戻りません。`,
                cost: normalCost,
                confirmLabel: "追加TP+1",
            },
            slot: {
                title: "TPで装備枠を拡張しますか？",
                text: nextSlot <= config.deck.maxSlotLimit
                    ? `TPを${slotCost}消費して、装備枠を${nextSlot}枚にします。装備枠拡張コストは通常TP消費とは別カウントです。`
                    : "装備枠はすでに上限です。",
                cost: nextSlot <= config.deck.maxSlotLimit ? slotCost : null,
                confirmLabel: "装備枠を増やす",
                disabledMessage: "装備枠はすでに上限です。",
            },
        };
        const meta = table[action];
        if (!meta) return null;
        return {
            ...meta,
            disabled: !meta.cost || state.tower.bp < meta.cost,
            disabledMessage: meta.disabledMessage || `TPが足りません。必要TP: ${meta.cost}`,
        };
    }

    function syncActiveRunLoadout() {
        const run = state.activeRun;
        if (!run || run.status !== "active") return;
        const valmon = config.valmons.find((row) => row.id === Number(run.valmonId));
        if (!valmon) return;
        const setting = settingForValmon(valmon);
        run.baseTuning = { ...setting.tuning };
        run.equippedOrbCodes = [...state.tower.deckCardCodes];
        const stats = runStats(run);
        const previousMaxHp = Number(run.maxHp || stats.maxHp);
        run.maxHp = stats.maxHp;
        if (stats.maxHp > previousMaxHp) {
            run.currentHp += stats.maxHp - previousMaxHp;
        }
        run.currentHp = clamp(run.currentHp, 0, run.maxHp);
        state.tower.currentHp = run.currentHp;
        state.tower.maxHp = run.maxHp;
    }

    function buyCoinTuningPoint() {
        if (state.tower.coinTuningPointCount >= (config.coin.tuningPointPurchaseLimit || 10)) {
            flash("金貨で買えるTPは上限です。", "error");
            return;
        }
        if (!consumeCoin(config.coin.tuningPointCost || 1)) {
            flash("使用できる金貨がありません。", "error");
            return;
        }
        state.tower.coinTuningPointCount += 1;
        state.tower.seasonTuningBonusPoints += 1;
        syncActiveRunLoadout();
        saveState();
        flash("シーズンTPが+1されました。");
        render();
    }

    function buyBpTuningPoint() {
        const cost = nextBpActionCost();
        if (!consumeBpNormalAction(cost)) {
            flash("TPが足りません。", "error");
            return;
        }
        state.tower.bpTuningPointCount += 1;
        state.tower.seasonTuningBonusPoints += 1;
        syncActiveRunLoadout();
        saveState();
        flash(`シーズンTPが+1されました。TP ${cost}消費。`);
        render();
    }

    function spendBp(action) {
        const tower = state.tower;
        if (action === "slot") {
            const nextSlot = tower.deckSlotLimit + 1;
            const cost = nextDeckSlotExpandCost();
            if (!cost || tower.bp < cost) return;
            tower.bp -= cost;
            tower.spentBp += cost;
            tower.deckSlotExpandCount += 1;
            tower.deckSlotLimit = nextSlot;
            flash(`装備枠が${nextSlot}枚になりました。TP ${cost}消費。`);
        }
        syncActiveRunLoadout();
        saveState();
        render();
    }

    function applyCardChoice(cardCode) {
        const tower = state.tower;
        const card = cardByCode(cardCode);
        if (!card || tower.ownedCardCodes.includes(cardCode)) return;
        tower.ownedCardCodes.push(cardCode);
        if (tower.deckCardCodes.length < tower.deckSlotLimit) {
            tower.deckCardCodes.push(cardCode);
        }
        flash(`「${card.name}」を手に入れた！`);
    }

    function render() {
        ensureSeason();
        const recovered = applyHpRecovery();
        if (recovered) saveState();
        renderStamina();
        renderTowerSummary();
        renderPrepare();
        renderDeparture();
        renderRun();
        renderResult();
        renderCardChoice();
        renderRanking();
        renderStatus();
        renderHpRecoveryStatus();
        updateActionStates();
    }

    function renderStamina() {
        state.stamina = state.stamina || { current: staminaMax, max: staminaMax };
        state.stamina.max = staminaMax;
        state.stamina.current = clamp(Number(state.stamina.current || 0), 0, staminaMax);
        $("staminaCurrentText").textContent = fmt(state.stamina.current);
        $("staminaMaxText").textContent = `/${fmt(state.stamina.max)}`;
    }

    function syncTowerHpMax(maxHp) {
        const tower = state.tower;
        const safeMax = Math.max(1, Math.round(Number(maxHp || config.baseStats.hp)));
        const previousMax = Math.max(1, Number(tower.maxHp || safeMax));
        const previousHp = Number(tower.currentHp ?? previousMax);
        tower.maxHp = safeMax;
        tower.currentHp = clamp(previousHp, 0, safeMax);
        if (!tower.lastHpRecoveredAt) tower.lastHpRecoveredAt = nowIso();
    }

    function applyHpRecovery() {
        const tower = state.tower;
        const maxHp = Math.max(1, Number(tower.maxHp || config.baseStats.hp));
        tower.currentHp = clamp(Number(tower.currentHp ?? maxHp), 0, maxHp);
        if (!tower.lastHpRecoveredAt) tower.lastHpRecoveredAt = nowIso();
        if (tower.currentHp >= maxHp) {
            tower.lastHpRecoveredAt = nowIso();
            return false;
        }
        const lastAt = new Date(tower.lastHpRecoveredAt).getTime();
        if (!Number.isFinite(lastAt)) {
            tower.lastHpRecoveredAt = nowIso();
            return false;
        }
        const ticks = Math.floor(Math.max(0, Date.now() - lastAt) / hpRecoveryIntervalMs);
        if (ticks <= 0) return false;
        const recoveryBonus = towerDeckBonus("milestoneHealBonus");
        const healPerTick = Math.max(1, Math.ceil(maxHp * (hpRecoveryRate + recoveryBonus)));
        const before = tower.currentHp;
        tower.currentHp = clamp(tower.currentHp + (ticks * healPerTick), 0, maxHp);
        tower.lastHpRecoveredAt = new Date(lastAt + (ticks * hpRecoveryIntervalMs)).toISOString();
        if (tower.currentHp >= maxHp) tower.lastHpRecoveredAt = nowIso();
        return tower.currentHp !== before;
    }

    function nextHpRecoveryRemainingMs() {
        const tower = state.tower;
        const maxHp = Math.max(1, Number(tower.maxHp || config.baseStats.hp));
        if (Number(tower.currentHp || 0) >= maxHp) return 0;
        const lastAt = new Date(tower.lastHpRecoveredAt || nowIso()).getTime();
        const elapsed = Math.max(0, Date.now() - lastAt);
        return Math.max(0, hpRecoveryIntervalMs - (elapsed % hpRecoveryIntervalMs));
    }

    function naturalHpRecoveryAmount() {
        const maxHp = Math.max(1, Number(state.tower.maxHp || config.baseStats.hp));
        const recoveryBonus = towerDeckBonus("milestoneHealBonus");
        return Math.max(1, Math.ceil(maxHp * (hpRecoveryRate + recoveryBonus)));
    }

    function goldRestCost() {
        const floor = clamp(Number(state.tower.currentFloorToChallenge || 1), 1, config.season.maxFloorTarget);
        return floor * goldRestCostPerFloor;
    }

    function goldRestHealAmount() {
        const bonus = towerDeckBonus("restHealBonus") + towerDeckBonus("milestoneHealBonus");
        return Math.max(1, Math.ceil(Number(state.tower.maxHp || config.baseStats.hp) * (goldRestHealRate + bonus)));
    }

    function renderHpRecoveryStatus() {
        const tower = state.tower;
        const hp = clamp(Number(tower.currentHp || 0), 0, Number(tower.maxHp || config.baseStats.hp));
        const maxHp = Math.max(1, Number(tower.maxHp || config.baseStats.hp));
        if ($("topHpText")) $("topHpText").textContent = `${fmt(hp)}/${fmt(maxHp)}`;
        if ($("topGoldText")) $("topGoldText").textContent = fmt(tower.gold || 0);
        if (!$("hpRecoveryText")) return;
        $("hpRecoveryText").textContent = `HP ${fmt(hp)} / ${fmt(maxHp)}`;
        const restCost = goldRestCost();
        const healAmount = goldRestHealAmount();
        if (hp >= maxHp) {
            $("hpRecoveryTimerText").textContent = "HPは満タンです";
        } else {
            $("hpRecoveryTimerText").textContent = `自然回復 +${fmt(naturalHpRecoveryAmount())} / あと ${formatDuration(nextHpRecoveryRemainingMs())} / 休息 +${fmt(healAmount)}`;
        }
        const button = $("goldRestButton");
        if (button) {
            button.textContent = `Gold休息 ${fmt(restCost)}G`;
            button.disabled = hp >= maxHp || Number(tower.gold || 0) < restCost;
        }
        if ($("resultHpRecoveryPanel") && !$("resultHpRecoveryPanel").hidden) {
            renderResultHpRecovery();
        }
    }

    function naturalUnlockedCoinCount() {
        const elapsed = Math.max(0, Date.now() - new Date(state.season.startsAt).getTime());
        const intervals = Math.floor(elapsed / (config.season.naturalCoinIntervalHours * 60 * 60 * 1000));
        const unlockedBatches = intervals + 1;
        return clamp(unlockedBatches * config.season.naturalCoinAmountPerInterval, 0, config.season.naturalCoinMax);
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
        const nextBatchIndex = Math.floor(claimed / amount);
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
        if ($("topCoinText")) {
            $("topCoinText").textContent = fmt(availableCoinCount());
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
        $("startCurrentFloorText").textContent = `現在地下${fmt(tower.currentFloorToChallenge)}階`;
        $("towerBestFloorText").textContent = `${fmt(tower.highestClearedFloor)}階`;
        $("topCoinText").textContent = fmt(availableCoinCount());
        $("topBpText").textContent = fmt(tower.bp);
        renderHpRecoveryStatus();
        renderCoinClaimStatus();
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
        const limit = tuningPointLimit(valmon.level, state.tower?.seasonTuningBonusPoints || 0);
        const total = statKeys.reduce((sum, key) => sum + Number(setting.tuning[key] || 0), 0);
        const stats = calculateStats(valmon, setting.tuning, emptyTuning(), state.tower.deckCardCodes || [], null);
        syncTowerHpMax(stats.maxHp);
        const hp = clamp(Number(state.tower.currentHp || 0), 0, stats.maxHp);
        $("selectedValmonImage").src = `../../../public/images/valmon/${valmon.image}`;
        $("selectedValmonName").textContent = valmon.name;
        $("selectedValmonMeta").textContent = `Lv${valmon.level} / 基礎能力値 ${limit}`;
        $("selectedValmonHpText").textContent = `${fmt(hp)} / ${fmt(stats.maxHp)}`;
        setHpBar("selectedValmonHpBar", hp, stats.maxHp);
        $("tuningLimitLabel").textContent = `合計 ${limit} / 追加 ${state.tower.seasonTuningBonusPoints}`;
        renderTuningList();
        renderOrbList();
        const validation = validateSetting(valmon);
        $("tuningError").textContent = !validation.ok && validation.target === "tuning" ? validation.message : "";
        $("orbError").textContent = !validation.ok && validation.target === "orb" ? validation.message : "";
        renderCostActionStates();
    }

    function renderTuningList() {
        const setting = draftSetting();
        const limit = tuningPointLimit(draftValmon().level, state.tower?.seasonTuningBonusPoints || 0);
        const total = statKeys.reduce((sum, key) => sum + Number(setting.tuning[key] || 0), 0);
        const hasRemaining = total < limit;
        $("tuningList").innerHTML = statKeys.map((key) => `
            <div class="tuning-row">
                <label>${statIcon(key)}<span>${config.tuning.labels[key]}</span></label>
                <div class="tuning-value">${setting.tuning[key] || 0}</div>
                <button type="button" class="step-button" data-tuning="${key}" data-delta="1" title="${hasRemaining ? "残りTPを振り分ける（振り直し不可）" : "追加TPを取得して上げる"}">+</button>
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
        const deckCodes = tower.deckCardCodes || [];
        let ownedCards = tower.ownedCardCodes.map(cardByCode).filter(Boolean);
        const shouldShowDeckSlots = deckFilter === "all" || deckFilter === "equipped";
        ownedCards = ownedCards.filter((orb) => {
            if (deckFilter === "equipped") return deckCodes.includes(orb.code);
            if (deckFilter === "unequipped") return !deckCodes.includes(orb.code);
            if (deckFilter === "all") return true;
            return orb.category === deckFilter;
        });
        ownedCards.sort((a, b) => compareDeckCards(a, b, deckCodes));
        const cardHtml = ownedCards.map((orb) => {
            const active = deckCodes.includes(orb.code) ? " active" : "";
            return `<button type="button" class="orb-card${active}" data-orb-code="${orb.code}">
                ${cardArt(orb)}
                <span class="orb-card-body">
                    <strong>${orb.name}</strong>
                    <span>${categoryLabel(orb.category)} / ${orb.rarity || "N"}</span>
                    <p class="muted">${orb.description}</p>
                </span>
            </button>`;
        }).join("");
        const deckSlotHtml = shouldShowDeckSlots ? renderDeckSlotCards(deckCodes) : "";
        const extraCardHtml = shouldShowDeckSlots && deckFilter === "all"
            ? ownedCards.filter((orb) => !deckCodes.includes(orb.code)).map((orb) => `
                <button type="button" class="orb-card deck-extra-card" data-orb-code="${orb.code}">
                    ${cardArt(orb)}
                    <span class="orb-card-body">
                        <strong>${orb.name}</strong>
                        <span>${categoryLabel(orb.category)} / ${orb.rarity || "N"}</span>
                        <p class="muted">${orb.description}</p>
                    </span>
                </button>
            `).join("")
            : "";
        $("orbList").innerHTML = shouldShowDeckSlots
            ? `${deckSlotHtml}${extraCardHtml}`
            : cardHtml || `<p class="empty-note">まだカードを所持していません。金貨か1〜10階の初回突破報酬でカードを得ます。</p>`;
        renderBpActions();
    }

    function renderDeckSlotCards(deckCodes) {
        const slotLimit = state.tower.deckSlotLimit || deckSlotDefault;
        const maxSlotLimit = config.deck.maxSlotLimit || slotLimit;
        const unlockedSlots = Array.from({ length: slotLimit }, (_, index) => {
            const card = cardByCode(deckCodes[index]);
            if (!card) {
                return `<div class="orb-card deck-slot-empty" aria-label="空きスロット ${index + 1}">
                    ${cardBackArt("card-art card-back-art deck-slot-back")}
                    <span class="orb-card-body">
                        <strong>空きスロット</strong>
                        <span>${index + 1} / ${slotLimit}</span>
                        <p class="muted">カードを装備できます</p>
                    </span>
                </div>`;
            }
            return `<button type="button" class="orb-card active deck-slot-card" data-orb-code="${card.code}">
                ${cardArt(card)}
                <span class="orb-card-body">
                    <strong>${card.name}</strong>
                    <span>${categoryLabel(card.category)} / ${card.rarity || "N"}</span>
                <p class="muted">${card.description}</p>
                </span>
            </button>`;
        });
        const lockedSlots = Array.from({ length: Math.max(0, maxSlotLimit - slotLimit) }, (_, index) => {
            const slotNumber = slotLimit + index + 1;
            const cost = deckSlotExpandCostForSlot(slotNumber);
            const isNextSlot = index === 0;
            const tagName = isNextSlot ? "button" : "div";
            const actionAttr = isNextSlot ? ' type="button" data-bp-action="slot"' : "";
            const ariaLabel = isNextSlot ? `装備枠 ${slotNumber} をTP ${cost}で解放` : `未解放スロット ${slotNumber}`;
            return `<${tagName}${actionAttr} class="orb-card deck-slot-locked${isNextSlot ? " is-next" : ""}" aria-label="${ariaLabel}">
                ${cardBackArt("card-art card-back-art deck-slot-back")}
                <span class="deck-slot-unlock-badge">TP ${fmt(cost)}で解放</span>
                <span class="orb-card-body">
                    <strong>未解放スロット</strong>
                    <span>${slotNumber} / ${maxSlotLimit}</span>
                </span>
            </${tagName}>`;
        });
        return [...unlockedSlots, ...lockedSlots].join("");
    }

    function compareDeckCards(a, b, deckCodes) {
        const equippedDiff = Number(deckCodes.includes(b.code)) - Number(deckCodes.includes(a.code));
        if (deckSort === "equipped" && equippedDiff !== 0) return equippedDiff;
        if (deckSort === "category") {
            const categoryDiff = categorySortRank(a.category) - categorySortRank(b.category);
            if (categoryDiff !== 0) return categoryDiff;
        }
        if (deckSort === "rarity") {
            const rarityDiff = raritySortRank(b.rarity) - raritySortRank(a.rarity);
            if (rarityDiff !== 0) return rarityDiff;
        }
        return String(a.name).localeCompare(String(b.name), "ja");
    }

    function categorySortRank(category) {
        const index = ["attack", "defense", "heal", "explore", "evasion"].indexOf(category);
        return index >= 0 ? index : 99;
    }

    function raritySortRank(rarity) {
        return { N: 1, R: 2, SR: 3, SSR: 4 }[rarity] || 0;
    }

    function renderBpActions() {
        const tower = state.tower;
        $("bpUpgradeLabel").textContent = `未使用TP ${fmt(tower.bp)} / 次 ${fmt(nextBpActionCost())}TP`;
        const nextSlot = tower.deckSlotLimit + 1;
        const slotCost = nextSlot <= config.deck.maxSlotLimit ? nextDeckSlotExpandCost() : null;
        const actions = [
            { key: "slot", label: nextSlot <= config.deck.maxSlotLimit ? `装備枠 +1` : "装備枠 最大", cost: slotCost, kind: "拡張TP" },
        ];
        $("bpActionGrid").innerHTML = actions.map((action) => {
            const disabled = !action.cost || tower.bp < action.cost;
            return `<button type="button" class="bp-action" data-bp-action="${action.key}" ${disabled ? "disabled" : ""}>
                <strong>${action.label}</strong>
                <span>${action.cost ? `${action.kind} ${action.cost}TP` : "上限"}</span>
            </button>`;
        }).join("");
    }

    function renderCostActionStates() {
        const pendingChoice = Boolean(pendingCardChoice());
        $("openCardDrawButton").disabled = false;
        $("openCardDrawButton").textContent = pendingChoice ? "カード報酬を選ぶ" : "カードを取得する";
        if ($("resultCardButton")) {
            $("resultCardButton").disabled = false;
            $("resultCardButton").textContent = pendingChoice ? "カード報酬を選ぶ" : "カードを取得する";
        }
        if (!$("tuningUpgradeModal").hidden) renderTuningUpgradeModal();
        if (!$("cardDrawModal").hidden) renderCardDrawModal();
    }

    function orbIcon(orb) {
        if (!orb?.icon) return "";
        return `<img class="orb-icon" src="../../../public/images/icon/${orb.icon}" alt="" aria-hidden="true">`;
    }

    function cardArt(orb, className = "card-art") {
        const file = orb?.cardImage || cardArtFileFor(orb);
        if (!file) return orbIcon(orb);
        return `<img class="${className}" src="../../../public/images/valmon/card/${file}" alt="" aria-hidden="true">`;
    }

    function cardArtFileFor(orb) {
        const baseNumber = cardArtNumberByCategory[orb?.category];
        if (!baseNumber) return null;
        const offset = cardArtRarityOffset[orb?.rarity || "N"] ?? 0;
        return `val_card${String(baseNumber + offset).padStart(2, "0")}.webp`;
    }

    function cardBackArt(className = "card-art card-back-art") {
        return `<img class="${className}" src="../../../public/images/valmon/card/val_card00.webp" alt="" aria-hidden="true">`;
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
        const eventVisualHeader = pageEvent && !isCombatEvent(pageEvent) ? renderEventVisualHeader(pageEvent) : "";
        $("runLog").innerHTML = pageEvent
            ? eventVisualHeader
                + renderLeadingChoiceLogEvents(run, pageEvent)
                + renderBattleTimeline(run, [pageEvent])
                + renderTrailingLogEvents(run, pageEvent)
            : `<p class="empty-note">${escapeHtml(run.valmonName)}が地下入口で待機しています。</p>`;
        const last = pageEvent;
        $("lastEvent").innerHTML = last ? escapeHtml(last.logText).replace(/\n/g, "<br>") : "まだ進行していません。";
        renderEventStage(run);
    }

    function floorHint(floorNumber) {
        if (floorNumber % 10 === 0) {
            return "階層主の気配がする。長期戦になりそうだ。";
        }
        const rng = seededRandom(`${state.season.seed}:hint:${floorNumber}`);
        const hints = [
            "硬い殻を持つ敵の気配。攻めの厚みがほしい。",
            "黒い風がざわついている。罠と回避に備えたい。",
            "小さな宝の匂いがする。探知が役立ちそうだ。",
            "足音の多い階層のようだ。連戦に備えたい。",
            "静かな階層のようだ。落ち着いて進めそうだ。",
        ];
        return hints[Math.floor(rng() * hints.length)];
    }

    function renderResult() {
        const result = state.lastResult;
        const tower = state.tower;
        const nextFloor = clamp(Number(tower.currentFloorToChallenge || 1), 1, config.season.maxFloorTarget);
        const hasStamina = Number(state.stamina?.current || 0) > 0;
        const hasHp = Number(tower.currentHp || 0) > 0;
        const panel = $("resultPanel");
        if (!result || result.status !== "ended") {
            panel.classList.remove("victory", "defeat");
            $("resultEyebrow").textContent = "帰還";
            $("resultTitle").textContent = "まだ出撃していません。";
            $("resultRewardText").textContent = "";
            $("resultInfoText").textContent = `現在階層：地下${fmt(nextFloor)}階`;
            $("resultHpRecoveryPanel").hidden = true;
            $("resultFloorHint").textContent = "";
            $("resultSortieButton").disabled = true;
            $("resultSortieButton").textContent = "出撃する";
            return;
        }

        const victory = result.sortieResult === "victory";
        panel.classList.toggle("victory", victory);
        panel.classList.toggle("defeat", !victory);
        $("resultValmonImage").src = `../../../public/images/valmon/${(config.valmons.find((row) => row.id === Number(result.valmonId)) || selectedValmon()).image}`;
        $("resultEyebrow").textContent = victory ? "突破" : "帰還";
        $("resultTitle").textContent = victory
            ? `地下${fmt(result.currentFloor)}階を突破！`
            : `地下${fmt(result.targetFloor)}階で敗北……`;
        $("resultRewardText").textContent = victory
            ? (result.rewardBp > 0
                ? `獲得：TP +${fmt(result.rewardBp)} / スコア +${fmt(result.score)}`
                : "この階層の報酬は獲得済みです")
            : "カードやTPを見直して再挑戦できます";
        $("resultInfoText").textContent = `次の挑戦：地下${fmt(nextFloor)}階 / HP ${fmt(tower.currentHp)} / ${fmt(tower.maxHp)}`;
        renderResultHpRecovery();
        $("resultFloorHint").textContent = `地下${fmt(nextFloor)}階の気配：${floorHint(nextFloor)}`;
        $("resultSortieButton").disabled = !hasStamina || !hasHp || Boolean(pendingCardChoice());
        $("resultSortieButton").textContent = !hasStamina
            ? "探索力不足"
            : !hasHp
                ? "HP回復待ち"
            : victory
                ? `この編成のまま地下${fmt(nextFloor)}階へ`
                : "この編成でもう一度挑戦";
    }

    function renderResultHpRecovery() {
        const tower = state.tower;
        const hp = clamp(Number(tower.currentHp || 0), 0, Number(tower.maxHp || config.baseStats.hp));
        const maxHp = Math.max(1, Number(tower.maxHp || config.baseStats.hp));
        $("resultHpRecoveryPanel").hidden = false;
        $("resultHpText").textContent = `${fmt(hp)} / ${fmt(maxHp)}`;
        setHpBar("resultHpBar", hp, maxHp);
        if (hp >= maxHp) {
            $("resultNextRecoveryText").textContent = "HPは満タンです";
            $("resultRecoveryTimerText").textContent = "";
        } else {
            $("resultNextRecoveryText").textContent = `次回自然回復 +${fmt(naturalHpRecoveryAmount())}`;
            $("resultRecoveryTimerText").textContent = `あと ${formatDuration(nextHpRecoveryRemainingMs())}`;
        }
        $("resultGoldRestText").textContent = `Gold休息 +${fmt(goldRestHealAmount())} / ${fmt(goldRestCost())}G`;
    }

    function renderCardChoice() {
        const choice = pendingCardChoice();
        if (!choice) {
            $("cardChoiceTitle").textContent = "カードを選ぶ";
            $("cardChoiceSource").textContent = "待機中";
            $("cardChoiceLead").textContent = "選択中のカード候補はありません。";
            $("cardChoiceOptions").innerHTML = "";
            $("cardChoiceDetail").innerHTML = `<p class="empty-note">金貨、TP、または1〜10階の初回突破報酬でカード3択が発生します。</p>`;
            $("cardChoiceConfirmButton").disabled = true;
            return;
        }

        if (choice.selected) {
            $("cardChoiceOptions").classList.remove("dealing");
        }
        const sourceLabel = {
            coin: "金貨",
            bp: "TP",
            floor_reward: `${choice.sourceFloor || ""}階報酬`,
        }[choice.choiceType] || "カード報酬";
        $("cardChoiceTitle").textContent = `${selectedValmon().name}がカードの気配を見つけた。`;
        $("cardChoiceSource").textContent = sourceLabel;
        $("cardChoiceLead").textContent = "3枚のうち1枚を選んでください。候補は保存済みで、引き直しはできません。";
        $("cardChoiceOptions").innerHTML = choice.options.map((code) => {
            const card = cardByCode(code);
            const isSelected = choice.selected === code;
            return `<button type="button" class="draw-card${isSelected ? " selected" : ""}" data-choice-card="${code}" aria-pressed="${isSelected ? "true" : "false"}">
                ${cardArt(card, "card-art draw-card-art")}
                <strong>${escapeHtml(card.name)}</strong>
                <span>${categoryLabel(card.category)} / ${card.rarity || "N"}</span>
            </button>`;
        }).join("");

        const selected = cardByCode(choice.selected);
        $("cardChoiceDetail").innerHTML = selected
            ? `<div class="card-detail-head">
                    ${cardArt(selected, "card-art card-detail-art")}
                    <div>
                        <strong>${escapeHtml(selected.name)}</strong>
                        <span>${categoryLabel(selected.category)} / ${selected.rarity || "N"}</span>
                    </div>
                </div>
                <p>${escapeHtml(selected.description)}</p>`
            : `<p class="empty-note">カードを選ぶと、ここに効果の説明が表示されます。</p>`;
        $("cardChoiceConfirmButton").disabled = !choice.selected;
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
            rest: "白風の広場に出た。",
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
            rest: ["白風の広場", "小休止"],
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
            .filter((event) => event.eventType === "log")
            .map((event) => renderRunLogNote(event))
            .join("");
    }

    function isHighlightLog(event) {
        return String(event.logText || "").includes("突破した！");
    }

    function renderRunLogNote(event) {
        return `<p class="is-note${isHighlightLog(event) ? " is-stairs" : ""}">${formatBattleLogText(event)}</p>`;
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
            rest: { title: "白風の広場", label: "小休止", type: "rest" },
            walk: { title: "細い地下道", label: "移動探索", type: "walk" },
            omen: { title: "黒い風の気配", label: "気配", type: "omen" },
        }[event.eventType] || { title: "地下の気配", label: "出来事", type: "idle" };
    }

    function renderEventVisualHeader(event) {
        const meta = eventVisualMeta(event);
        return `
            <div class="event-visual event-visual-${meta.type} event-visual-standalone">
                <span>${escapeHtml(meta.label)}</span>
                <strong>${escapeHtml(meta.title)}</strong>
            </div>
        `;
    }

    function renderEventFrame(run, valmon, event) {
        const meta = eventVisualMeta(event);
        return `
            <article class="event-turn event-${meta.type}">
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
            { label: "敗北", value: `${fmt(state.tower.defeatCount || 0)}回` },
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
        const statusHp = run ? run.currentHp : clamp(Number(state.tower.currentHp || 0), 0, stats.maxHp);
        $("statusHpText").textContent = `${fmt(statusHp)} / ${fmt(run ? run.maxHp : stats.maxHp)}`;
        setHpBar("statusHpBar", statusHp, run ? run.maxHp : stats.maxHp);
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
        const hasHp = Number(state.tower.currentHp || 0) > 0;
        const run = state.activeRun;
        const hasSortieResult = !run && state.lastResult?.status === "ended";
        const needsCardChoice = startCheck.target === "card-choice";
        $("startRunButton").disabled = !startCheck.ok && !needsCardChoice;
        $("startRunButton").textContent = needsCardChoice
            ? "カード報酬を選ぶ"
            : startCheck.action === "resume" ? "出撃へ戻る" : hasHp ? "出撃する" : "HP回復待ち";
        $("departureStartButton").disabled = isAdvancing || !run || !hasStamina;
        $("departureStartButton").classList.toggle("loading", isAdvancing);
        $("departureStartButton").textContent = isAdvancing ? "探索中..." : hasStamina ? "探索へ" : "探索力不足";
        $("advanceButton").disabled = isAdvancing || (!run && !hasSortieResult) || (run && !hasStamina);
        $("advanceButton").classList.toggle("loading", isAdvancing);
        $("advanceButton").classList.toggle("descend", hasSortieResult && !isAdvancing);
        $("advanceButton").textContent = isAdvancing
            ? "探索中..."
            : hasSortieResult
            ? "帰還する"
            : !run
            ? "探索終了"
            : !hasStamina
                ? "探索力不足"
                : "さらに奥に進む";
        renderCostActionStates();
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
        if (target.dataset.screen) showScreen(target.dataset.screen);
        if (target.dataset.modalClose !== undefined) closeRulesModal();
        if (target.dataset.coinClose !== undefined) closeCoinModal();
        if (target.dataset.goldRestCancel !== undefined) closeGoldRestModal();
        if (target.dataset.bpCancel !== undefined) closeBpConfirmModal();
        if (target.dataset.valmonSelectCancel !== undefined) closeValmonSelectModal();
        if (target.dataset.valmonCycle) cycleDraftValmon(target.dataset.valmonCycle);
        if (target.dataset.valmonPick) pickDraftValmon(target.dataset.valmonPick);
        if (target.dataset.orbCode) toggleOrb(target.dataset.orbCode);
        if (target.dataset.tuning) {
            const setting = draftSetting();
            const key = target.dataset.tuning;
            const delta = Number(target.dataset.delta);
            const current = Number(setting.tuning[key] || 0);
            if (delta > 0) {
                const limit = tuningPointLimit(draftValmon().level, state.tower?.seasonTuningBonusPoints || 0);
                const total = statKeys.reduce((sum, statKey) => sum + Number(setting.tuning[statKey] || 0), 0);
                if (current >= config.tuning.statCap) {
                    flash(`${config.tuning.labels[key]}は項目上限です。`, "error");
                    return;
                }
                if (total >= limit) {
                    openTuningUpgradeModal(key);
                    return;
                }
            }
            setting.tuning[key] = clamp(current + delta, 0, config.tuning.statCap);
            syncActiveRunLoadout();
            saveState();
            render();
        }
        if (target.dataset.choiceCard) chooseCard(target.dataset.choiceCard);
        if (target.dataset.bpAction) openBpConfirmModal(target.dataset.bpAction);
    });

    $("rulesButton").addEventListener("click", openRulesModal);
    $("rulesModal").addEventListener("click", (event) => {
        if (event.target.id === "rulesModal") closeRulesModal();
    });
    $("coinModal").addEventListener("click", (event) => {
        if (event.target.id === "coinModal") closeCoinModal();
    });
    $("goldRestModal").addEventListener("click", (event) => {
        if (event.target.id === "goldRestModal" || event.target.dataset.goldRestCancel !== undefined) {
            closeGoldRestModal();
        }
    });
    $("bpConfirmModal").addEventListener("click", (event) => {
        if (event.target.id === "bpConfirmModal") closeBpConfirmModal();
    });
    $("tuningUpgradeModal").addEventListener("click", (event) => {
        if (event.target.id === "tuningUpgradeModal" || event.target.dataset.tuningUpgradeCancel !== undefined) {
            closeTuningUpgradeModal();
        }
    });
    $("valmonSelectModal").addEventListener("click", (event) => {
        if (event.target.id === "valmonSelectModal" || event.target.dataset.valmonSelectCancel !== undefined) {
            closeValmonSelectModal();
        }
    });
    $("cardDrawModal").addEventListener("click", (event) => {
        if (event.target.id === "cardDrawModal" || event.target.dataset.cardDrawCancel !== undefined) {
            closeCardDrawModal();
        }
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && !$("rulesModal").hidden) closeRulesModal();
        if (event.key === "Escape" && !$("coinModal").hidden) closeCoinModal();
        if (event.key === "Escape" && !$("goldRestModal").hidden) closeGoldRestModal();
        if (event.key === "Escape" && !$("bpConfirmModal").hidden) closeBpConfirmModal();
        if (event.key === "Escape" && !$("tuningUpgradeModal").hidden) closeTuningUpgradeModal();
        if (event.key === "Escape" && !$("valmonSelectModal").hidden) closeValmonSelectModal();
        if (event.key === "Escape" && !$("cardDrawModal").hidden) closeCardDrawModal();
    });
    $("startRunButton").addEventListener("click", () => startRun(false));
    $("resultSortieButton").addEventListener("click", () => startRun(true));
    $("resultPrepareButton").addEventListener("click", () => showScreen("prepare"));
    $("resultCardButton").addEventListener("click", openCardDrawModal);
    $("openCardDrawButton").addEventListener("click", openCardDrawModal);
    $("coinClaimButton").addEventListener("click", claimCoin);
    $("goldRestButton").addEventListener("click", openGoldRestModal);
    $("goldRestConfirmButton").addEventListener("click", confirmGoldRest);
    $("bpConfirmButton").addEventListener("click", confirmBpAction);
    $("cardDrawCoinButton").addEventListener("click", () => beginCardChoiceFromModal("coin"));
    $("cardDrawTpButton").addEventListener("click", () => beginCardChoiceFromModal("tp"));
    $("openValmonSelectButton").addEventListener("click", openValmonSelectModal);
    $("tuningUpgradeCoinButton").addEventListener("click", () => applyPurchasedTuningPoint("coin"));
    $("tuningUpgradeTpButton").addEventListener("click", () => applyPurchasedTuningPoint("tp"));
    $("departureStartButton").addEventListener("click", requestAdvanceRun);
    $("advanceButton").addEventListener("click", requestAdvanceRun);
    $("cardChoiceConfirmButton").addEventListener("click", confirmCardChoice);
    $("deckFilterSelect").addEventListener("change", (event) => {
        deckFilter = event.target.value;
        renderOrbList();
    });
    $("deckSortSelect").addEventListener("change", (event) => {
        deckSort = event.target.value;
        renderOrbList();
    });
    $("detailsRankingButton").addEventListener("click", () => showScreen("ranking"));
    $("rankingBackButton").addEventListener("click", () => showScreen(state.activeRun ? explorationScreenForRun(state.activeRun) : "prepare"));
    render();
    window.setInterval(renderCoinClaimStatus, 1000);
    window.setInterval(() => {
        if (applyHpRecovery()) {
            saveState();
            render();
        } else {
            renderHpRecoveryStatus();
            updateActionStates();
        }
    }, 1000);
})();

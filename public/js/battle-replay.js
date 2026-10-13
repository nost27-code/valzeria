(() => {
    'use strict';
    if (window.valzeriaBattleReplayBoot) { window.valzeriaBattleReplayBoot(); return; }
    const instances = new Set();
    const initializedRoots = new WeakMap();
    const playbackSpeeds = [1, 1.5, 2];
    const speedStorageKey = 'valzeria:battle-replay-speed';
    function savedSpeed() {
        try {
            const value = Number(localStorage.getItem(speedStorageKey));
            return playbackSpeeds.includes(value) ? value : 1;
        } catch { return 1; }
    }
    const lineages = ['剣勢', '竜気', '狩猟印', '照準', '聖護', '触媒', '崩し', '指揮点', '星印', '冥蝕'];
    const textures = ['sword', 'dragon', 'hunt', 'aim', 'holy', 'catalyst', 'break', 'command', 'star', 'eclipse', 'fire', 'impact'];
    const number = value => Number(value).toLocaleString('ja-JP');
    // Preserve the existing log's text/emphasis; never accept event handlers, URLs or active HTML.
    function logFragment(lines) {
        const source = new DOMParser().parseFromString(lines.join('<br>'), 'text/html');
        const fragment = document.createDocumentFragment();
        function copy(node, parent) {
            if (node.nodeType === Node.TEXT_NODE) { parent.append(document.createTextNode(node.textContent)); return; }
            if (node.nodeType !== Node.ELEMENT_NODE || ['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT'].includes(node.tagName)) return;
            if (node.classList.contains('battle-log-job-art-tooltip-panel')) return;
            const tag = {SPAN: 'span', BR: 'br', STRONG: 'strong', B: 'b', BUTTON: 'span'}[node.tagName];
            const target = tag ? document.createElement(tag) : parent;
            if (tag) { target.className = node.className; parent.append(target); }
            node.childNodes.forEach(child => copy(child, target));
        }
        source.body.childNodes.forEach(node => copy(node, fragment));
        return fragment;
    }
    // Summarize recorded logs and snapshots only; keep the full log below unchanged.
    function summaryFragment(frame, previous) {
        const fragment = document.createDocumentFragment();
        const action = frame.action || {};
        const title = document.createElement('div'); title.className = 'br-summary-title';
        title.textContent = action.side
            ? `${frame.actors[action.side].name} の ${action.skill ? `《${action.name}》` : action.name}`
            : '';
        const lines = [], skillTitles = [];
        for (const log of frame.logs) {
            const holder = document.createElement('div'); holder.append(logFragment([log]));
            holder.querySelectorAll('.battle-log-job-art-title').forEach(el => {
                const name = el.textContent.match(/《(.+?)》/);
                if (name) skillTitles.push(`${action.side ? `${frame.actors[action.side].name} の ` : ''}《${name[1]}》`);
            });
            const legacy = holder.textContent.match(/【必殺技】(.+?) の必殺技、(.+?) が発動！/);
            if (legacy) skillTitles.push(`${legacy[1]} の《${legacy[2]}》`);
            holder.querySelectorAll('.battle-log-enemy-action').forEach(el => {
                const name = el.textContent.match(/【敵技】(.+?) の (.+?)！/);
                if (name) skillTitles.push(`${name[1]} の《${name[2]}》`);
            });
            holder.querySelectorAll('.battle-log-job-art-title,.battle-log-special-phrase,.battle-log-special-description').forEach(el => el.remove());
            holder.querySelectorAll('br').forEach(el => el.replaceWith(document.createTextNode('\n')));
            lines.push(...holder.textContent.split('\n').map(text => text.trim()).filter(Boolean));
        }
        if (skillTitles.length) title.textContent = [...new Set(skillTitles)].join(' ／ ');
        if (title.textContent) fragment.append(title);
        for (let text of lines) {
            if (!/(?:[\d,]+\s*(?:の\s*)?ダメージ|回復した|かわした|回避された|外れた|耐えた|倒した|倒れて|遭遇した|時間切れ|戦闘は終了|訓練.*(?:完了|終了|終えた))/.test(text)) continue;
            text = text.replace(/^.*? の(?:攻撃|追撃|奥義)！\s*(?:……しかし、\s*)?/, '')
                .replace(/\s+/g, ' ').replace(/(\d[\d,]*)/g, value => number(Number(value.replaceAll(',', ''))));
            const row = document.createElement('div'); row.className = 'br-summary-result';
            if (/ダメージ/.test(text)) {
                const parts = text.split(/(\d[\d,]*)/);
                parts.forEach((part, index) => {
                    const node = document.createElement(index % 2 ? 'strong' : 'span'); node.textContent = part; row.append(node);
                });
            } else row.textContent = text;
            fragment.append(row);
        }
        const changes = [];
        if ((action.hits || []).some(hit => hit.kind === 'hit' && hit.critical)) changes.push('会心の一撃');
        for (const [side, actor] of Object.entries(frame.actors)) {
            const before = previous[side];
            const prefix = side === action.side ? '' : `${actor.name}：`;
            for (const resource of actor.resources) {
                const old = before.resources.find(item => item.name === resource.name)?.points ?? 0;
                const delta = resource.points - old;
                if (delta) changes.push(`${prefix}${resource.name} ${delta > 0 ? '+' : ''}${delta}`);
            }
            actor.badges.filter(badge => !before.badges.some(old => old === badge || old.split(' ')[0] === badge.split(' ')[0]))
                .forEach(badge => changes.push(`${prefix}${badge}`));
        }
        if (changes.length) {
            const row = document.createElement('div'); row.className = 'br-summary-changes'; row.textContent = changes.join(' ｜ '); fragment.append(row);
        }
        return fragment;
    }
    function fallback(root) {
        // Reveal the existing result first, even if cleanup encounters damaged DOM.
        root.dataset.replayUnavailable = '1';
        root.dataset.replayComplete = '1';
        try { initializedRoots.get(root)?.destroy(); } catch { /* The result remains accessible. */ }
        delete root.dataset.replayReady;
        const log = root.querySelector('[data-replay-log]');
        if (log) log.textContent = '再生に失敗しました。下の戦闘ログと結果をご確認ください。';
        root.querySelectorAll('.br-controls button, [data-replay-effects]').forEach(control => { control.disabled = true; });
    }
    function safeInit(root) { try { init(root); } catch { fallback(root); } }
    function init(root) {
        if (initializedRoots.has(root) || root.dataset.replayUnavailable === '1') return;
        let data;
        data = JSON.parse(root.querySelector('[data-replay-data]').textContent);
        if (data?.version !== 1 || !Array.isArray(data.frames) || !data.frames.length) throw new Error('Unsupported replay');
        let cursor = 0, speed = savedSpeed(), running = false, timer;
        const listeners = new AbortController();
        const effectTimers = new Set();
        const pendingResources = new Map();
        const find = selector => root.querySelector(selector);
        const guard = callback => (...args) => { try { return callback(...args); } catch { fallback(root); } };
        const on = (selector, type, callback) => find(selector).addEventListener(type, guard(callback), {signal: listeners.signal});
        const actors = {player: find('[data-side="player"]'), enemy: find('[data-side="enemy"]')};
        const motion = matchMedia('(prefers-reduced-motion: reduce)');
        const motionChanged = guard(clearEffects);
        const instance = {root, stop: guard(stop), destroy() {
            running = false; clearTimeout(timer);
            effectTimers.forEach(clearTimeout); effectTimers.clear(); pendingResources.clear();
            listeners.abort();
            if (motion.removeEventListener) motion.removeEventListener('change', motionChanged);
            else motion.removeListener?.(motionChanged);
            root.querySelectorAll('.br-fx,.br-ghost,.br-resource-gain').forEach(el => el.remove());
            Object.values(actors).filter(Boolean).forEach(el => el.classList.remove('br-attacking', 'br-hit', 'br-dodge', 'br-critical'));
            initializedRoots.delete(root); delete root.dataset.replayReady; instances.delete(instance);
        }};
        instances.add(instance); initializedRoots.set(root, instance);
        function later(callback, delay) { const id = setTimeout(guard(() => { effectTimers.delete(id); callback(); }), delay); effectTimers.add(id); }
        function clearEffects() {
            effectTimers.forEach(clearTimeout); effectTimers.clear();
            pendingResources.forEach((resources, side) => renderResources(side, resources)); pendingResources.clear();
            root.querySelectorAll('.br-fx,.br-ghost,.br-resource-gain').forEach(el => el.remove());
            Object.values(actors).forEach(el => el.classList.remove('br-attacking', 'br-hit', 'br-dodge', 'br-critical'));
        }
        function stop() { running = false; clearTimeout(timer); clearEffects(); controls(); }
        function controls() {
            find('[data-replay-play]').textContent = running ? '一時停止' : (cursor === data.frames.length ? 'もう一度' : '再生');
            find('[data-replay-step]').disabled = cursor === data.frames.length;
            find('[data-replay-previous]').disabled = cursor === 0;
            find('[data-replay-speed]').textContent = `${speed}×`;
            find('[data-replay-result]').disabled = cursor === data.frames.length;
            root.dataset.replayCursor = cursor;
            root.dataset.replayComplete = cursor === data.frames.length ? '1' : '0';
        }
        function render(snapshot) {
            for (const [side, state] of Object.entries(snapshot)) {
                const actor = actors[side];
                for (const key of ['hp', 'sp']) {
                    const maximum = state[key === 'hp' ? 'maxHp' : 'maxSp'];
                    actor.querySelector(`[data-value="${key}"]`).textContent = `${number(state[key])}/${number(maximum)}`;
                    actor.querySelector(`[data-meter="${key}"] span`).style.width = `${maximum > 0 ? Math.max(0, Math.min(100, state[key] / maximum * 100)) : 0}%`;
                }
                renderResources(side, state.resources);
                const badges = actor.querySelector('[data-replay-badges]'); badges.replaceChildren();
                state.badges.forEach(text => { const badge = document.createElement('span'); badge.textContent = text; badges.append(badge); });
            }
        }
        function renderResources(side, resources) {
            actors[side].querySelector('[data-replay-resources]').textContent = resources.map(r => `${r.name} +${r.points}`).join(' ｜ ');
        }
        function animateResourceGains(snapshot, previous) {
            if (motion.matches) return;
            for (const [side, state] of Object.entries(snapshot)) {
                const gains = state.resources.map(resource => ({
                    name: resource.name,
                    delta: resource.points - (previous[side].resources.find(old => old.name === resource.name)?.points ?? 0),
                })).filter(resource => resource.delta > 0);
                if (!gains.length) continue;
                renderResources(side, state.resources.map(resource => ({
                    ...resource,
                    points: resource.points - (gains.find(gain => gain.name === resource.name)?.delta ?? 0),
                })));
                pendingResources.set(side, state.resources);
                const popup = document.createElement('span'); popup.className = 'br-resource-gain';
                popup.setAttribute('aria-hidden', 'true');
                popup.textContent = gains.map(gain => `${gain.name} +${gain.delta}`).join(' ｜ ');
                popup.style.setProperty('--gain-duration', `${850 / speed}ms`);
                actors[side].append(popup);
                later(() => { renderResources(side, state.resources); pendingResources.delete(side); }, 480 / speed);
                later(() => popup.remove(), 870 / speed);
            }
        }
        function paint(side, slot, ultimate = false) {
            if (motion.matches || !find('[data-replay-effects]').checked) return;
            const layer = document.createElement('span');
            layer.className = `br-fx${ultimate ? ' br-ultimate' : ''}`; layer.setAttribute('aria-hidden', 'true');
            layer.style.setProperty('--fx-duration', `${650 / speed}ms`);
            const ink = document.createElement('span'); ink.className = `br-paint texture-${textures[slot]}`;
            ink.style.backgroundPosition = `${slot % 4 * 100 / 3}% ${Math.floor(slot / 4) * 50}%`;
            layer.append(ink); actors[side].querySelector('.br-portrait').append(layer);
            later(() => layer.remove(), 680 / speed);
        }
        function animate(action) {
            if (!action.side || motion.matches) return;
            const attacker = actors[action.side];
            const hits = action.hits || [];
            if (hits.length) attacker.classList.add('br-attacking');
            const lineage = lineages.indexOf(action.lineage);
            if (action.skill) {
                const landed = hits.find(hit => hit.kind === 'hit' && hit.damage > 0);
                if (landed || !hits.length) paint(landed?.target || action.side, lineage < 0 ? 10 : lineage, action.ultimate);
            }
            hits.forEach((hit, index) => later(() => {
                const target = actors[hit.target];
                if (hit.kind === 'miss') {
                    target.classList.remove('br-hit'); target.classList.add('br-dodge');
                    const image = target.querySelector('.br-art');
                    if (image && find('[data-replay-effects]').checked) {
                        const ghost = image.cloneNode(); ghost.className += ' br-ghost'; ghost.alt = '';
                        target.querySelector('.br-portrait').append(ghost); later(() => ghost.remove(), 350 / speed);
                    }
                } else if (hit.damage > 0) {
                    target.classList.remove('br-hit'); void target.offsetWidth; target.classList.add('br-hit');
                    if (hit.critical) { target.classList.add('br-critical'); paint(hit.target, 11); }
                    else if (!action.skill) paint(hit.target, lineage < 0 ? 0 : lineage);
                }
            }, (150 + index * Math.min(100, 550 / Math.max(1, hits.length))) / speed));
            later(() => Object.values(actors).forEach(el => el.classList.remove('br-attacking', 'br-hit', 'br-dodge', 'br-critical')), 1050 / speed);
        }
        function step(withMotion = true) {
            if (cursor >= data.frames.length) { stop(); return; }
            clearEffects();
            const frame = data.frames[cursor++]; render(frame.actors);
            const previous = cursor > 1 ? data.frames[cursor - 2].actors : data.initial;
            find('[data-replay-turn]').textContent = frame.turn ? `ターン ${frame.turn}` : '戦闘開始';
            const log = find('[data-replay-log]');
            let summary = summaryFragment(frame, previous);
            for (let index = cursor - 2; !summary.childNodes.length && index >= 0; index--) {
                summary = summaryFragment(data.frames[index], index ? data.frames[index - 1].actors : data.initial);
            }
            log.replaceChildren(summary);
            log.scrollTop = 0;
            root.style.setProperty('--body-duration', `${380 / speed}ms`);
            if (withMotion) { animate(frame.action || {}); animateResourceGains(frame.actors, previous); }
            controls();
        }
        function tick() { if (!root.isConnected) { instance.destroy(); return; } step(); if (running && cursor < data.frames.length) timer = setTimeout(guard(tick), 1500 / speed); else { running = false; controls(); } }
        function reset() { stop(); cursor = 0; render(data.initial); find('[data-replay-turn]').textContent = '開始前'; find('[data-replay-log]').textContent = '「再生」で戦闘の経過を確認できます。'; find('[data-replay-log]').scrollTop = 0; controls(); }
        on('[data-replay-play]', 'click', () => { if (running) { stop(); return; } if (cursor === data.frames.length) reset(); running = true; tick(); });
        on('[data-replay-step]', 'click', () => { stop(); step(); });
        on('[data-replay-result]', 'click', () => {
            stop(); cursor = data.frames.length - 1; step(false);
            const result = root.closest('[data-battle-result-page]')?.querySelector('[data-compact-accordion="result"]');
            if (result) { result.open = true; result.scrollIntoView({block: 'nearest', behavior: motion.matches ? 'instant' : 'smooth'}); }
        });
        on('[data-replay-previous]', 'click', () => {
            stop();
            if (cursor <= 1) { reset(); return; }
            cursor -= 2; step(false);
        });
        on('[data-replay-speed]', 'click', () => {
            speed = playbackSpeeds[(playbackSpeeds.indexOf(speed) + 1) % playbackSpeeds.length];
            try { localStorage.setItem(speedStorageKey, String(speed)); } catch { /* Playback also works without storage. */ }
            controls();
            if (running) { clearTimeout(timer); timer = setTimeout(guard(tick), 1500 / speed); }
        });
        on('[data-replay-effects]', 'change', clearEffects);
        if (motion.addEventListener) motion.addEventListener('change', motionChanged);
        else motion.addListener(motionChanged);
        reset(); root.dataset.replayReady = '1';
    }
    window.valzeriaBattleReplayBoot = (scope = document) => {
        instances.forEach(instance => { if (!instance.root.isConnected) instance.destroy(); });
        if (scope.matches?.('[data-battle-replay]')) safeInit(scope);
        scope.querySelectorAll('[data-battle-replay]').forEach(safeInit);
    };
    document.addEventListener('livewire:navigating', () => instances.forEach(instance => instance.destroy()));
    document.addEventListener('livewire:navigated', () => window.valzeriaBattleReplayBoot());
    document.addEventListener('visibilitychange', () => { if (document.hidden) instances.forEach(instance => instance.stop()); });
    window.valzeriaBattleReplayBoot();
})();

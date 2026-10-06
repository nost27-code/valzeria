(() => {
    // Only browsing preferences are stored; never assets, selections or payment consent.
    window.attachFacilityPageState = (component, scope, characterId, rules, afterRestore = () => {}, { errorTarget = null } = {}) => {
        const key = `valzeria:${scope}:v1:${characterId}`;
        const clean = (values) => Object.fromEntries(Object.entries(rules).map(([name, rule]) => {
            const value = values?.[name];
            return [name, typeof rule === 'function' ? rule(value, component[name])
                : (typeof value === 'string' && rule.includes(value) ? value : component[name])];
        }));
        let saved = {};
        try { saved = JSON.parse(sessionStorage.getItem(key) || '{}') || {}; } catch (_) { /* Storage may be disabled. */ }
        Object.assign(component, clean(saved.values));
        const persist = () => {
            try { sessionStorage.setItem(key, JSON.stringify({ values: clean(component), scrollY: Math.max(0, window.scrollY) })); }
            catch (_) { /* The page still works without storage. */ }
        };
        for (const name of Object.keys(rules)) component.$watch(name, persist);
        window.addEventListener('pagehide', persist);
        window.addEventListener('facility-state-save', persist);
        component.$nextTick(() => {
            afterRestore();
            // Let Alpine apply filters and sorted cards before restoring the position.
            window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
                // A failed action must remain visible even when browsing was saved lower down.
                if (errorTarget?.isConnected) {
                    errorTarget.scrollIntoView({ block: 'center', behavior: 'auto' });
                    errorTarget.focus({ preventScroll: true });
                    return;
                }
                if (Number.isFinite(saved.scrollY) && saved.scrollY >= 0) window.scrollTo(0, saved.scrollY);
            }));
        });
        return () => {
            window.removeEventListener('pagehide', persist);
            window.removeEventListener('facility-state-save', persist);
        };
    };
    window.facilityCraftQuantities = (maxima) => (values) => {
        const result = {};
        for (const [code, max] of Object.entries(maxima)) {
            const value = Number(values?.[code]);
            const upper = Math.max(1, Math.min(99, Number(max) || 1));
            result[code] = Number.isFinite(value) ? Math.max(1, Math.min(upper, Math.trunc(value))) : 1;
        }
        return result;
    };
})();

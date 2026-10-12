<style>
    .battle-log-turn-vitals {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .5rem;
        margin: .5rem 0 .75rem;
        font-family: ui-sans-serif, system-ui, sans-serif;
        font-size: 11px;
        line-height: 1.5;
    }
    .battle-log-vitals-combatant {
        display: grid;
        grid-template-rows: 1fr auto auto;
        min-width: 0;
    }
    .battle-log-vitals-name,
    .battle-log-vitals-value {
        display: block;
        color: #475569;
        font-weight: 700;
        overflow-wrap: anywhere;
    }
    .battle-log-vitals-name { margin-bottom: .25rem; }
    .battle-log-vitals-resource { display: block; margin-top: .25rem; }
    .battle-log-vitals-value { font-variant-numeric: tabular-nums; }
    .battle-log-vitals-track {
        display: block;
        height: .5rem;
        margin-top: .25rem;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        border-radius: 9999px;
        background: #fff;
    }
    .battle-log-vitals-fill { display: block; height: 100%; border-radius: 9999px; }
    .battle-log-vitals-hp { background: #10b981; }
    .battle-log-vitals-sp { background: #0ea5e9; }
    @media (max-width: 639px) {
        .battle-log-turn-vitals { gap: .375rem; }
    }
</style>

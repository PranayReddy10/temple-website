<style>
    .ds-picker { display: flex; align-items: center; gap: .75rem; max-width: 28rem; }
    .ds-picker label { font-size: .875rem; font-weight: 500; white-space: nowrap; }
    .ds-picker > div { flex: 1; }
    .ds-grid { display: grid; gap: 1rem; }
    @media (min-width: 48rem) { .ds-grid--2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } .ds-grid--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    .ds-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr)); gap: .75rem; }
    .ds-kpi { border: 1px solid rgba(120, 113, 108, .22); border-radius: .75rem; padding: .85rem 1rem; }
    .ds-kpi__label { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; opacity: .65; }
    .ds-kpi__value { font-size: clamp(1.15rem, 1.6vw, 1.5rem); overflow-wrap: anywhere; font-weight: 700; margin-top: .2rem; font-variant-numeric: tabular-nums; }
    .ds-kpi__note { font-size: .8125rem; opacity: .75; margin-top: .15rem; }
    .ds-kpi--lead { background: rgba(155, 27, 48, .05); border-color: rgba(155, 27, 48, .3); }
    .ds-rows { width: 100%; font-size: .875rem; border-collapse: collapse; }
    .ds-rows th, .ds-rows td { padding: .5rem .25rem; border-bottom: 1px solid rgba(120, 113, 108, .15); text-align: left; }
    .ds-rows th { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; opacity: .65; }
    .ds-rows .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .ds-rows tr:last-child td { border-bottom: 0; }
    .ds-muted { font-size: .8125rem; opacity: .75; }
    .ds-dl { display: grid; grid-template-columns: max-content 1fr; gap: .4rem 1rem; font-size: .875rem; }
    .ds-dl dt { opacity: .7; }
    .ds-dl dd { font-weight: 500; }
    .ds-banner { border-radius: .75rem; padding: .9rem 1rem; display: flex; gap: .75rem; align-items: flex-start; border: 1px solid; }
    .ds-banner__icon { width: 1.5rem; height: 1.5rem; flex: none; }
    .ds-banner--approved { border-color: rgba(16, 185, 129, .45); background: rgba(16, 185, 129, .07); }
    .ds-banner--pending { border-color: rgba(201, 162, 39, .5); background: rgba(201, 162, 39, .08); }
    .ds-banner--rejected, .ds-banner--missing { border-color: rgba(155, 27, 48, .4); background: rgba(155, 27, 48, .06); }
</style>

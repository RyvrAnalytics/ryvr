// Styles live in each widget's shadow root. Hosts theme the widgets with CSS custom
// properties (they cross the shadow boundary) and ::part() selectors.

export const CSS = `
:host{display:block;
--fg:var(--ryvr-fg,#1b1b1a);--muted:var(--ryvr-muted,#6b6b66);--bg:var(--ryvr-bg,#fff);
--accent:var(--ryvr-accent,#2f6fdb);--grid:var(--ryvr-grid,#e6e6e3);--radius:var(--ryvr-radius,8px);
--pos:var(--ryvr-positive,#1f8a4c);--neg:var(--ryvr-negative,#c2412d);
font:14px/1.4 var(--ryvr-font,system-ui,-apple-system,"Segoe UI",sans-serif);color:var(--fg)}
:host([data-theme=dark]){--fg:var(--ryvr-fg,#ececea);--muted:var(--ryvr-muted,#9d9d98);--bg:var(--ryvr-bg,#1c1c1b);
--accent:var(--ryvr-accent,#6ea0ff);--grid:var(--ryvr-grid,#333331);--pos:var(--ryvr-positive,#4cc27f);--neg:var(--ryvr-negative,#ff7a63)}
@media (prefers-color-scheme:dark){:host([data-theme=auto]){--fg:var(--ryvr-fg,#ececea);--muted:var(--ryvr-muted,#9d9d98);
--bg:var(--ryvr-bg,#1c1c1b);--accent:var(--ryvr-accent,#6ea0ff);--grid:var(--ryvr-grid,#333331);
--pos:var(--ryvr-positive,#4cc27f);--neg:var(--ryvr-negative,#ff7a63)}}
*{box-sizing:border-box}
.bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
select{font:inherit;color:var(--fg);background:var(--bg);border:1px solid var(--grid);border-radius:var(--radius);padding:4px 8px}
.grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr))}
.card{background:var(--bg);border:1px solid var(--grid);border-radius:var(--radius);padding:16px;min-width:0}
.wide{grid-column:1/-1}
.title{margin:0 0 12px;font-size:13px;font-weight:500;color:var(--muted)}
.kpis{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(110px,1fr))}
.label{font-size:12px;color:var(--muted)}
.value{font-size:24px;font-weight:300;font-variant-numeric:tabular-nums}
.delta{font-size:12px;color:var(--muted)}.up{color:var(--pos)}.down{color:var(--neg)}
svg{display:block;width:100%;height:160px;overflow:visible}
.axis{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:4px}
ol{list-style:none;margin:0;padding:0}
li{position:relative;display:flex;justify-content:space-between;gap:12px;padding:4px 8px;margin-bottom:2px}
li::before{content:"";position:absolute;inset:0 auto 0 0;width:var(--w,0%);background:var(--accent);opacity:.12;border-radius:4px}
li span{position:relative;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
li span+span{flex:none;font-variant-numeric:tabular-nums}
.live{display:flex;align-items:center;gap:10px}
.dot{width:8px;height:8px;border-radius:50%;background:var(--pos);animation:p 2s infinite}
@keyframes p{50%{opacity:.35}}
@media (prefers-reduced-motion:reduce){.dot{animation:none}}
.note{color:var(--muted);font-size:13px;margin:0}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
`;

let sheet: CSSStyleSheet | null = null;

/** Attach styles without inline <style> where the browser allows it (stricter host CSPs). */
export function adoptStyles(root: ShadowRoot): void {
    try {
        if (!sheet) {
            sheet = new CSSStyleSheet();
            sheet.replaceSync(CSS);
        }
        root.adoptedStyleSheets = [sheet];
    } catch {
        const style = document.createElement('style');
        style.textContent = CSS;
        root.appendChild(style);
    }
}

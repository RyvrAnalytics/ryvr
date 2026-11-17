// Widget renderers. Every string from the API is set with textContent (never innerHTML),
// so page paths or referrers containing markup render as plain text.

import { EmbedError, type Session } from './session';

export const WIDGETS = [
    'kpi',
    'trend',
    'top_pages',
    'top_sources',
    'realtime',
] as const;
export type WidgetName = (typeof WIDGETS)[number];

export type Params = {
    site?: string;
    period?: string;
    limit?: string;
    by?: string;
};

type Summary = {
    visitors: number;
    visits: number;
    pageviews: number;
    bounce_rate: number;
    avg_duration: number;
};
type Range = { from: string; to: string };
type Row = { value: string; visitors: number };

const TITLES: Record<WidgetName, string> = {
    kpi: 'Overview',
    trend: 'Visitors',
    top_pages: 'Top pages',
    top_sources: 'Top sources',
    realtime: 'Online now',
};

const SVG = 'http://www.w3.org/2000/svg';

export function h<K extends keyof HTMLElementTagNameMap>(
    tag: K,
    cls?: string,
    text?: string,
): HTMLElementTagNameMap[K] {
    const el = document.createElement(tag);
    if (cls) el.className = cls;
    if (text !== undefined) el.textContent = text;
    return el;
}

function svg(tag: string, attrs: Record<string, string>): SVGElement {
    const el = document.createElementNS(SVG, tag);
    for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, v);
    return el;
}

const nf = new Intl.NumberFormat(undefined, {
    notation: 'compact',
    maximumFractionDigits: 1,
});
const num = (n: number) => nf.format(n || 0);
const duration = (s: number) =>
    s >= 60
        ? `${Math.floor(s / 60)}m ${Math.round(s % 60)}s`
        : `${Math.round(s || 0)}s`;

function delta(
    current: number,
    previous: number,
    lowerIsBetter = false,
): HTMLElement {
    if (!previous) return h('div', 'delta', ' ');
    const change = ((current - previous) / previous) * 100;
    const good = lowerIsBetter ? change < 0 : change > 0;
    const sign = change > 0 ? '+' : '';
    return h(
        'div',
        'delta ' + (Math.abs(change) < 0.5 ? '' : good ? 'up' : 'down'),
        `${sign}${change.toFixed(0)}%`,
    );
}

function kpi(
    body: HTMLElement,
    data: { current: Summary; previous: Summary },
): void {
    const { current: c, previous: p } = data;
    const grid = h('div', 'kpis');
    const items: [string, string, HTMLElement][] = [
        ['Visitors', num(c.visitors), delta(c.visitors, p.visitors)],
        ['Visits', num(c.visits), delta(c.visits, p.visits)],
        ['Pageviews', num(c.pageviews), delta(c.pageviews, p.pageviews)],
        [
            'Bounce rate',
            `${Math.round(c.bounce_rate)}%`,
            delta(c.bounce_rate, p.bounce_rate, true),
        ],
        [
            'Visit duration',
            duration(c.avg_duration),
            delta(c.avg_duration, p.avg_duration),
        ],
    ];
    for (const [label, value, change] of items) {
        const cell = h('div');
        const v = h('div', 'value', value);
        v.setAttribute('part', 'value');
        cell.append(h('div', 'label', label), v, change);
        grid.append(cell);
    }
    body.append(grid);
}

function trend(
    body: HTMLElement,
    data: { range: Range; points: { bucket: string; visitors: number }[] },
): void {
    const points = data.points;
    if (!points.length) {
        body.append(h('p', 'note', 'No data for this period.'));
        return;
    }
    const w = 600;
    const ht = 160;
    const max = Math.max(1, ...points.map((p) => p.visitors));
    const step = points.length > 1 ? w / (points.length - 1) : 0;
    const xy = points.map(
        (p, i) =>
            `${(i * step).toFixed(1)},${(ht - (p.visitors / max) * (ht - 8)).toFixed(1)}`,
    );

    const chart = svg('svg', {
        viewBox: `0 0 ${w} ${ht}`,
        preserveAspectRatio: 'none',
        role: 'img',
        part: 'chart',
        'aria-label': `Visitors from ${data.range.from} to ${data.range.to}, peak ${max}`,
    });
    for (const f of [0.25, 0.5, 0.75]) {
        chart.append(
            svg('line', {
                x1: '0',
                x2: String(w),
                y1: String(ht * f),
                y2: String(ht * f),
                stroke: 'var(--grid)',
                'vector-effect': 'non-scaling-stroke',
            }),
        );
    }
    chart.append(
        svg('polygon', {
            points: `0,${ht} ${xy.join(' ')} ${(step * (points.length - 1)).toFixed(1)},${ht}`,
            fill: 'var(--accent)',
            opacity: '.12',
        }),
        svg('polyline', {
            points: xy.join(' '),
            fill: 'none',
            stroke: 'var(--accent)',
            'stroke-width': '2',
            'vector-effect': 'non-scaling-stroke',
        }),
    );

    const axis = h('div', 'axis');
    axis.append(
        h('span', '', points[0].bucket.slice(0, 10)),
        h('span', '', points[points.length - 1].bucket.slice(0, 10)),
    );

    // Data table fallback for screen readers.
    const table = h('table', 'sr');
    table.setAttribute('part', 'table');
    for (const p of points) {
        const tr = h('tr');
        tr.append(h('td', '', p.bucket), h('td', '', String(p.visitors)));
        table.append(tr);
    }
    body.append(chart, axis, table);
}

function list(
    body: HTMLElement,
    rows: Row[],
    empty: string,
    label: (v: string) => string,
): void {
    if (!rows.length) {
        body.append(h('p', 'note', empty));
        return;
    }
    const max = Math.max(1, ...rows.map((r) => r.visitors));
    const ol = h('ol');
    ol.setAttribute('part', 'table');
    for (const r of rows) {
        const li = h('li');
        li.style.setProperty(
            '--w',
            `${((r.visitors / max) * 100).toFixed(1)}%`,
        );
        const name = h('span', '', label(r.value));
        name.title = r.value;
        li.append(name, h('span', '', num(r.visitors)));
        ol.append(li);
    }
    body.append(ol);
}

function realtime(body: HTMLElement, data: { visitors: number }): void {
    const row = h('div', 'live');
    const v = h('span', 'value', num(data.visitors));
    v.setAttribute('part', 'value');
    row.append(
        h('span', 'dot'),
        v,
        h(
            'span',
            'label',
            data.visitors === 1
                ? 'visitor in the last 5 minutes'
                : 'visitors in the last 5 minutes',
        ),
    );
    body.append(row);
}

export function message(code: string): string {
    switch (code) {
        case 'token_expired':
        case 'token_unavailable':
        case 'key_not_found':
        case 'token_revoked':
            return 'This view has expired. Reload the page.';
        case 'origin_not_allowed':
            return 'This view cannot be shown on this page.';
        case 'rate_limited':
            return 'Too many requests. Try again in a minute.';
        default:
            return 'Could not load this chart.';
    }
}

/**
 * Render one widget into a card. Returns a stop function (for realtime polling).
 */
export function renderWidget(
    card: HTMLElement,
    name: WidgetName,
    session: Session,
    params: Params,
    showTitle: boolean,
    onError: (e: EmbedError) => void,
): () => void {
    card.replaceChildren();
    card.className =
        'card' + (name === 'kpi' || name === 'trend' ? ' wide' : '');
    card.setAttribute('part', 'card');
    card.setAttribute('aria-busy', 'true');
    if (showTitle) {
        const title = h('h3', 'title', TITLES[name]);
        title.setAttribute('part', 'title');
        card.append(title);
    }
    const body = h('div');
    body.append(h('p', 'note', 'Loading'));
    card.append(body);

    const base = { site: params.site, period: params.period };
    const load = async (): Promise<void> => {
        const out = h('div');
        switch (name) {
            case 'kpi':
                kpi(out, await session.api('summary', base));
                break;
            case 'trend':
                trend(out, await session.api('trend', base));
                break;
            case 'top_pages':
                list(
                    out,
                    (
                        await session.api<{ rows: Row[] }>('top/pages', {
                            ...base,
                            limit: params.limit,
                        })
                    ).rows,
                    'No pageviews in this period.',
                    (v) => v,
                );
                break;
            case 'top_sources':
                list(
                    out,
                    (
                        await session.api<{ rows: Row[] }>('top/sources', {
                            ...base,
                            limit: params.limit,
                            by: params.by,
                        })
                    ).rows,
                    'No visits in this period.',
                    (v) => v || 'Direct',
                );
                break;
            case 'realtime':
                realtime(
                    out,
                    await session.api('realtime', { site: params.site }),
                );
                break;
        }
        if (stopped) return;
        body.replaceChildren(...Array.from(out.childNodes));
        card.removeAttribute('aria-busy');
    };

    let stopped = false;
    const run = (quiet: boolean) =>
        load().catch((e: unknown) => {
            if (stopped || quiet) return;
            const err =
                e instanceof EmbedError
                    ? e
                    : new EmbedError('request_failed', String(e));
            body.replaceChildren(h('p', 'note', message(err.code)));
            card.removeAttribute('aria-busy');
            onError(err);
        });
    void run(false);

    // Realtime refreshes while the page is visible; failed polls keep the last number.
    const timer =
        name === 'realtime'
            ? setInterval(() => {
                  if (!document.hidden) void run(true);
              }, 30000)
            : undefined;

    return () => {
        stopped = true;
        if (timer) clearInterval(timer);
    };
}

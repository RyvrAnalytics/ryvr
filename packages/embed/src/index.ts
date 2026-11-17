/*!
 * RYVR embed. MIT licence.
 * Read-only analytics widgets for your own app, authorised by short-lived embed tokens that
 * your server signs. Never pass an API key or the embed secret to this library.
 */

import {
    EmbedError,
    Session,
    originMatches,
    type TokenFetcher,
} from './session';
import { adoptStyles } from './styles';
import {
    WIDGETS,
    h,
    message,
    renderWidget,
    type Params,
    type WidgetName,
} from './widgets';

type Theme = 'light' | 'dark' | 'auto';

export type MountOptions = {
    /** Called on start and shortly before the token expires. Return a fresh embed token. */
    fetchToken: TokenFetcher;
    /** RYVR base URL. Defaults to the origin this script was loaded from. */
    host?: string;
    /** Embed key id (ek_...). Optional: read from the token header when omitted. */
    kid?: string;
    /** Widgets to show, in order. Defaults to every widget the token allows. */
    widgets?: WidgetName[];
    theme?: Theme;
    site?: string;
    period?: string;
    limit?: number;
    /** Show period and site pickers above a dashboard (default true). */
    controls?: boolean;
    /** Show widget titles (default true). */
    titles?: boolean;
};

type Meta = {
    sites: { id: string; name: string; domain: string }[];
    widgets: WidgetName[];
    period: { default: string; allowed: string[] };
    origins: string[];
    theme: Theme;
};

export type Handle = { destroy: () => void; refresh: () => void };

const PERIOD_LABELS: Record<string, string> = {
    today: 'Today',
    yesterday: 'Yesterday',
    '24h': 'Last 24 hours',
    '7d': 'Last 7 days',
    '30d': 'Last 30 days',
    month: 'This month',
    last_month: 'Last month',
    '90d': 'Last 90 days',
    '12mo': 'Last 12 months',
    year: 'This year',
};

const scriptOrigin = (() => {
    try {
        const src = (document.currentScript as HTMLScriptElement | null)?.src;
        return src ? new URL(src).origin : location.origin;
    } catch {
        return location.origin;
    }
})();

function emit(target: Element, type: string, detail?: unknown): void {
    target.dispatchEvent(
        new CustomEvent('ryvr:' + type, {
            detail,
            bubbles: true,
            composed: true,
        }),
    );
}

function shadow(el: Element): ShadowRoot {
    const root = el.shadowRoot ?? el.attachShadow({ mode: 'open' });
    root.replaceChildren();
    adoptStyles(root);
    return root;
}

function errorHandler(el: Element) {
    return (e: EmbedError) => {
        emit(
            el,
            e.code === 'token_expired' || e.code === 'token_unavailable'
                ? 'expired'
                : 'error',
            { code: e.code, message: e.message },
        );
    };
}

/**
 * Render a dashboard of widgets into an element. All widgets share one token.
 */
function mountWith(
    el: HTMLElement,
    session: Session,
    opts: Omit<MountOptions, 'fetchToken'>,
): Handle {
    const root = shadow(el);
    const stops: (() => void)[] = [];
    let destroyed = false;
    const state: Params = {
        site: opts.site,
        period: opts.period,
        limit: opts.limit ? String(opts.limit) : undefined,
    };

    const fail = (e: unknown) => {
        const err =
            e instanceof EmbedError
                ? e
                : new EmbedError('request_failed', String(e));
        root.replaceChildren(h('p', 'note', message(err.code)));
        adoptStyles(root);
        errorHandler(el)(err);
    };

    const start = async () => {
        const meta = await session.api<Meta>('meta');
        if (destroyed) return;
        el.dataset.theme = opts.theme ?? meta.theme ?? 'auto';
        state.period ??= meta.period.default;

        const wanted = (opts.widgets ?? meta.widgets).filter(
            (w) => meta.widgets.includes(w) && WIDGETS.includes(w),
        );
        const grid = h('div', 'grid');
        const draw = () => {
            stops.splice(0).forEach((stop) => stop());
            grid.replaceChildren();
            for (const name of wanted) {
                const card = h('section');
                grid.append(card);
                stops.push(
                    renderWidget(
                        card,
                        name,
                        session,
                        state,
                        opts.titles !== false,
                        errorHandler(el),
                    ),
                );
            }
        };

        const bar = h('div', 'bar');
        if (opts.controls !== false) {
            if (meta.period.allowed.length > 1) {
                bar.append(
                    picker(
                        'Period',
                        meta.period.allowed.map((p) => [
                            p,
                            PERIOD_LABELS[p] ?? p,
                        ]),
                        state.period,
                        (v) => {
                            state.period = v;
                            draw();
                        },
                    ),
                );
            }
            if (meta.sites.length > 1 && !opts.site) {
                bar.append(
                    picker(
                        'Site',
                        [
                            ['', 'All sites'],
                            ...meta.sites.map((s): [string, string] => [
                                s.id,
                                s.name,
                            ]),
                        ],
                        '',
                        (v) => {
                            state.site = v || undefined;
                            draw();
                        },
                    ),
                );
            }
        }
        root.append(...(bar.childNodes.length ? [bar] : []), grid);
        draw();
        emit(el, 'ready', { widgets: wanted });
    };
    start().catch(fail);

    return {
        destroy() {
            destroyed = true;
            stops.splice(0).forEach((stop) => stop());
            root.replaceChildren();
        },
        refresh() {
            stops.splice(0).forEach((stop) => stop());
            root.replaceChildren();
            adoptStyles(root);
            start().catch(fail);
        },
    };
}

function picker(
    label: string,
    options: [string, string][],
    value: string | undefined,
    onChange: (v: string) => void,
): HTMLSelectElement {
    const select = h('select');
    select.setAttribute('aria-label', label);
    for (const [v, text] of options) {
        const option = h('option', '', text);
        option.value = v;
        option.selected = v === (value ?? '');
        select.append(option);
    }
    select.addEventListener('change', () => onChange(select.value));
    return select;
}

// ---------------------------------------------------------------------------------------------
// Global configuration for <ryvr-widget> elements.

let shared: Session | null = null;
let sharedTheme: Theme | undefined;
const handles = new Set<Handle>();
const waiting = new Set<RyvrWidget>();

function configure(opts: Omit<MountOptions, 'widgets'>): void {
    if (typeof opts?.fetchToken !== 'function')
        throw new Error('RyvrEmbed.configure needs fetchToken.');
    shared?.destroy();
    shared = new Session(
        (opts.host ?? scriptOrigin).replace(/\/$/, ''),
        opts.kid,
        opts.fetchToken,
    );
    sharedTheme = opts.theme;
    waiting.forEach((w) => w.render());
}

function mount(el: HTMLElement, opts: MountOptions): Handle {
    if (typeof opts?.fetchToken !== 'function')
        throw new Error('RyvrEmbed.mount needs fetchToken.');
    const session = new Session(
        (opts.host ?? scriptOrigin).replace(/\/$/, ''),
        opts.kid,
        opts.fetchToken,
    );
    const inner = mountWith(el, session, opts);
    const handle: Handle = {
        destroy() {
            inner.destroy();
            session.destroy();
            handles.delete(handle);
        },
        refresh: inner.refresh,
    };
    handles.add(handle);
    return handle;
}

/** Forget every token and stop every widget (call on logout). */
function destroy(): void {
    handles.forEach((handle) => handle.destroy());
    shared?.destroy();
    shared = null;
    document
        .querySelectorAll('ryvr-widget')
        .forEach((el) => (el as RyvrWidget).stop());
}

const Base = (
    typeof HTMLElement === 'undefined' ? class {} : HTMLElement
) as typeof HTMLElement;

/**
 * <ryvr-widget type="trend" period="30d" site="..." limit="10" theme="dark"></ryvr-widget>
 * Uses the token source set with RyvrEmbed.configure().
 */
class RyvrWidget extends Base {
    static observedAttributes = [
        'type',
        'period',
        'site',
        'limit',
        'by',
        'theme',
    ];
    private handle: Handle | null = null;

    connectedCallback(): void {
        this.render();
    }

    disconnectedCallback(): void {
        this.stop();
        waiting.delete(this);
    }

    attributeChangedCallback(): void {
        if (this.isConnected) this.render();
    }

    stop(): void {
        this.handle?.destroy();
        this.handle = null;
    }

    render(): void {
        this.stop();
        if (!shared) {
            waiting.add(this);
            return;
        }
        waiting.delete(this);
        const type = this.getAttribute('type') as WidgetName;
        if (!WIDGETS.includes(type)) return;
        const session = shared;
        const root = shadow(this);
        const card = h('section');
        root.append(card);
        this.dataset.theme =
            (this.getAttribute('theme') as Theme) ?? sharedTheme ?? 'auto';
        const params: Params = {
            site: this.getAttribute('site') ?? undefined,
            period: this.getAttribute('period') ?? undefined,
            limit: this.getAttribute('limit') ?? undefined,
            by: this.getAttribute('by') ?? undefined,
        };
        const stop = renderWidget(
            card,
            type,
            session,
            params,
            this.getAttribute('titles') !== 'false',
            errorHandler(this),
        );
        this.handle = { destroy: stop, refresh: () => this.render() };
    }
}

// ---------------------------------------------------------------------------------------------
// Iframe delivery. The parent page uses RyvrEmbed.iframe(); the RYVR-hosted page calls frame().
// Tokens travel by postMessage (or the URL fragment), never in a path or query string.

type FrameMessage = { type?: string; token?: string; height?: number };

/** Parent side: create the iframe and answer its token requests. */
function iframe(
    el: HTMLElement,
    opts: {
        fetchToken: TokenFetcher;
        kid: string;
        host?: string;
        theme?: Theme;
        title?: string;
    },
): Handle {
    const host = (opts.host ?? scriptOrigin).replace(/\/$/, '');
    const origin = new URL(host).origin;
    const frame = document.createElement('iframe');
    frame.src = `${host}/embed/v1/${encodeURIComponent(opts.kid)}/dashboard#theme=${opts.theme ?? 'auto'}`;
    frame.title = opts.title ?? 'Website analytics';
    frame.loading = 'lazy';
    frame.referrerPolicy = 'strict-origin-when-cross-origin';
    frame.setAttribute('sandbox', 'allow-scripts allow-same-origin');
    frame.style.cssText = 'width:100%;border:0;min-height:320px';

    const onMessage = (event: MessageEvent<FrameMessage>) => {
        if (event.source !== frame.contentWindow || event.origin !== origin)
            return;
        const type = event.data?.type;
        if (type === 'ryvr:ready' || type === 'ryvr:token-expiring') {
            opts.fetchToken().then(
                (token) =>
                    frame.contentWindow?.postMessage(
                        { type: 'ryvr:token', token },
                        origin,
                    ),
                () => emit(el, 'expired', { code: 'token_unavailable' }),
            );
        } else if (
            type === 'ryvr:height' &&
            typeof event.data.height === 'number'
        ) {
            frame.style.height = `${Math.ceil(event.data.height)}px`;
        }
    };
    window.addEventListener('message', onMessage);
    el.replaceChildren(frame);

    return {
        destroy() {
            window.removeEventListener('message', onMessage);
            frame.remove();
        },
        refresh() {
            frame.setAttribute('src', frame.src);
        },
    };
}

/** Frame side: runs on /embed/v1/{kid}/dashboard. */
function frameMain(el: HTMLElement | null): void {
    if (!el) return;
    const kid = el.dataset.kid ?? '';
    const hash = new URLSearchParams(location.hash.slice(1));
    let initial = hash.get('token');
    const theme = (hash.get('theme') as Theme | null) ?? undefined;
    // Drop the token from the address bar and history; keep it in memory only.
    if (location.hash) history.replaceState(null, '', location.pathname);

    const parentOrigin = (() => {
        try {
            const ancestors = location.ancestorOrigins;
            if (ancestors?.length) return ancestors[0];
            return document.referrer ? new URL(document.referrer).origin : '';
        } catch {
            return '';
        }
    })();
    const inFrame = window.parent !== window;

    let resolveToken: ((token: string) => void) | null = null;
    window.addEventListener('message', (event: MessageEvent<FrameMessage>) => {
        if (
            event.source !== window.parent ||
            (parentOrigin && event.origin !== parentOrigin)
        )
            return;
        if (
            event.data?.type === 'ryvr:token' &&
            typeof event.data.token === 'string' &&
            resolveToken
        ) {
            resolveToken(event.data.token);
            resolveToken = null;
        }
    });

    const ask = (type: string) =>
        new Promise<string>((resolve, reject) => {
            if (!inFrame) return reject(new Error('no parent'));
            resolveToken = resolve;
            window.parent.postMessage({ type }, parentOrigin || '*');
            setTimeout(() => reject(new Error('timeout')), 10000);
        });

    const session: Session = new Session(
        location.origin,
        kid,
        (): Promise<string> => {
            if (initial) {
                const token = initial;
                initial = null;
                return Promise.resolve(token);
            }
            return ask(
                session.claims.exp ? 'ryvr:token-expiring' : 'ryvr:ready',
            );
        },
    );

    // Defence in depth: frame-ancestors is the real control, but also refuse to render for
    // a parent the token does not name.
    session
        .get()
        .then(() => {
            const origins = session.claims.ryvr?.origins ?? [];
            if (
                inFrame &&
                (!parentOrigin ||
                    !origins.some((o) => originMatches(parentOrigin, o)))
            ) {
                const root = shadow(el);
                root.append(h('p', 'note', message('origin_not_allowed')));
                return;
            }
            mountWith(el, session, { theme });
            if (inFrame && 'ResizeObserver' in window) {
                new ResizeObserver(() => {
                    window.parent.postMessage(
                        {
                            type: 'ryvr:height',
                            height: document.documentElement.scrollHeight,
                        },
                        parentOrigin || '*',
                    );
                }).observe(document.body);
            }
        })
        .catch((e: unknown) => {
            const root = shadow(el);
            root.append(
                h(
                    'p',
                    'note',
                    message(
                        e instanceof EmbedError ? e.code : 'token_unavailable',
                    ),
                ),
            );
        });
}

export const RyvrEmbed = {
    configure,
    mount,
    destroy,
    iframe,
    frame: frameMain,
    version: '0.1.0',
};

declare global {
    interface Window {
        RyvrEmbed?: typeof RyvrEmbed;
    }
}

if (typeof window !== 'undefined') {
    window.RyvrEmbed = RyvrEmbed;
    if (window.customElements && !customElements.get('ryvr-widget')) {
        customElements.define('ryvr-widget', RyvrWidget);
    }
}

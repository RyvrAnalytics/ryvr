'use strict';
(() => {
    ((r, i) => {
        var q, D;
        let y = i.currentScript;
        if (!y) return;
        let a = (e) => y.getAttribute('data-' + e),
            S = a('key');
        if (!S) return;
        let L = a('api') || new URL(y.src).origin + '/e',
            R = a('hash') !== null,
            O = a('local') !== null,
            T = a('auto-links') !== 'false',
            U = a('consent') === 'required',
            s = r.location,
            m = r.navigator,
            o = (() => {
                try {
                    return r.localStorage;
                } catch (e) {
                    return null;
                }
            })(),
            P = new URLSearchParams(s.search).get('ryvr_ignore');
        P !== null &&
            (P === '0'
                ? o == null || o.removeItem('ryvr_ignore')
                : o == null || o.setItem('ryvr_ignore', '1'));
        let u = U ? 'pending' : null,
            k = [],
            W = () =>
                (o == null ? void 0 : o.getItem('ryvr_ignore')) === '1' ||
                (!O &&
                    /^(localhost|127\.|\[::1\]|0\.0\.0\.0)|\.local$|\.test$/.test(
                        s.hostname,
                    )) ||
                s.protocol === 'file:',
            p = (e, t = !1) => {
                if (W()) return;
                if (u === 'pending') {
                    k.push(e);
                    return;
                }
                let n = JSON.stringify({
                    k: S,
                    u: s.href,
                    r: i.referrer,
                    w: r.innerWidth,
                    wd: m.webdriver ? 1 : 0,
                    c: u != null ? u : void 0,
                    ...e,
                });
                if (t && m.sendBeacon) {
                    m.sendBeacon(L, new Blob([n], { type: 'text/plain' }));
                    return;
                }
                fetch(L, {
                    method: 'POST',
                    body: n,
                    keepalive: !0,
                    headers: { 'Content-Type': 'text/plain' },
                }).catch(() => {});
            },
            d = i.visibilityState === 'visible' ? Date.now() : 0,
            h = 0,
            b = () => {
                (d && ((h += Date.now() - d), (d = 0)),
                    h >= 1e3 && p({ n: 'engagement', e: h }, !0),
                    (h = 0));
            };
        (i.addEventListener('visibilitychange', () => {
            i.visibilityState === 'hidden' ? b() : (d = Date.now());
        }),
            r.addEventListener('pagehide', b));
        let w = '',
            c = () => {
                let e = R ? s.href : s.href.split('#')[0];
                e !== w &&
                    (w &&
                        (b(),
                        i.visibilityState === 'visible' && (d = Date.now())),
                    (w = e),
                    p({ n: 'pageview' }));
            },
            g = (e, t, n) => {
                let l = { n: e, p: t };
                ((n == null ? void 0 : n.revenue) !== void 0 &&
                    ((l.$ = n.revenue), (l.cur = n.currency)),
                    p(l));
            },
            E = r.history;
        for (let e of ['pushState', 'replaceState']) {
            let t = E[e];
            E[e] = function (...n) {
                (t.apply(this, n), c());
            };
        }
        if (
            (r.addEventListener('popstate', c),
            R && r.addEventListener('hashchange', c),
            T)
        ) {
            let e =
                /\.(pdf|zip|dmg|exe|msi|pkg|csv|xlsx?|docx?|pptx?|mp3|mp4|mov|rar|7z|gz|apk|ipa|epub)$/i;
            i.addEventListener(
                'click',
                (t) => {
                    var v, $;
                    let n =
                        ($ = (v = t.target) == null ? void 0 : v.closest) ==
                        null
                            ? void 0
                            : $.call(v, 'a');
                    if (!n || !n.href) return;
                    let l = n.getAttribute('data-ryvr-event');
                    if (l) {
                        g(l);
                        return;
                    }
                    let f = new URL(n.href, s.href);
                    /^https?:$/.test(f.protocol) &&
                        (e.test(f.pathname)
                            ? g('File download', { url: f.href })
                            : f.hostname !== s.hostname &&
                              g('Outbound link', { url: f.href }));
                },
                { capture: !0 },
            );
        }
        let x = {
                track: g,
                pageview: c,
                consent: (e) => {
                    if (((u = e ? 'granted' : 'denied'), !e)) {
                        k.length = 0;
                        return;
                    }
                    k.splice(0).forEach((t) => p(t));
                },
            },
            _ = (D = (q = r.ryvr) == null ? void 0 : q.q) != null ? D : [];
        r.ryvr = x;
        for (let [e, ...t] of _) {
            let n = x[e];
            typeof n == 'function' && n(...t);
        }
        i.readyState === 'loading'
            ? i.addEventListener('DOMContentLoaded', c)
            : c();
    })(window, document);
})();

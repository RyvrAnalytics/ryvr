/*!
 * RYVR tracker. MIT licence.
 * Cookieless page and event tracking. No personal data is stored in the browser.
 */

type Props = Record<string, string | number | boolean | null>;
type Revenue = { revenue?: number; currency?: string };
type Payload = Record<string, unknown>;

interface Ryvr {
  track: (name: string, props?: Props, revenue?: Revenue) => void;
  pageview: () => void;
  consent: (granted: boolean) => void;
  q?: unknown[][];
}

declare global {
  interface Window {
    ryvr?: Ryvr | ((...args: unknown[]) => void) & { q?: unknown[][] };
  }
}

((w: Window, d: Document) => {
  const script = d.currentScript as HTMLScriptElement | null;
  if (!script) return;

  const attr = (name: string) => script.getAttribute('data-' + name);
  const key = attr('key');
  if (!key) return;

  const endpoint = attr('api') || new URL(script.src).origin + '/e';
  const hashMode = attr('hash') !== null;
  const trackLocal = attr('local') !== null;
  const autoLinks = attr('auto-links') !== 'false';
  const consentRequired = attr('consent') === 'required';
  const loc = w.location;
  const nav = w.navigator;
  const storage = (() => {
    try {
      return w.localStorage;
    } catch {
      return null;
    }
  })();

  // ?ryvr_ignore=1 marks this browser as yours: nothing is sent from it. ?ryvr_ignore=0 undoes it.
  const ignoreParam = new URLSearchParams(loc.search).get('ryvr_ignore');
  if (ignoreParam !== null) {
    if (ignoreParam === '0') storage?.removeItem('ryvr_ignore');
    else storage?.setItem('ryvr_ignore', '1');
  }

  let consent: string | null = consentRequired ? 'pending' : null;
  const pendingWhileConsent: Payload[] = [];

  const blocked = () =>
    storage?.getItem('ryvr_ignore') === '1' ||
    (!trackLocal && /^(localhost|127\.|\[::1\]|0\.0\.0\.0)|\.local$|\.test$/.test(loc.hostname)) ||
    loc.protocol === 'file:';

  const send = (payload: Payload, beacon = false) => {
    if (blocked()) return;
    if (consent === 'pending') {
      pendingWhileConsent.push(payload);
      return;
    }
    const body = JSON.stringify({
      k: key,
      u: loc.href,
      r: d.referrer,
      w: w.innerWidth,
      wd: nav.webdriver ? 1 : 0,
      c: consent ?? undefined,
      ...payload,
    });
    // text/plain keeps the request "simple": no CORS preflight.
    if (beacon && nav.sendBeacon) {
      nav.sendBeacon(endpoint, new Blob([body], { type: 'text/plain' }));
      return;
    }
    fetch(endpoint, { method: 'POST', body, keepalive: true, headers: { 'Content-Type': 'text/plain' } }).catch(
      () => {},
    );
  };

  // Engagement: foreground time on the current page, sent when the page is left.
  let visibleSince = d.visibilityState === 'visible' ? Date.now() : 0;
  let engaged = 0;
  const flushEngagement = () => {
    if (visibleSince) {
      engaged += Date.now() - visibleSince;
      visibleSince = 0;
    }
    if (engaged >= 1000) {
      send({ n: 'engagement', e: engaged }, true);
    }
    engaged = 0;
  };

  d.addEventListener('visibilitychange', () => {
    if (d.visibilityState === 'hidden') flushEngagement();
    else visibleSince = Date.now();
  });
  w.addEventListener('pagehide', flushEngagement);

  let lastUrl = '';
  const pageview = () => {
    const url = hashMode ? loc.href : loc.href.split('#')[0];
    if (url === lastUrl) return;
    if (lastUrl) {
      flushEngagement();
      if (d.visibilityState === 'visible') visibleSince = Date.now();
    }
    lastUrl = url;
    send({ n: 'pageview' });
  };

  const track = (name: string, props?: Props, revenue?: Revenue) => {
    const payload: Payload = { n: name, p: props };
    if (revenue?.revenue !== undefined) {
      payload.$ = revenue.revenue;
      payload.cur = revenue.currency;
    }
    send(payload);
  };

  // Single-page apps: count route changes as pageviews.
  const history = w.history;
  for (const method of ['pushState', 'replaceState'] as const) {
    const original = history[method];
    history[method] = function (this: History, ...args: Parameters<History['pushState']>) {
      original.apply(this, args);
      pageview();
    };
  }
  w.addEventListener('popstate', pageview);
  if (hashMode) w.addEventListener('hashchange', pageview);

  // Outbound links and file downloads.
  if (autoLinks) {
    const fileTypes = /\.(pdf|zip|dmg|exe|msi|pkg|csv|xlsx?|docx?|pptx?|mp3|mp4|mov|rar|7z|gz|apk|ipa|epub)$/i;
    d.addEventListener(
      'click',
      (event) => {
        const link = (event.target as Element | null)?.closest?.('a');
        if (!link || !link.href) return;
        const custom = link.getAttribute('data-ryvr-event');
        if (custom) {
          track(custom);
          return;
        }
        const url = new URL(link.href, loc.href);
        if (!/^https?:$/.test(url.protocol)) return;
        if (fileTypes.test(url.pathname)) track('File download', { url: url.href });
        else if (url.hostname !== loc.hostname) track('Outbound link', { url: url.href });
      },
      { capture: true },
    );
  }

  const api: Ryvr = {
    track,
    pageview,
    consent: (granted: boolean) => {
      consent = granted ? 'granted' : 'denied';
      if (!granted) {
        pendingWhileConsent.length = 0;
        return;
      }
      pendingWhileConsent.splice(0).forEach((payload) => send(payload));
    },
  };

  // Replay calls queued before the script loaded: window.ryvr = window.ryvr || function(){(ryvr.q=ryvr.q||[]).push(arguments)}
  const queued = (w.ryvr as { q?: unknown[][] } | undefined)?.q ?? [];
  w.ryvr = api;
  for (const [method, ...args] of queued) {
    const fn = api[method as keyof Ryvr];
    if (typeof fn === 'function') (fn as (...a: unknown[]) => void)(...args);
  }

  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', pageview);
  else pageview();
})(window, document);

export {};

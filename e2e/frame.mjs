#!/usr/bin/env node
// From the actuallyreadme README playbook (MIT). Used by `npm run screenshots`.
// Capture pages (or frame existing PNGs) as browser-window screenshots on a soft glow.
//
//   npm i -D playwright && npx playwright install chromium
//   node frame.mjs shots.json
//
// shots.json:
// {
//   "base": "http://127.0.0.1:8000",          // optional, prefixed to each "path"
//   "out": "docs/assets/readme",              // output folder
//   "login": "/demo-login",                   // optional, visited once per theme first
//   "displayHost": "your-app.com",            // shown in the URL pill
//   "theme": { "storageKey": "theme" },       // optional: localStorage key the app reads ("light"/"dark");
//                                             // without it, prefers-color-scheme is emulated
//   "glow": { "light": ["#d9501f", "#eab308"], "dark": ["#d9501f", "#92400e"] },  // optional brand colours
//   "modes": ["light", "dark"],               // optional
//   "shots": [
//     { "name": "dashboard", "path": "/admin" },
//     { "name": "table", "path": "/admin/orders", "click": ["tbody input[type=checkbox] >> nth=1"] },
//     { "name": "search", "path": "/admin", "fill": { "input[type=search]": "acme" }, "wait": 800 },
//     { "name": "legacy", "png": "existing-light.png", "mode": "light", "url": "your-app.com/x" }
//   ]
// }
//
// Writes {out}/{name}-{mode}.png.

import { readFileSync, mkdirSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(join(process.cwd(), 'noop.js'));
const { chromium } = require('playwright');

const configPath = process.argv[2];
if (!configPath) {
    console.error('usage: node frame.mjs shots.json');
    process.exit(1);
}
const config = JSON.parse(readFileSync(configPath, 'utf8'));
const out = resolve(config.out ?? '.');
mkdirSync(out, { recursive: true });

function hexToRgba(hex, alpha) {
    const h = hex.replace('#', '');
    const n = parseInt(
        h.length === 3
            ? h
                  .split('')
                  .map((c) => c + c)
                  .join('')
            : h,
        16,
    );
    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${alpha})`;
}

const glow = {
    light: config.glow?.light ?? ['#6366f1', '#ec4899'],
    dark: config.glow?.dark ?? ['#6366f1', '#ec4899'],
};

const FRAME = {
    light: {
        page: '#f6f5f3',
        glow: [
            hexToRgba(glow.light[0], 0.22),
            hexToRgba(glow.light[1], 0.14),
            hexToRgba(glow.light[0], 0.12),
            hexToRgba(glow.light[1], 0.12),
        ],
        chrome: '#ffffff',
        border: 'rgba(15,23,42,0.10)',
        dot: '#e5e3df',
        pill: '#f2f1ee',
        pillText: '#71717a',
        shadow: '0 30px 70px -20px rgba(15,23,42,0.28), 0 12px 24px -12px rgba(15,23,42,0.14)',
    },
    dark: {
        page: '#09090b',
        glow: [
            hexToRgba(glow.dark[0], 0.28),
            hexToRgba(glow.dark[1], 0.12),
            hexToRgba(glow.dark[0], 0.16),
            hexToRgba(glow.dark[1], 0.2),
        ],
        chrome: '#141416',
        border: 'rgba(255,255,255,0.09)',
        dot: '#2f2f35',
        pill: '#1c1c20',
        pillText: '#8b8b94',
        shadow: '0 30px 80px -20px rgba(0,0,0,0.75), 0 0 0 1px rgba(255,255,255,0.04)',
    },
};

function frameHtml(png, mode, url) {
    const c = FRAME[mode];
    const [g1, g2, g3, g4] = c.glow;
    return `<!doctype html><html><head><style>
        html, body { margin: 0; }
        body { font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; }
        #stage { display: inline-block; padding: 72px 88px 88px; background:
            radial-gradient(60% 55% at 12% 8%, ${g1}, transparent 70%),
            radial-gradient(50% 50% at 38% 100%, ${g2}, transparent 70%),
            radial-gradient(55% 55% at 88% 12%, ${g3}, transparent 70%),
            radial-gradient(50% 60% at 100% 95%, ${g4}, transparent 70%), ${c.page}; }
        .window { width: 1440px; border-radius: 14px; overflow: hidden; background: ${c.chrome};
            border: 1px solid ${c.border}; box-shadow: ${c.shadow}; }
        .bar { height: 44px; display: flex; align-items: center; gap: 8px; padding: 0 16px; border-bottom: 1px solid ${c.border}; }
        .dot { width: 12px; height: 12px; border-radius: 50%; background: ${c.dot}; }
        .pill { margin: 0 auto; transform: translateX(-30px); height: 26px; min-width: 380px; padding: 0 14px; border-radius: 7px;
            background: ${c.pill}; color: ${c.pillText}; font-size: 13px; display: flex; align-items: center; justify-content: center; }
        img { display: block; width: 1440px; }
    </style></head><body><div id="stage"><div class="window">
        <div class="bar"><span class="dot"></span><span class="dot"></span><span class="dot"></span><span class="pill">${url}</span></div>
        <img src="data:image/png;base64,${png.toString('base64')}">
    </div></div></body></html>`;
}

const options = { animations: 'disabled', caret: 'hide' };
const browser = await chromium.launch();

async function writeFramed(context, png, mode, url, name) {
    const page = await context.newPage();
    await page.setViewportSize({ width: 1800, height: 1200 });
    await page.setContent(frameHtml(png, mode, url));
    await page.locator('#stage img').evaluate((img) => img.decode());
    const file = join(out, `${name}-${mode}.png`);
    await page.locator('#stage').screenshot({ path: file, ...options });
    await page.close();
    console.log(file);
}

for (const mode of config.modes ?? ['light', 'dark']) {
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
        deviceScaleFactor: 2,
        colorScheme: mode,
    });
    if (config.theme?.storageKey) {
        await context.addInitScript(
            ([k, m]) => localStorage.setItem(k, m),
            [config.theme.storageKey, mode],
        );
    }
    const page = await context.newPage();
    if (config.login && config.base)
        await page.goto(config.base + config.login);

    for (const shot of config.shots) {
        if (shot.png) {
            if ((shot.mode ?? mode) !== mode) continue;
            const png = readFileSync(resolve(dirname(configPath), shot.png));
            await writeFramed(
                context,
                png,
                mode,
                shot.url ?? config.displayHost ?? '',
                shot.name,
            );
            continue;
        }
        await page.goto((config.base ?? '') + shot.path);
        await page.waitForLoadState('networkidle');
        for (const selector of shot.click ?? [])
            await page.locator(selector).first().click();
        for (const [selector, value] of Object.entries(shot.fill ?? {}))
            await page.locator(selector).first().fill(value);
        if (shot.wait) await page.waitForTimeout(shot.wait);
        await page.evaluate(() => document.fonts.ready);
        const png = await page.screenshot(options);
        await writeFramed(
            context,
            png,
            mode,
            `${config.displayHost ?? 'your-app.com'}${shot.path}`,
            shot.name,
        );
    }
    await context.close();
}

await browser.close();

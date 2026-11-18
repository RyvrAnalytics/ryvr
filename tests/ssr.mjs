import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { setTimeout as delay } from 'node:timers/promises';

const url = 'http://127.0.0.1:13738';
const external = process.argv.includes('--external');
let server;
let diagnostics = '';

async function healthy() {
    try {
        return (
            await fetch(`${url}/health`, { signal: AbortSignal.timeout(500) })
        ).ok;
    } catch {
        return false;
    }
}

function page(component, props = {}) {
    return {
        component,
        url: '/sites/00000000-0000-7000-8000-000000000001',
        version: 'ssr-test',
        clearHistory: false,
        encryptHistory: false,
        props: {
            name: 'RYVR',
            auth: { user: null },
            organization: null,
            organizations: [],
            rail: [],
            errors: {},
            once: {},
            ...props,
        },
    };
}

function sitePage(name) {
    const summary = {
        visitors: 2,
        visits: 2,
        pageviews: 2,
        events: 0,
        bounce_rate: 50,
        avg_duration: 42,
        views_per_visit: 1,
    };
    const previous = { ...summary, visitors: 0, visits: 0, pageviews: 0 };
    const series = Array.from({ length: 24 }, (_, hour) => ({
        bucket: `2026-11-17 ${String(hour).padStart(2, '0')}:00`,
        visitors: hour === 3 ? 2 : 0,
        visits: hour === 3 ? 2 : 0,
        pageviews: hour === 3 ? 2 : 0,
        previous: {
            bucket: `2026-11-16 ${String(hour).padStart(2, '0')}:00`,
            visitors: 0,
            visits: 0,
            pageviews: 0,
        },
    }));
    return page('sites/Show', {
        auth: {
            user: {
                id: 1,
                name: `${name} Owner`,
                email: 'owner@example.test',
                email_verified_at: '2026-11-01',
            },
        },
        site: {
            id: '00000000-0000-7000-8000-000000000001',
            name,
            domain: 'example.test',
            timezone: 'America/Toronto',
            canManage: false,
            canExport: false,
            canManageGoals: false,
        },
        query: {
            period: 'today',
            from: '2026-11-17',
            to: '2026-11-17',
            timezone: 'America/Toronto',
            interval: 'hour',
            filters: {},
        },
        summary,
        previous,
        series,
        channels: [
            {
                name: 'Direct',
                visitors: series.map((point) => point.visitors),
                visits: series.map((point) => point.visits),
                pageviews: series.map((point) => point.pageviews),
            },
        ],
        panels: {
            pages: [],
            sources: [
                {
                    value: 'icons-smoke.example',
                    visitors: 2,
                    visits: 2,
                    pageviews: 2,
                },
            ],
            audience: [],
        },
        panelTabs: {
            pages: ['page', 'entry_page', 'exit_page'],
            sources: ['referrer', 'channel', 'utm_source', 'utm_campaign'],
            audience: ['country', 'city', 'device', 'browser', 'os'],
        },
        events: [],
        goals: [],
        drops: {},
        currents: [],
        live: 0,
        setup: null,
        flows: { nodes: { sources: [], pages: [] }, links: [] },
    });
}

async function render(input) {
    const response = await fetch(`${url}/render`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(input),
        signal: AbortSignal.timeout(10_000),
    });
    assert.equal(
        response.status,
        200,
        `${input.component}: renderer failed. ${diagnostics}`,
    );
    const result = await response.json();
    assert.ok(
        result.body.includes('data-server-rendered="true"'),
        `${input.component}: missing rendered HTML.`,
    );
    return result.body;
}

try {
    if (!external) {
        assert.equal(
            await healthy(),
            false,
            'Port 13738 is already in use. Pass --external to test the existing renderer.',
        );
        server = spawn(process.execPath, ['bootstrap/ssr/app.js'], {
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        for (const stream of [server.stdout, server.stderr])
            stream.on('data', (chunk) => {
                diagnostics = (diagnostics + chunk).slice(-4000);
            });
    }
    for (let attempt = 0; attempt < 100 && !(await healthy()); attempt++)
        await delay(50);
    assert.ok(
        await healthy(),
        `SSR server did not become healthy. ${diagnostics}`,
    );

    const login = await render(page('auth/Login', { canResetPassword: true }));
    assert.ok(
        login.includes('type="email"') && login.includes('type="password"'),
        'Login must render its form without JavaScript.',
    );

    const dashboard = await render(sitePage('Smoke Alpha'));
    assert.ok(
        dashboard.includes('<svg') &&
            dashboard.includes('Visitors by channel over 24 periods'),
        'The dashboard must render its actual chart and hourly buckets.',
    );
    assert.ok(
        dashboard.includes('icons-smoke.example') &&
            !dashboard.includes('src="/icons/'),
        'Initial HTML must use favicon fallbacks until handlers are hydrated.',
    );

    const security = await render(
        page('settings/Security', {
            auth: {
                user: {
                    id: 1,
                    name: 'Smoke Owner',
                    email: 'owner@example.test',
                },
            },
            passwordRules: 'min:12',
            canManagePasskeys: true,
            passkeys: [],
            canManageTwoFactor: true,
            requiresConfirmation: true,
            twoFactorEnabled: false,
        }),
    );
    assert.ok(
        security.includes('Update password') && security.includes('Passkeys'),
        'Security settings must render without browser globals.',
    );

    const names = ['Smoke Alpha', 'Smoke Beta', 'Smoke Gamma'];
    const renders = await Promise.all(
        names.map((name) => render(sitePage(name))),
    );
    renders.forEach((body, index) => {
        assert.ok(body.includes(names[index]), 'Missing current site.');
        names
            .filter((_, other) => other !== index)
            .forEach((name) =>
                assert.ok(
                    !body.includes(name),
                    'SSR mixed data between requests.',
                ),
            );
    });
    console.log(
        'SSR: login form, active hourly chart, security settings, and concurrent site isolation pass.',
    );
} finally {
    if (server) {
        const exited = once(server, 'exit');
        server.kill();
        await Promise.race([exited, delay(2000)]);
    }
}

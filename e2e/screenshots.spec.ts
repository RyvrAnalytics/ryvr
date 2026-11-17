import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// Raw captures; e2e/frame.mjs turns them into the framed images in .github/screenshots.
const out = fileURLToPath(new URL('../screenshots/raw/', import.meta.url));
mkdirSync(out, { recursive: true });

// Seeded by database/seeders/ScreenshotSeeder.php.
const EMAIL = 'alex@northwind.test';
const PASSWORD = 'password';

async function capture(page: Page, name: string, mode: string) {
    // Keep the pointer off the content so nothing shows a hover state.
    await page.mouse.move(1439, 899);
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(600);
    await page.screenshot({
        path: `${out}${name}-${mode}.png`,
        animations: 'disabled',
        caret: 'hide',
    });
}

for (const mode of ['light', 'dark'] as const) {
    test(`screenshots (${mode})`, async ({ page, context, baseURL }) => {
        await page.emulateMedia({ colorScheme: mode });
        // Set the stored appearance the way the settings page does, before the app mounts.
        await context.addCookies([
            { name: 'appearance', value: mode, url: baseURL! },
        ]);
        await context.addInitScript(
            (m) => localStorage.setItem('appearance', m),
            mode,
        );

        await page.goto('/login');
        await page.locator('input[name=email]').fill(EMAIL);
        await page.locator('input[name=password]').fill(PASSWORD);
        await page.locator('button[type=submit]').click();
        await page.waitForURL('**/sites');

        const html = page.locator('html');
        if (mode === 'dark') await expect(html).toHaveClass(/dark/);
        else await expect(html).not.toHaveClass(/dark/);

        // Portfolio: every site side by side.
        await page.waitForLoadState('networkidle');
        await expect(page.getByText('What changed')).toBeVisible();
        await capture(page, 'portfolio', mode);

        // Site dashboard.
        const site = await page
            .locator('a[href*="/sites/"]')
            .filter({ hasText: /Northwind(?! Docs)/ })
            .first()
            .getAttribute('href')
            .then((href) => new URL(href!, baseURL).pathname);
        await page.goto(site, { waitUntil: 'networkidle' });
        await expect(page.getByText('Where each channel lands')).toBeVisible();
        await capture(page, 'site', mode);

        // Realtime.
        await page.goto(`${site}/now`, { waitUntil: 'networkidle' });
        await expect(
            page.getByRole('heading', { name: 'Right now' }),
        ).toBeVisible();
        await capture(page, 'now', mode);

        // Site settings, scrolled to the exclusion rules.
        await page.goto(`${site}/settings`, { waitUntil: 'networkidle' });
        const exclusions = page.getByRole('heading', { name: 'Exclusions' });
        await exclusions.evaluate((el) =>
            window.scrollBy(0, el.getBoundingClientRect().top - 32),
        );
        await capture(page, 'exclusions', mode);

        // API keys and the MCP endpoint.
        await page.goto('/organization/api-keys', { waitUntil: 'networkidle' });
        await expect(page.getByText('Weekly report script')).toBeVisible();
        await capture(page, 'api', mode);
    });
}

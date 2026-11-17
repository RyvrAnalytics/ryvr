import { defineConfig } from '@playwright/test';

// Captures the README screenshots. See CONTRIBUTING.md.
export default defineConfig({
    testDir: '.',
    testMatch: 'screenshots.spec.ts',
    fullyParallel: false,
    workers: 1,
    timeout: 120_000,
    reporter: 'list',
    use: {
        baseURL: process.env.APP_URL ?? 'http://127.0.0.1:8000',
        viewport: { width: 1440, height: 900 },
        deviceScaleFactor: 2,
    },
});

# Contributing

RYVR is in beta, so expect the code to move quickly. Small, focused pull requests are easiest to review.

## Develop

```bash
docker compose -f compose.dev.yaml up -d   # PostgreSQL, ClickHouse, Redis
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan ryvr:migrate
php artisan db:seed --class=DevSeeder && php artisan ryvr:demo
composer dev
```

`DevSeeder` creates `dev@ryvr.test` with the password `password`. `ryvr:demo` writes sample traffic to every site; pass `--seed=1` for the same traffic every time and `--live=20` for visitors on the site right now.

Run `php artisan test` for the test suite and `php artisan ryvr:smoke` for an end-to-end ingest check against real services. `composer ci:check` runs everything CI runs: formatting, lint, type checks and tests.

## Screenshots

The README images in `.github/screenshots/` are captured from the running app, never mocked up. To regenerate them:

```bash
php artisan db:seed --class=ScreenshotSeeder   # Northwind Studio demo, sign in as alex@northwind.test / password
npx playwright install chromium
APP_URL=http://127.0.0.1:8000 npm run screenshots
```

Run the capture soon after seeding so the realtime screens still have visitors. `e2e/screenshots.spec.ts` captures each screen at 1440x900 in light and dark into `screenshots/raw/`, and `e2e/frame.mjs` frames them into `.github/screenshots/`. The [screenshots workflow](.github/workflows/screenshots.yml) does the same on every `v*` tag (or when run by hand) and commits the result.

## Rules

- Never name other analytics products, in code, docs or commits.
- No em dashes in user-facing copy.
- The tracker in `packages/tracker` has a size budget; `npm run build:tracker` checks it.

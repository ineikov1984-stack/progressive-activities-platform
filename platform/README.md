# Progressive Activities Platform

## Local development

Requirements: Docker Desktop and pnpm 11.

```bash
docker compose up --build
docker compose exec api php artisan migrate --database=pgsql_admin --force
docker compose exec api php artisan db:seed --class='Database\Seeders\LocalDemoSeeder' --force
```

- Web: http://localhost:3000
- API: http://localhost:8080/api/v1/health
- Mailpit: http://localhost:8025 — имейлите за записванията идват тук до минута; изпраща ги контейнерът `worker` (`docker compose logs -f worker`)
- Демо вход: http://localhost:3000/login с локалните `DEMO_USER_EMAIL` и `DEMO_USER_PASSWORD` от `compose.yaml`
- Администрация на дейностите: http://localhost:3000/dashboard/activities
- Публичен демо каталог: http://localhost:3000/o/progressive-academy-demo
- Публична страница „Шахмат“: http://localhost:3000/o/progressive-academy-demo/activities/shahmat

The Docker web service runs `next dev --turbopack` directly. In a local comparison using the same image and two fresh containers per bundler, first HTML responses for Enrollments took 1.3–4.2 s with Turbopack versus 5.7–12.6 s with Webpack; Schedule took 1.1–1.4 s versus 4.7–9.2 s. These are local development measurements, not production page-load guarantees. The host `pnpm dev` command still uses Webpack; production builds and CI browser tests keep their existing commands. After source changes, rebuild with `docker compose up --build -d` (the Docker service has no host source mount).

All local data is synthetic. Never import production or Fitsys exports.

## Script security policy

Page responses receive a fresh 128-bit script nonce. Next.js applies it to the framework and inline bootstrap scripts; inline scripts without it are blocked. HTML is rendered per request and sent with `private, no-store` rather than shared page caching. The API proxy and static assets retain their existing handling. Development keeps the eval/WebSocket permissions needed by Next.js; production does not allow eval. Inline style permissions are unchanged.

## Browser verification

Run browser tests on a supported macOS, Windows or Linux host with Node.js 22+ and pnpm 11. The Alpine development web image does not contain a supported Playwright browser; `docker compose run web pnpm test:e2e` is not a browser-test environment.

From `platform/apps/web`, install the version-matched Chromium browser once, build, and run the isolated Visit Offers/Open Days suites:

```bash
pnpm install --frozen-lockfile
pnpm exec playwright install chromium
pnpm build
pnpm exec playwright test --config=playwright.isolated.config.ts
```

On Linux, Playwright may also require its documented system libraries (`pnpm exec playwright install --with-deps chromium`). An already-installed Google Chrome can be used explicitly with `PLAYWRIGHT_BROWSER_CHANNEL=chrome pnpm exec playwright test --config=playwright.isolated.config.ts`. The default is Playwright's version-matched Chromium, so a personal Chrome installation is not required.

The isolated configuration starts its own Next server on `127.0.0.1:3108`; the public suite also starts synthetic API/Next fixtures on ports `18089` and `3107`. Keep these three ports free. It never needs the demo database, intercepts administration API traffic, and shuts its servers down after the run. For a focused run append `open-day-admin.spec.ts` or another test filename directly (no extra `--`). Screenshots and failures are retained in `apps/web/test-results/`.

For the complete browser suite, start and seed the Docker stack above, then run `pnpm exec playwright test` from `apps/web` on the host. That configuration uses the running app on port 3000; its demo-login tests require the local synthetic seed.

## Visit allocation and lifecycle rules

A child can have only one live request or allocation for an organization's session, regardless of request key, offer, or approval mode. Review rechecks the rule under the same child/group locks, including regular enrollments that overlap a visit. Eligibility overrides cannot authorize duplicate seats. A withdrawn or rejected pending request, or a confirmed visit cancelled before its accepted cancellation deadline, allows a fresh request with a new key. An old key continues to replay its original record. Late cancellations, attendance and no-shows retain the historical allocation and accepted snapshots.

Public availability is observational inside the tenant transaction. Submission and review explicitly request allocation locks. Group availability for a new enrollment considers sessions starting after the effective instant, without resetting it to local midnight.

Published offers retain historical session attachments during content edits. New attachments must be future scheduled sessions; confirmed participation still protects membership and accepted terms. Closing/cancelling a campaign hides its public wrapper but leaves its offers independently available. Closing/cancelling an offer stops new requests. Neither action deletes existing applications/enrollments or executes refunds; cancelling a campaign also locks its membership against moves through another campaign.

import { defineConfig } from '@playwright/test';

// Full stack: production build of the web app in front of a real API and database.
// Started by the "e2e-stack" CI job after the API is migrated, seeded and running.
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: ['stack-smoke.spec.ts'],
  workers: 1,
  retries: 0,
  use: {
    baseURL: 'http://127.0.0.1:3000',
    trace: 'retain-on-failure',
    channel: process.env.PLAYWRIGHT_BROWSER_CHANNEL || undefined,
  },
  webServer: {
    command: 'node node_modules/next/dist/bin/next start --hostname 127.0.0.1 --port 3000',
    url: 'http://127.0.0.1:3000/login',
    reuseExistingServer: false,
    timeout: 120_000,
  },
});

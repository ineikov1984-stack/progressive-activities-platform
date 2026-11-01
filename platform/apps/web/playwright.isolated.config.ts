import { defineConfig } from '@playwright/test';

// These suites use synthetic API boundaries and never need the demo database.
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: ['team-workspace.spec.ts', 'activity-workspace.spec.ts', 'attendance-modal.spec.ts', 'attendance.spec.ts', 'attendance-office.spec.ts', 'csp-nonce.spec.ts', 'responsive-ui.spec.ts', 'visit-offer-admin*.spec.ts', 'open-day-admin.spec.ts', 'public-visit-offers.spec.ts', 'payment-terms-settings.spec.ts'],
  workers: 1,
  use: { baseURL: 'http://127.0.0.1:3108', channel: process.env.PLAYWRIGHT_BROWSER_CHANNEL || undefined },
  webServer: {
    command: `node node_modules/next/dist/bin/next ${process.env.PLAYWRIGHT_ISOLATED_DEV === '1' ? 'dev --webpack' : 'start'} --hostname 127.0.0.1 --port 3108`,
    url: 'http://127.0.0.1:3108',
    reuseExistingServer: false,
    timeout: 120_000,
  },
});

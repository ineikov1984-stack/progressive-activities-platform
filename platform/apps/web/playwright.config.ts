import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  use: { baseURL: 'http://127.0.0.1:3000', channel: 'chrome' },
  webServer: {
    command: 'pnpm build && pnpm start',
    url: 'http://127.0.0.1:3000',
    reuseExistingServer:
      process.env.PLAYWRIGHT_REUSE_EXISTING_SERVER === '1' || !process.env.CI,
  },
});

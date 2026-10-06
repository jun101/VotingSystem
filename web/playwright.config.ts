import { defineConfig, devices } from '@playwright/test';

/*
 * Browser acceptance tests. Part of the acceptance harness: not edited when a slice is
 * coded.
 *
 * They run against the whole stack through the proxy, as a visitor would reach it.
 * Start the stack first (make up), then: make e2e
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.BASE_URL ?? 'http://localhost:8080',
    locale: 'fr-FR',
    timezoneId: 'America/Port-au-Prince',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'desktop',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } },
    },
    {
      // The smallest width the voting flow must support (NFR-UX-02).
      name: 'phone',
      use: { ...devices['Pixel 5'], viewport: { width: 320, height: 640 } },
    },
  ],
});

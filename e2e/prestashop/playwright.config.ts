import { defineConfig, devices } from '@playwright/test';
import { config } from './helpers/env';

/**
 * Playwright config pro e2e testy modulu Packeta (PrestaShop).
 * Plán: ../../.notes/plans/e2e-playwright-poc.md
 *
 * POZOR: dev env volá PRODUKČNÍ Packeta API — každý submit = reálná zásilka.
 * Proto workers=1 a retries=0: žádný paralelní běh ani retry, který by
 * vytvořil další reálné zásilky.
 */
export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: config.baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    // Zpomalení akcí pro sledování naživo (defaultně 0 = vypnuto):
    //   SLOWMO=1000 make e2e ARGS="packet-submit-flow --headed"
    launchOptions: {
      slowMo: Number(process.env.SLOWMO || 0),
    },
  },
  projects: [
    {
      name: 'setup',
      testMatch: /.*\.setup\.ts/,
    },
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1920, height: 1080 },
        storageState: '.auth/admin.json',
      },
      dependencies: ['setup'],
    },
  ],
});

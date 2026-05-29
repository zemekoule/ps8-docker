import { test as setup, expect } from '@playwright/test';
import path from 'path';
import { config } from '../helpers/env';

// Přihlášený stav uložíme jednou a sdílíme napříč testy (řeší i CSRF token).
export const authFile = path.resolve(__dirname, '../.auth/admin.json');

setup('admin login', async ({ page }) => {
  await page.goto(`/${config.adminFolder}/`);

  await page.fill('#email', config.adminEmail);
  await page.fill('#passwd', config.adminPassword);
  await page.click('button[name="submitLogin"]');

  // Po loginu PS přesměruje na dashboard — to je důkaz úspěšného přihlášení.
  await expect(page).toHaveURL(/controller=AdminDashboard/, { timeout: 30_000 });

  await page.context().storageState({ path: authFile });
});

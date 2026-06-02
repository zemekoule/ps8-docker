import { test, expect } from '@playwright/test';
import { config } from '../helpers/env';
import { getPacketeryOrder, getLatestLog } from '../helpers/db';

/**
 * Záchranný cleanup: zruší zásilku, která zůstala podaná na fixture objednávce
 * (např. když hlavní test spadl mezi submit a cancel). Spouštět ručně:
 *   npx playwright test cleanup --project=chromium
 */
const orderId = config.fixtureOrderId;

test('zrušit zbylou podanou zásilku na fixture', async ({ page }) => {
  const row = await getPacketeryOrder(orderId);
  test.skip(!row?.tracking_number, 'Fixture nemá podanou zásilku — nic k úklidu.');

  page.on('dialog', (dialog) => dialog.accept());
  await page.goto(`/${config.adminFolder}/index.php/sell/orders/${orderId}/view`);
  const tokenConfirm = page.locator(`a[href*="/sell/orders/${orderId}/view?_token="]`);
  if (await tokenConfirm.count()) {
    await tokenConfirm.first().click();
  }
  await page.locator('#process_cancel_packet').click();

  await expect
    .poll(async () => (await getPacketeryOrder(orderId))?.tracking_number, { timeout: 20_000 })
    .toBeNull();
  expect((await getLatestLog(orderId, 'packet-cancelling'))?.status).toBe('success');
});

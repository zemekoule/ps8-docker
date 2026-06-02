import { Browser, Page, expect } from '@playwright/test';
import { config, authFile } from './env';
import { getPacketeryOrder } from './db';

/**
 * Otevře detail objednávky v adminu a ošetří PS CSRF guard ("Neplatný token")
 * u přímého deep-linku — potvrdí přes odkaz s platným _token (href, ne text,
 * kvůli lokalizaci).
 */
export async function gotoOrderView(page: Page, orderId: number): Promise<void> {
  await page.goto(`/${config.adminFolder}/index.php/sell/orders/${orderId}/view`);
  const tokenConfirm = page.locator(`a[href*="/sell/orders/${orderId}/view?_token="]`);
  if (await tokenConfirm.count()) {
    await tokenConfirm.first().click();
  }
}

/**
 * Robustní teardown: pokud na objednávce zůstala podaná (reálná) zásilka,
 * zruší ji přes UI v čerstvém přihlášeném contextu. Volá se i po pádu testu
 * mezi submit a cancel → produkční API zůstane čisté. Viz plán R3 / success criteria.
 */
export async function ensurePacketCancelled(browser: Browser, orderId: number): Promise<void> {
  const row = await getPacketeryOrder(orderId);
  if (!row?.tracking_number) {
    return; // žádná zásilka venku
  }
  const context = await browser.newContext({ storageState: authFile });
  try {
    const page = await context.newPage();
    page.on('dialog', (dialog) => dialog.accept());
    await gotoOrderView(page, orderId);
    await page.locator('#process_cancel_packet').click();
    await expect
      .poll(async () => (await getPacketeryOrder(orderId))?.tracking_number, { timeout: 20_000 })
      .toBeNull();
  } finally {
    await context.close();
  }
}

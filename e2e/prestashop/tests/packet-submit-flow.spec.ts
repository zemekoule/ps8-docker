import { test, expect } from '@playwright/test';
import { config } from '../helpers/env';
import { resetFixture, getPacketeryOrder, getLatestLog } from '../helpers/db';
import { gotoOrderView, ensurePacketCancelled } from '../helpers/flow';

/**
 * E2E: podání a zrušení zásilky (Packeta widget v detailu objednávky).
 *
 * Flow dokumentace (co/proč/edge cases):
 *   modules/prestashop/packetery/.notes/e2e/feature-catalog/packeta-prestashop/packet-submit-flow.md
 *
 * POZOR: volá PRODUKČNÍ Packeta API — Post parcel vytvoří REÁLNOU zásilku,
 * Cancel ji reálně zruší. Test je submit+cancel v jednom běhu; navíc afterEach
 * (ensurePacketCancelled) zruší zásilku i kdyby test spadl mezi submit a cancel.
 * Fixture = objednávka #7 (carrier 18, výdejní místa).
 */

const orderId = config.fixtureOrderId;

test.beforeEach(async () => {
  await resetFixture(orderId);
});

test.afterEach(async ({ browser }) => {
  // 1) zruš reálnou zásilku, pokud zůstala podaná; 2) vyčisti lokální DB stav.
  await ensurePacketCancelled(browser, orderId);
  await resetFixture(orderId);
});

test('podání a zrušení zásilky', async ({ page }) => {
  // JS confirm() na Post parcel i Cancel → bez accept by Playwright dialog dismissnul.
  page.on('dialog', (dialog) => dialog.accept());

  // 1. Detail objednávky
  await gotoOrderView(page, orderId);
  await expect(page.locator('#process_post_parcel')).toBeVisible();

  // 2. Rozměry vyplnit jen u carrierů, co je vyžadují ($carrierRequiresSize).
  //    Fixture #7 (výdejní místa) je nevyžaduje → krok se přeskočí.
  const lengthInput = page.locator('input[name="length"]');
  if (await lengthInput.count()) {
    await lengthInput.fill('20');
    await page.fill('input[name="width"]', '15');
    await page.fill('input[name="height"]', '10');
    await page.locator('button[name="order_update"]').click();
    await expect(page.locator('input[name="length"]')).toHaveValue('20');
  }

  // 3. Post parcel → reálný createPacket
  await page.locator('#process_post_parcel').click();

  // 4. Assert submit — DB tracking_number vyplněn + log packet-sending success
  await expect
    .poll(async () => (await getPacketeryOrder(orderId))?.tracking_number, { timeout: 20_000 })
    .toBeTruthy();
  expect((await getLatestLog(orderId, 'packet-sending'))?.status).toBe('success');

  // 5. Assert DOM — success alert se zobrazí.
  //    `:not(#ajax_confirmation)` vyloučí prázdný PS placeholder se stejnou třídou.
  await expect(page.locator('.alert-success:not(#ajax_confirmation)')).toBeVisible();

  // 6. Cancel packet → reálný cancelPacket (cleanup)
  await page.locator('#process_cancel_packet').click();

  // 7. Assert cancel — DB tracking_number zpět NULL + log packet-cancelling success
  await expect
    .poll(async () => (await getPacketeryOrder(orderId))?.tracking_number, { timeout: 20_000 })
    .toBeNull();
  expect((await getLatestLog(orderId, 'packet-cancelling'))?.status).toBe('success');
});

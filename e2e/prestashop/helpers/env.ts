import path from 'path';
import dotenv from 'dotenv';

// Sdílený zdroj pravdy = root `.env` dev stacku (admin folder, creds, default PS).
// helpers/ -> prestashop/ -> e2e/ -> root
dotenv.config({ path: path.resolve(__dirname, '../../../.env') });

// Verze PS, proti které testujeme. `PS=ps91 make e2e` přepíše; jinak DEFAULT_PS z .env.
const ps = process.env.PS || process.env.DEFAULT_PS || 'ps82';

/**
 * Veškerá konfigurace přes env → přechod host↔Docker je jen jiné env, ne změna kódu
 * (z hostu DB na 127.0.0.1:3308, z Dockeru dev_db:3306). Viz plán R7.
 */
export const config = {
  ps,
  baseURL: process.env.PS_BASE_URL || `http://${ps}.localhost`,
  adminFolder: process.env.PS_FOLDER_ADMIN || 'admin1234',
  adminEmail: process.env.ADMIN_MAIL || 'demo@prestashop.com',
  adminPassword: process.env.ADMIN_PASSWD || 'prestashop_demo',
  // Primární fixture = objednávka #7 (carrier 18, výdejní místa). Viz plán §3.1.
  fixtureOrderId: Number(process.env.FIXTURE_ORDER_ID || 7),
  db: {
    host: process.env.DB_HOST || '127.0.0.1',
    port: Number(process.env.DB_PORT || 3308),
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || 'asdf',
    database: process.env.DB_NAME || `prestashop_${ps}`,
  },
};

export const adminBaseUrl = `${config.baseURL}/${config.adminFolder}`;

// Uložený přihlášený stav (vytváří auth.setup.ts, sdílí playwright.config + teardown).
export const authFile = path.resolve(__dirname, '../.auth/admin.json');

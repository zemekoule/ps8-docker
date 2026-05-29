import mysql from 'mysql2/promise';
import { config } from './env';

/** Jednorázové připojení k DB dev stacku, spustí callback, zavře. */
async function withConnection<T>(fn: (conn: mysql.Connection) => Promise<T>): Promise<T> {
  const conn = await mysql.createConnection(config.db);
  try {
    return await fn(conn);
  } finally {
    await conn.end();
  }
}

export interface PacketeryOrderRow {
  id_order: number;
  id_carrier: number;
  id_branch: number | null;
  tracking_number: string | null;
  consign_password: string | null;
  consign_password_processed: string | null;
  length: number | null;
  width: number | null;
  height: number | null;
}

/** Vrátí řádek ps_packetery_order pro objednávku, nebo null. */
export async function getPacketeryOrder(orderId: number): Promise<PacketeryOrderRow | null> {
  return withConnection(async (conn) => {
    const [rows] = await conn.query<mysql.RowDataPacket[]>(
      'SELECT id_order, id_carrier, id_branch, tracking_number, consign_password, consign_password_processed, length, width, height FROM ps_packetery_order WHERE id_order = ?',
      [orderId],
    );
    return (rows[0] as PacketeryOrderRow) ?? null;
  });
}

/**
 * Vrátí fixture do clean stavu (tracking + consign password NULL).
 * Volá se v setupu i teardownu — fixture jen RESETUJE, nestaví (plán R4).
 */
export async function resetFixture(orderId: number): Promise<void> {
  await withConnection(async (conn) => {
    await conn.execute(
      'UPDATE ps_packetery_order SET tracking_number = NULL, consign_password = NULL, consign_password_processed = NULL WHERE id_order = ?',
      [orderId],
    );
  });
}

export interface PacketeryLogRow {
  id: number;
  order_id: number | null;
  status: string;
  action: string;
  date: string;
}

/** Poslední log záznam dané akce pro objednávku (action ∈ packet-sending|packet-cancelling|packet-info). */
export async function getLatestLog(orderId: number, action: string): Promise<PacketeryLogRow | null> {
  return withConnection(async (conn) => {
    const [rows] = await conn.query<mysql.RowDataPacket[]>(
      'SELECT id, order_id, status, action, date FROM ps_packetery_log WHERE order_id = ? AND action = ? ORDER BY id DESC LIMIT 1',
      [orderId, action],
    );
    return (rows[0] as PacketeryLogRow) ?? null;
  });
}

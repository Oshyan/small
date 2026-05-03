import { TelegramClient } from "telegram";
import { StringSession } from "telegram/sessions/index.js";
import input from "input";
import fs from "node:fs/promises";
import path from "node:path";

export const SESSION_FILE = path.resolve("./.session");

export async function loadSession() {
  try { return await fs.readFile(SESSION_FILE, "utf8"); }
  catch { return ""; }
}

export async function saveSession(value) {
  await fs.writeFile(SESSION_FILE, value, { mode: 0o600 });
}

export function localIso(date = new Date()) {
  const pad = (n, w = 2) => String(n).padStart(w, "0");
  const off = -date.getTimezoneOffset();
  const sign = off >= 0 ? "+" : "-";
  const h = pad(Math.floor(Math.abs(off) / 60));
  const m = pad(Math.abs(off) % 60);
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}${sign}${h}:${m}`;
}

export function fileTimestamp() {
  return localIso().replace(/:/g, "-");
}

export function classify(entity) {
  if (!entity) return null;
  if (entity.className === "Channel") {
    if (entity.megagroup) return "supergroup";
    if (entity.gigagroup) return "broadcast-group";
    return "channel";
  }
  if (entity.className === "Chat") return "group";
  return null;
}

export function chatKey(entity) {
  if (!entity) return null;
  if (entity.className === "Channel") return `channel:${entity.id.toString()}`;
  if (entity.className === "Chat") return `chat:${entity.id.toString()}`;
  return null;
}

export async function fetchAllDialogs(client) {
  const merged = new Map();
  for (const archived of [false, true]) {
    const dialogs = await client.getDialogs({ archived, limit: 1000 });
    for (const d of dialogs) {
      const key = chatKey(d.entity);
      if (!key) continue;
      if (!merged.has(key)) merged.set(key, { dialog: d, archived });
    }
  }
  return merged;
}

export function requireCreds() {
  const apiId = parseInt(process.env.TG_API_ID || "", 10);
  const apiHash = process.env.TG_API_HASH || "";
  if (!apiId || !apiHash) {
    console.error("Missing TG_API_ID / TG_API_HASH. Copy .env.example to .env and fill in your credentials from https://my.telegram.org");
    process.exit(1);
  }
  return { apiId, apiHash };
}

export async function authClient({ apiId, apiHash, floodSleepThreshold = 60 }) {
  const session = new StringSession(await loadSession());
  const client = new TelegramClient(session, apiId, apiHash, {
    connectionRetries: 5,
    floodSleepThreshold,
  });
  await client.start({
    phoneNumber: () => input.text("Phone (with country code, e.g. +14155551234): "),
    password: () => input.text("2FA password (blank if none): "),
    phoneCode: () => input.text("Login code (sent in Telegram): "),
    onError: (e) => console.error(e),
  });
  const sessionStr = client.session.save();
  if (sessionStr) await saveSession(sessionStr);
  return client;
}

export function installExitHandlers() {
  process.on("unhandledRejection", (e) => {
    if (e?.message === "TIMEOUT") return;
    console.error(e);
    process.exit(1);
  });
}

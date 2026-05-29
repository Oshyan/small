// fetch-photos.js — dedicated photo media downloader.
//
// Walks each chat folder's existing JSON message exports, collects message IDs
// where media is "MessageMediaPhoto", re-fetches those specific messages from
// Telegram (file_reference is required and short-lived), downloads each photo,
// and saves under <chat-folder>/media/<msg_id>.jpg.
//
// Skips:
// - Already-downloaded files (idempotent re-runs)
// - Non-photo media (MessageMediaDocument, MessageMediaWebPage, etc.)
// - Service messages
//
// Reports total size per chat at the end. Stops before any import.
//
// Usage:
//   node fetch-photos.js [chat-folder-name ...]
//   node fetch-photos.js                          # all chats in output/messages/
//   node fetch-photos.js --in-scope               # only the Phase 7 in-scope chats
//   node fetch-photos.js --report-only            # no downloads; print what WOULD be fetched
//   node fetch-photos.js --dry-run                # alias for --report-only

import { Api } from "telegram";
import fs from "node:fs/promises";
import { createWriteStream } from "node:fs";
import path from "node:path";
import {
  authClient, fetchAllDialogs, installExitHandlers, requireCreds, localIso,
} from "./lib.js";

// Messages live at this path on Oshyan's Mac (per the EdgeCity demo workflow).
// Override with --messages-dir <path> or TG_MESSAGES_DIR env var.
const DEFAULT_MESSAGES_DIR = "/Users/oshyan/Projects/EdgeCity/Data Export/Telegram/messages";
const messagesDirArgIdx = process.argv.indexOf("--messages-dir");
const MESSAGES_DIR = path.resolve(
  messagesDirArgIdx >= 0 ? process.argv[messagesDirArgIdx + 1] :
  (process.env.TG_MESSAGES_DIR || DEFAULT_MESSAGES_DIR)
);

// In-scope chat folders per Phase 7 v2.1 plan
const IN_SCOPE_CHATS = new Set([
  "edge-esmeralda-2026-3980048315",
  "housing-at-edge-esmeralda-2026-3870362925",
  "edge-esmeralda-2025-2515230099",
  "housing-at-edge-esmeralda-2593162638",
  "edge-city-patagonia-2025-2944565963",
  "residencies-housing-edge-city-patagonia-2025-2835417889",
]);

const args = process.argv.slice(2);
const REPORT_ONLY = args.includes("--report-only") || args.includes("--dry-run");
const IN_SCOPE_FLAG = args.includes("--in-scope");
// Filter out --flags and their values for the chat-folder positional list
const requestedChats = [];
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  if (a === "--messages-dir") { i++; continue; }
  if (a.startsWith("--")) continue;
  requestedChats.push(a);
}

const BATCH_SIZE = 50;       // messages per getMessages call
const POLITE_DELAY_MS = 80;  // between getMessages calls
const PER_PHOTO_DELAY_MS = 30; // between individual downloads

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

function fmtBytes(n) {
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  if (n < 1024 * 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(1)} MB`;
  return `${(n / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}

async function listChatFolders() {
  const entries = await fs.readdir(MESSAGES_DIR);
  const folders = [];
  for (const name of entries) {
    const dir = path.join(MESSAGES_DIR, name);
    try {
      const stat = await fs.stat(dir);
      if (!stat.isDirectory()) continue;
      const meta = path.join(dir, "metadata.json");
      await fs.access(meta);
      folders.push(name);
    } catch {}
  }
  return folders.sort();
}

async function collectPhotoMessageIds(chatDir) {
  // Returns Map<topicId-or-"main", { topicTitle, messageIds:[], existingPaths:[] }>
  // plus a flat list of {msg_id, topic_id, has_existing_file, existing_path}
  const files = await fs.readdir(chatDir);
  const mediaDir = path.join(chatDir, "media");
  await fs.mkdir(mediaDir, { recursive: true });
  const items = [];
  for (const f of files) {
    if (!f.endsWith(".json")) continue;
    if (f === "metadata.json" || f === "senders.json") continue;
    const data = JSON.parse(await fs.readFile(path.join(chatDir, f), "utf8"));
    const messages = data.messages || [];
    for (const m of messages) {
      if (m.is_service) continue;
      if (m.media !== "MessageMediaPhoto") continue;
      const msgId = m.id;
      const out = path.join(mediaDir, `${msgId}.jpg`);
      let exists = false;
      try { await fs.access(out); exists = true; } catch {}
      items.push({
        msg_id: msgId,
        topic_id: data.topic_id || null,
        topic_title: data.topic || null,
        already_exists: exists,
        out_path: out,
      });
    }
  }
  return items;
}

async function fetchPhotosForChat(client, dialogsByChatId, folder) {
  const chatDir = path.join(MESSAGES_DIR, folder);
  const metaPath = path.join(chatDir, "metadata.json");
  const meta = JSON.parse(await fs.readFile(metaPath, "utf8"));

  const items = await collectPhotoMessageIds(chatDir);
  const todo = items.filter(i => !i.already_exists);
  const alreadyHave = items.filter(i => i.already_exists);

  let alreadyHaveBytes = 0;
  for (const i of alreadyHave) {
    try { alreadyHaveBytes += (await fs.stat(i.out_path)).size; } catch {}
  }

  console.log(`\n[${folder}]`);
  console.log(`  chat: ${meta.chat} (chat_id=${meta.chat_id})`);
  console.log(`  total photo messages: ${items.length}`);
  console.log(`  already downloaded:   ${alreadyHave.length} (${fmtBytes(alreadyHaveBytes)})`);
  console.log(`  remaining to fetch:   ${todo.length}`);

  if (REPORT_ONLY) {
    return { folder, planned: items.length, todo: todo.length, downloaded: 0, downloaded_bytes: 0, errors: [] };
  }
  if (todo.length === 0) {
    return { folder, planned: items.length, todo: 0, downloaded: 0, downloaded_bytes: alreadyHaveBytes, errors: [] };
  }

  const entity = dialogsByChatId.get(meta.chat_id);
  if (!entity) {
    console.log(`  ERR: dialog entity not found for chat_id=${meta.chat_id} — skipping`);
    return { folder, planned: items.length, todo: todo.length, downloaded: 0, downloaded_bytes: 0, errors: ["entity_not_found"] };
  }

  let downloaded = 0;
  let downloadedBytes = alreadyHaveBytes;
  const errors = [];

  for (let i = 0; i < todo.length; i += BATCH_SIZE) {
    const batch = todo.slice(i, i + BATCH_SIZE);
    const ids = batch.map(b => b.msg_id);

    let msgs;
    try {
      msgs = await client.getMessages(entity, { ids });
    } catch (e) {
      console.log(`  ERR getMessages batch starting ${ids[0]}: ${e.message}`);
      errors.push({ batch_start: ids[0], reason: `getMessages: ${e.message.slice(0, 120)}` });
      await sleep(POLITE_DELAY_MS);
      continue;
    }

    for (let j = 0; j < batch.length; j++) {
      const item = batch[j];
      const msg = msgs[j];
      if (!msg || !msg.media || msg.media.className !== "MessageMediaPhoto") {
        errors.push({ msg_id: item.msg_id, reason: "media_not_photo_in_refetch" });
        continue;
      }

      try {
        // Download largest available photo size. gramjs picks the biggest by default.
        const buf = await client.downloadMedia(msg);
        if (!buf || buf.length === 0) {
          errors.push({ msg_id: item.msg_id, reason: "empty_buffer" });
          continue;
        }
        await fs.writeFile(item.out_path, buf);
        downloaded++;
        downloadedBytes += buf.length;
      } catch (e) {
        errors.push({ msg_id: item.msg_id, reason: e.message.slice(0, 120) });
      }
      await sleep(PER_PHOTO_DELAY_MS);
    }

    const progressDone = Math.min(i + BATCH_SIZE, todo.length);
    process.stdout.write(`  progress: ${progressDone}/${todo.length} downloaded=${downloaded} errors=${errors.length}\r`);
    await sleep(POLITE_DELAY_MS);
  }
  process.stdout.write("\n");

  return { folder, planned: items.length, todo: todo.length, downloaded, downloaded_bytes: downloadedBytes, errors };
}

async function summarize(results) {
  console.log("\n" + "=".repeat(72));
  console.log(REPORT_ONLY ? "Report (no downloads ran)" : "Download summary");
  console.log("=".repeat(72));
  console.log(`${"chat folder".padEnd(60)} ${"planned".padStart(8)} ${"got".padStart(6)} ${"size".padStart(10)}`);
  let total_planned = 0, total_dl = 0, total_bytes = 0, total_errs = 0;
  for (const r of results) {
    console.log(`${r.folder.slice(0, 60).padEnd(60)} ${String(r.planned).padStart(8)} ${String(r.downloaded).padStart(6)} ${fmtBytes(r.downloaded_bytes).padStart(10)}`);
    total_planned += r.planned;
    total_dl += r.downloaded;
    total_bytes += r.downloaded_bytes;
    total_errs += r.errors.length;
  }
  console.log("-".repeat(72));
  console.log(`${"TOTAL".padEnd(60)} ${String(total_planned).padStart(8)} ${String(total_dl).padStart(6)} ${fmtBytes(total_bytes).padStart(10)}`);
  console.log(`Errors: ${total_errs}`);
  console.log("=".repeat(72));

  // Save report to disk
  const reportPath = path.join(MESSAGES_DIR, `fetch-photos-report-${localIso().replace(/:/g, "-")}.json`);
  await fs.writeFile(reportPath, JSON.stringify({
    ran_at: localIso(),
    mode: REPORT_ONLY ? "report-only" : "download",
    per_chat: results,
    total_planned, total_downloaded: total_dl, total_bytes, total_errors: total_errs,
  }, null, 2));
  console.log(`Report → ${reportPath}`);
}

async function main() {
  // Decide chat-folder set
  const available = await listChatFolders();
  let chatFolders;
  if (requestedChats.length > 0) {
    chatFolders = requestedChats;
    const missing = chatFolders.filter(c => !available.includes(c));
    if (missing.length) {
      console.error(`Unknown chat folders: ${missing.join(", ")}`);
      process.exit(1);
    }
  } else if (IN_SCOPE_FLAG) {
    chatFolders = available.filter(c => IN_SCOPE_CHATS.has(c));
  } else {
    chatFolders = available;
  }
  console.log(`Will process ${chatFolders.length} chat folder(s):`);
  for (const c of chatFolders) console.log(`  - ${c}`);
  if (REPORT_ONLY) console.log("\nMode: REPORT ONLY (no auth, no downloads)");

  if (REPORT_ONLY) {
    // No auth needed for report-only
    const results = [];
    for (const folder of chatFolders) {
      const items = await collectPhotoMessageIds(path.join(MESSAGES_DIR, folder));
      const alreadyHave = items.filter(i => i.already_exists);
      let bytes = 0;
      for (const i of alreadyHave) { try { bytes += (await fs.stat(i.out_path)).size; } catch {} }
      console.log(`\n[${folder}] total=${items.length} already_dl=${alreadyHave.length} ${fmtBytes(bytes)}`);
      results.push({ folder, planned: items.length, todo: items.length - alreadyHave.length, downloaded: alreadyHave.length, downloaded_bytes: bytes, errors: [] });
    }
    await summarize(results);
    process.exit(0);
  }

  const { apiId, apiHash } = requireCreds();
  const client = await authClient({ apiId, apiHash });

  console.log("\nFetching dialogs to resolve chat entities...");
  const merged = await fetchAllDialogs(client);
  // Build chat_id (string of entity.id) → entity map
  const byChatId = new Map();
  for (const [, { dialog }] of merged) {
    const e = dialog.entity;
    if (!e) continue;
    byChatId.set(e.id.toString(), e);
  }
  console.log(`Resolved ${byChatId.size} dialogs.`);

  const results = [];
  for (const folder of chatFolders) {
    try {
      const r = await fetchPhotosForChat(client, byChatId, folder);
      results.push(r);
    } catch (e) {
      console.log(`  fatal error on ${folder}: ${e.message}`);
      results.push({ folder, planned: 0, todo: 0, downloaded: 0, downloaded_bytes: 0, errors: [{ reason: `fatal: ${e.message}` }] });
    }
  }

  await summarize(results);
  await client.destroy();
}

installExitHandlers();
main().then(() => process.exit(0)).catch(e => { console.error(e); process.exit(1); });

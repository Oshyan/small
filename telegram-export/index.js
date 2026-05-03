import { Api } from "telegram";
import fs from "node:fs/promises";
import path from "node:path";
import {
  authClient, classify, fetchAllDialogs, fileTimestamp,
  installExitHandlers, localIso, requireCreds,
} from "./lib.js";

const OUTPUT_DIR = path.resolve("./output");

function peerKey(peer) {
  if (peer.className === "InputPeerChannel") return `channel:${peer.channelId.toString()}`;
  if (peer.className === "InputPeerChat") return `chat:${peer.chatId.toString()}`;
  return null;
}

async function fetchTopics(client, entity) {
  const all = [];
  let offsetDate = 0, offsetId = 0, offsetTopic = 0;
  while (true) {
    const res = await client.invoke(new Api.channels.GetForumTopics({
      channel: entity, limit: 100, offsetDate, offsetId, offsetTopic,
    }));
    const titled = res.topics.filter(t => t.title);
    all.push(...titled);
    if (res.topics.length < 100) break;
    const last = res.topics[res.topics.length - 1];
    offsetTopic = last.id;
    offsetId = last.topMessage ?? 0;
    offsetDate = last.date ?? 0;
  }
  return all;
}

async function fetchFolders(client) {
  const result = await client.invoke(new Api.messages.GetDialogFilters({}));
  const list = Array.isArray(result) ? result : (result.filters ?? []);
  const folders = [];
  for (const f of list) {
    if (f.className === "DialogFilterDefault") continue;
    const titleText = typeof f.title === "string" ? f.title : (f.title?.text ?? "(unnamed)");
    const peers = [...(f.pinnedPeers ?? []), ...(f.includePeers ?? [])]
      .map(peerKey).filter(Boolean);
    folders.push({ id: f.id, title: titleText, peerKeys: new Set(peers) });
  }
  return folders;
}

async function main() {
  const { apiId, apiHash } = requireCreds();
  const client = await authClient({ apiId, apiHash });

  console.log("Fetching dialogs...");
  const merged = await fetchAllDialogs(client);

  console.log("Fetching folders...");
  const folders = await fetchFolders(client);
  console.log(`Found ${merged.size} groups/channels and ${folders.length} user folders.`);

  const chats = [];
  let processed = 0;
  for (const [key, { dialog, archived }] of merged) {
    processed++;
    const entity = dialog.entity;
    const kind = classify(entity);
    if (!kind) continue;
    const record = {
      key,
      id: entity.id.toString(),
      title: dialog.title || entity.title || "(untitled)",
      kind,
      archived,
      isForum: !!entity.forum,
      username: entity.username || null,
      topics: [],
    };
    if (entity.forum) {
      process.stdout.write(`  [${processed}/${merged.size}] topics for "${record.title}"... `);
      try {
        const topics = await fetchTopics(client, entity);
        record.topics = topics.map(t => ({
          id: t.id, title: t.title,
          closed: !!t.closed, hidden: !!t.hidden,
        }));
        console.log(`${record.topics.length} topics`);
      } catch (e) {
        record.topicsError = e.message;
        console.log(`error: ${e.message}`);
      }
    }
    chats.push(record);
  }

  await fs.mkdir(OUTPUT_DIR, { recursive: true });
  const stamp = fileTimestamp();
  const jsonPath = path.join(OUTPUT_DIR, `telegram-export-${stamp}.json`);
  const mdPath = path.join(OUTPUT_DIR, `telegram-export-${stamp}.md`);

  await fs.writeFile(jsonPath, JSON.stringify({
    generatedAt: localIso(),
    folders: folders.map(f => ({ id: f.id, title: f.title, peerKeys: [...f.peerKeys] })),
    chats,
  }, null, 2));

  const renderChat = (c) => {
    const tags = [c.kind, c.archived ? "archived" : null, c.isForum ? "forum" : null].filter(Boolean);
    const handle = c.username ? ` (@${c.username})` : "";
    const lines = [`- **${c.title}** [${tags.join(", ")}]${handle}`];
    for (const t of c.topics) {
      const s = [t.closed ? "closed" : null, t.hidden ? "hidden" : null].filter(Boolean);
      lines.push(`  - ${t.title}${s.length ? ` [${s.join(", ")}]` : ""}`);
    }
    return lines.join("\n");
  };

  const chatsByKey = new Map(chats.map(c => [c.key, c]));
  const sections = [];
  const assigned = new Set();
  for (const f of folders) {
    const inFolder = [...f.peerKeys].map(k => chatsByKey.get(k)).filter(Boolean);
    if (inFolder.length === 0) continue;
    inFolder.forEach(c => assigned.add(c.key));
    sections.push(`## ${f.title}\n\n${inFolder.map(renderChat).join("\n")}`);
  }
  const unassigned = chats.filter(c => !assigned.has(c.key));
  if (unassigned.length) {
    sections.push(`## (No folder)\n\n${unassigned.map(renderChat).join("\n")}`);
  }

  const md = `# Telegram chats — ${localIso()}\n\n${chats.length} chats, ${folders.length} folders.\n\n${sections.join("\n\n")}\n`;
  await fs.writeFile(mdPath, md);

  console.log(`\nWrote ${jsonPath}`);
  console.log(`Wrote ${mdPath}`);
  await client.destroy();
}

installExitHandlers();
main()
  .then(() => process.exit(0))
  .catch(e => { console.error(e); process.exit(1); });

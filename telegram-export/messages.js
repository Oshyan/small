import { Api } from "telegram";
import fs from "node:fs/promises";
import path from "node:path";
import {
  authClient, classify, fetchAllDialogs,
  installExitHandlers, localIso, requireCreds,
} from "./lib.js";

const OUTPUT_DIR = path.resolve("./output/messages");
const FORCE = process.argv.includes("--force");
const FLOOD_SLEEP_THRESHOLD = 300;
const INTER_CHAT_DELAY_MS = 1500;

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

function slugify(s) {
  return (s || "")
    .toLowerCase()
    .normalize("NFKD").replace(/[̀-ͯ]/g, "")
    .replace(/[^\w\s-]/g, "")
    .replace(/\s+/g, "-")
    .replace(/-+/g, "-")
    .replace(/^-|-$/g, "")
    .slice(0, 60) || "untitled";
}

function senderDisplay(message) {
  const s = message.sender;
  if (!s) {
    if (message.fromId?.userId) return `User#${message.fromId.userId}`;
    if (message.fromId?.channelId) return `Channel#${message.fromId.channelId}`;
    return "Unknown";
  }
  if (s.className === "Channel") return s.title || "(channel)";
  if (s.className === "User") {
    const name = [s.firstName, s.lastName].filter(Boolean).join(" ").trim();
    if (name && s.username) return `${name} (@${s.username})`;
    if (name) return name;
    if (s.username) return `@${s.username}`;
    return `User#${s.id}`;
  }
  return s.title || s.username || "Unknown";
}

function topicForMessage(message) {
  const r = message.replyTo;
  if (!r) return 1;
  if (r.forumTopic) return r.replyToTopId || r.replyToMsgId || 1;
  if (r.replyToTopId) return r.replyToTopId;
  return 1;
}

function serviceMessageSummary(message) {
  const a = message.action;
  if (!a) return "[service message]";
  switch (a.className) {
    case "MessageActionChatJoinedByLink":
    case "MessageActionChatJoinedByRequest":
    case "MessageActionChatAddUser":
      return "[joined]";
    case "MessageActionChatDeleteUser": return "[left]";
    case "MessageActionChatEditTitle": return `[renamed chat to "${a.title}"]`;
    case "MessageActionChatEditPhoto": return "[changed chat photo]";
    case "MessageActionChatDeletePhoto": return "[removed chat photo]";
    case "MessageActionPinMessage": return "[pinned a message]";
    case "MessageActionTopicCreate": return `[created topic "${a.title}"]`;
    case "MessageActionTopicEdit":
      if (a.title) return `[renamed topic to "${a.title}"]`;
      if (a.closed !== undefined) return a.closed ? "[closed topic]" : "[reopened topic]";
      if (a.hidden !== undefined) return a.hidden ? "[hid topic]" : "[unhid topic]";
      return "[edited topic]";
    case "MessageActionGroupCall": return "[group call]";
    case "MessageActionGroupCallScheduled": return "[scheduled group call]";
    case "MessageActionContactSignUp": return "[joined Telegram]";
    case "MessageActionChannelCreate": return `[created channel "${a.title}"]`;
    case "MessageActionChannelMigrateFrom": return "[migrated from group]";
    default: return `[${a.className.replace(/^MessageAction/, "")}]`;
  }
}

function messageDate(message) {
  return new Date(message.date * 1000);
}

function isoTimeShort(date) {
  return `${String(date.getHours()).padStart(2, "0")}:${String(date.getMinutes()).padStart(2, "0")}`;
}

function dayKey(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

function mediaSummary(media) {
  if (!media) return null;
  switch (media.className) {
    case "MessageMediaPhoto": return "[Photo]";
    case "MessageMediaDocument": {
      const doc = media.document;
      if (!doc) return "[Document]";
      const attrs = doc.attributes || [];
      for (const a of attrs) {
        if (a.className === "DocumentAttributeVideo") return "[Video]";
        if (a.className === "DocumentAttributeAudio") return a.voice ? "[Voice]" : "[Audio]";
        if (a.className === "DocumentAttributeSticker") return "[Sticker]";
        if (a.className === "DocumentAttributeAnimated") return "[GIF]";
        if (a.className === "DocumentAttributeFilename") return `[File: ${a.fileName}]`;
      }
      return "[Document]";
    }
    case "MessageMediaWebPage": return null;
    case "MessageMediaContact": return "[Contact]";
    case "MessageMediaGeo": return "[Location]";
    case "MessageMediaPoll": return "[Poll]";
    case "MessageMediaVenue": return "[Venue]";
    case "MessageMediaDice": return "[Dice]";
    case "MessageMediaGame": return "[Game]";
    default: return `[${media.className.replace(/^MessageMedia/, "")}]`;
  }
}

function renderMarkdown({ chatTitle, kind, archived, isForum, topic, messages, messageById }) {
  const dates = messages.map(messageDate);
  const senders = new Set();
  for (const m of messages) {
    if (m.className !== "MessageService") {
      const s = senderDisplay(m);
      if (s !== "Unknown") senders.add(s);
    }
  }

  const fm = [
    `chat: ${JSON.stringify(chatTitle)}`,
    `kind: ${kind}`,
    archived ? `archived: true` : null,
    isForum ? `is_forum: true` : null,
    topic ? `topic: ${JSON.stringify(topic.title)}` : null,
    topic ? `topic_id: ${topic.id}` : null,
    `messages: ${messages.length}`,
    dates.length ? `date_range: ${dayKey(dates[0])} → ${dayKey(dates[dates.length - 1])}` : null,
    `unique_senders: ${senders.size}`,
  ].filter(Boolean).join("\n");

  const lines = ["---", fm, "---", ""];
  lines.push(topic ? `# ${chatTitle} — ${topic.title}` : `# ${chatTitle}`, "");

  if (messages.length === 0) {
    lines.push("_No messages._");
    return lines.join("\n");
  }

  let lastDay = null;
  for (const m of messages) {
    const date = messageDate(m);
    const day = dayKey(date);
    if (day !== lastDay) {
      if (lastDay !== null) lines.push("");
      lines.push(`## ${day}`, "");
      lastDay = day;
    }

    const time = isoTimeShort(date);

    if (m.className === "MessageService") {
      lines.push(`[${time}] _${senderDisplay(m)} ${serviceMessageSummary(m).slice(1, -1)}_`, "");
      continue;
    }

    const sender = senderDisplay(m);
    const text = m.message || "";
    const media = mediaSummary(m.media);
    const replyToId = m.replyTo?.replyToMsgId;
    const isTopicHeader = m.replyTo?.forumTopic && replyToId && !m.replyTo?.replyToTopId;

    let header = `[${time}] **${sender}**`;
    if (m.fwdFrom) {
      const orig = m.fwdFrom.fromName || "(forwarded)";
      header += ` _(forwarded from ${orig})_`;
    }
    lines.push(`${header}:`);

    if (replyToId && !isTopicHeader && messageById.has(replyToId)) {
      const parent = messageById.get(replyToId);
      const parentSender = senderDisplay(parent);
      const raw = parent.message || (parent.className === "MessageService" ? serviceMessageSummary(parent) : "") || "";
      const snippet = raw.slice(0, 80).replace(/\n/g, " ");
      const more = raw.length > 80 ? "..." : "";
      lines.push(`> ↳ ${parentSender}: "${snippet}${more}"`);
    }

    if (text) {
      for (const t of text.split("\n")) lines.push(t);
    }
    if (media) lines.push(`_${media.slice(1, -1)}_`);
    if (m.editDate) lines.push(`_(edited)_`);
    lines.push("");
  }

  return lines.join("\n");
}

function jsonifyMessages(messages) {
  return messages.map(m => ({
    id: m.id,
    date: localIso(messageDate(m)),
    sender_id: m.senderId?.toString() || null,
    sender: senderDisplay(m),
    text: m.message || null,
    is_service: m.className === "MessageService",
    service_action: m.className === "MessageService" ? m.action?.className : null,
    service_summary: m.className === "MessageService" ? serviceMessageSummary(m) : null,
    reply_to_msg_id: m.replyTo?.replyToMsgId || null,
    reply_to_top_id: m.replyTo?.replyToTopId || null,
    forum_topic: !!m.replyTo?.forumTopic,
    edit_date: m.editDate ? localIso(new Date(m.editDate * 1000)) : null,
    forward_from: m.fwdFrom ? {
      from_name: m.fwdFrom.fromName || null,
      date: localIso(new Date(m.fwdFrom.date * 1000)),
    } : null,
    media: m.media?.className || null,
    media_summary: mediaSummary(m.media),
    reactions: m.reactions ? (m.reactions.results || []).map(r => ({
      emoji: r.reaction?.emoticon || null,
      custom_emoji_id: r.reaction?.documentId?.toString() || null,
      count: r.count,
    })) : null,
    pinned: !!m.pinned,
  }));
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

async function fetchAllMessages(client, entity, onProgress) {
  const all = [];
  let count = 0;
  for await (const msg of client.iterMessages(entity, { reverse: true })) {
    all.push(msg);
    count++;
    if (count % 200 === 0) onProgress(count, false);
  }
  onProgress(count, true);
  return all;
}

async function processChat(client, dialog, archived) {
  const entity = dialog.entity;
  const chatTitle = dialog.title || entity.title || "Untitled";
  const isForum = !!entity.forum;
  const kind = classify(entity);

  const dirSlug = `${slugify(chatTitle)}-${entity.id.toString()}`;
  const chatDir = path.join(OUTPUT_DIR, dirSlug);
  const metaPath = path.join(chatDir, "metadata.json");

  if (!FORCE) {
    try {
      await fs.access(metaPath);
      console.log(`  skip (exists): ${chatTitle}`);
      return;
    } catch {}
  }

  await fs.mkdir(chatDir, { recursive: true });

  const topicTitleById = new Map();
  topicTitleById.set(1, "General");
  if (isForum) {
    try {
      const topics = await fetchTopics(client, entity);
      for (const t of topics) topicTitleById.set(t.id, t.title);
    } catch (e) {
      console.log(`  warn: topic fetch failed for "${chatTitle}": ${e.message}`);
    }
  }

  process.stdout.write(`  fetching "${chatTitle}"`);
  let messages;
  try {
    messages = await fetchAllMessages(client, entity, (count, done) => {
      if (done) process.stdout.write(` ${count} msgs\n`);
      else process.stdout.write(` ${count}...`);
    });
  } catch (e) {
    process.stdout.write(` ERROR: ${e.message}\n`);
    return;
  }

  const messageById = new Map(messages.map(m => [m.id, m]));
  const dates = messages.map(messageDate);
  const senders = new Set();
  for (const m of messages) {
    if (m.className !== "MessageService") {
      const s = senderDisplay(m);
      if (s !== "Unknown") senders.add(s);
    }
  }
  const metadata = {
    chat: chatTitle,
    chat_id: entity.id.toString(),
    kind,
    archived,
    is_forum: isForum,
    username: entity.username || null,
    message_count: messages.length,
    date_range_start: dates.length ? localIso(dates[0]) : null,
    date_range_end: dates.length ? localIso(dates[dates.length - 1]) : null,
    unique_senders: senders.size,
    topics: isForum ? [...topicTitleById.entries()].map(([id, title]) => ({ id, title })) : null,
    generated_at: localIso(),
  };
  await fs.writeFile(metaPath, JSON.stringify(metadata, null, 2));

  if (isForum) {
    const byTopic = new Map();
    for (const m of messages) {
      const tid = topicForMessage(m);
      if (!byTopic.has(tid)) byTopic.set(tid, []);
      byTopic.get(tid).push(m);
    }
    for (const [tid, topicMessages] of byTopic) {
      const topicTitle = topicTitleById.get(tid) || `Topic ${tid}`;
      const fileBase = `${slugify(topicTitle)}-${tid}`;
      const md = renderMarkdown({
        chatTitle, kind, archived, isForum,
        topic: { id: tid, title: topicTitle },
        messages: topicMessages, messageById,
      });
      await fs.writeFile(path.join(chatDir, `${fileBase}.md`), md);
      await fs.writeFile(path.join(chatDir, `${fileBase}.json`), JSON.stringify({
        chat: chatTitle, chat_id: entity.id.toString(),
        topic: topicTitle, topic_id: tid,
        message_count: topicMessages.length,
        messages: jsonifyMessages(topicMessages),
      }, null, 2));
    }
  } else {
    const md = renderMarkdown({
      chatTitle, kind, archived, isForum, topic: null,
      messages, messageById,
    });
    await fs.writeFile(path.join(chatDir, "chat.md"), md);
    await fs.writeFile(path.join(chatDir, "chat.json"), JSON.stringify({
      chat: chatTitle, chat_id: entity.id.toString(),
      message_count: messages.length,
      messages: jsonifyMessages(messages),
    }, null, 2));
  }
}

async function main() {
  const { apiId, apiHash } = requireCreds();
  const client = await authClient({ apiId, apiHash, floodSleepThreshold: FLOOD_SLEEP_THRESHOLD });

  console.log("Fetching dialogs...");
  const merged = await fetchAllDialogs(client);
  console.log(`Found ${merged.size} groups/channels.`);
  console.log(FORCE
    ? "Forcing re-export of all chats."
    : "Skipping chats with existing metadata.json (use --force to override).");

  await fs.mkdir(OUTPUT_DIR, { recursive: true });

  let i = 0;
  for (const [, { dialog, archived }] of merged) {
    i++;
    console.log(`\n[${i}/${merged.size}]`);
    try {
      await processChat(client, dialog, archived);
    } catch (e) {
      console.error(`  fatal error processing chat: ${e.message}`);
    }
    await sleep(INTER_CHAT_DELAY_MS);
  }

  console.log(`\nDone. Output in ${OUTPUT_DIR}`);
  await client.destroy();
}

installExitHandlers();
main()
  .then(() => process.exit(0))
  .catch(e => { console.error(e); process.exit(1); });

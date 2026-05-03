# telegram-export

Dumps your Telegram groups, channels, folders, forum topics, and full message history to JSON + markdown via gramjs (MTProto user client). Output is structured for LLM ingestion.

## Setup

1. Get API credentials from https://my.telegram.org → API development tools (`api_id` and `api_hash`).
2. `cp .env.example .env` and fill in `TG_API_ID` and `TG_API_HASH`.
3. `npm install`

Requires Node 20.6+ (for `--env-file`).

## Scripts

### `npm start` — chat list dump

Lightweight: every group/channel you're in, organized by folder, with forum topic names nested. Fast.

Output: `output/telegram-export-<timestamp>.{json,md}`

### `npm run messages` — full message history

Walks every group/channel and dumps the full message history. For forum supergroups, each topic becomes its own document. No media files are downloaded — only metadata (`[Photo]`, `[Video]`, `[File: foo.pdf]` etc.) is recorded inline.

Output: `output/messages/<chat-slug>-<chat-id>/`

```
output/messages/
  edge-city-patagonia-2025-1234567890/
    metadata.json          # chat-level summary
    general-1.md           # one file per topic for forum chats
    general-1.json
    housing-42.md
    housing-42.json
    ...
  some-non-forum-group-9876543210/
    metadata.json
    chat.md                # single file for non-forum chats
    chat.json
```

**Resumable.** Skips any chat that already has a `metadata.json`. Pass `--force` to re-export everything:

```
npm run messages -- --force
```

(To re-export a single chat, delete its directory and re-run.)

### Output format (per topic / per chat)

Each `.md` file has YAML front matter, then chronological messages grouped by day, with sender, time, reply context (1-line quoted snippet), and inline media markers:

```markdown
---
chat: "Edge City Patagonia 2025"
kind: supergroup
is_forum: true
topic: "Housing"
topic_id: 42
messages: 1543
date_range: 2024-12-01 → 2025-04-15
unique_senders: 87
---

# Edge City Patagonia 2025 — Housing

## 2024-12-01

[08:32] **Alice (@alice)**:
hey, who has a spare room?

[09:15] **Bob**:
> ↳ Alice (@alice): "hey, who has a spare room?"
I might have one starting Jan 5
```

Each `.json` sidecar has the full structured form (sender ids, reply ids, edit dates, reactions, forwards, media class names) for re-rendering or programmatic ingestion.

## Rate limits

- gramjs auto-handles `FLOOD_WAIT_X` errors and will sleep up to 5 minutes for the messages script (60 s for the chat list script).
- 1.5 s pause between chats.
- Telegram allows roughly 3000 messages/min for user clients, so a 100k-message group takes ~30 min in the worst case. Most chats finish in seconds.

## Scope

Includes: groups, supergroups, channels, broadcast groups, archived chats, forum topics.
Excludes: DMs, bots, media file downloads.

## Files

- `.env`, `.session`, `output/` — gitignored.
- `.session` is chmod 600. Delete it to force re-auth (e.g., if you revoked the session in Telegram → Settings → Devices).
- Pagination cap of 1000 dialogs per archived state in `lib.js`. Bump if you have more.

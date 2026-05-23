# Telegram export script — extensions for per-person reactions and avatars

Companion to [`edge-city-demo-implementation-plan.md`](https://github.com/Oshyan/edge-city-demo/blob/main/docs/edge-city-demo-implementation-plan.md). Referenced
from Phase 3 (Data export).

The existing Telegram export script lives at
`/Users/oshyan/Projects/Coding/small/telegram-export` and was used
to produce the corpus in `output/messages/` covering all three
popups' main and housing groups plus several side-channels (~18 MB,
~19,200 messages, exported 2026-05-02). It uses gramjs (MTProto
user-client) with the user's own Telegram account credentials.

The existing export captures most of what's needed but has two
material gaps that affect demo quality:

1. **Reactions are aggregate counts only.** Each message's
   `reactions` field is shaped `[{emoji, custom_emoji_id,
   count}, ...]`. The script never resolves which specific people
   used which reaction.
2. **Avatars are not fetched.** The export captures sender_id and
   display name per message but no profile photo data.

This doc specifies the extensions needed to close those gaps, plus
implementation notes and operational considerations.

## Why the extensions matter

**Per-person reactions** — primary use case is making the imported
content feel *alive* in the demo. A Telegram intro that got six
heart-reactions reads as "this community engages" only if those
six reactions are attributable to six specific community members
with their names and avatars visible. Aggregate "6 ❤" without
attribution reads as sterile.

Secondary use cases (worth being aware of, not the demo driver):

- **Engagement signal for badge logic.** Reaction activity is an
  additional participation signal beyond join + post for inferring
  residency membership or general community involvement.
- **Discovery and social graph** — see the demo plan's Stretch
  goals section. Per-person reaction data is the foundational
  input for any reaction-driven social-graph or
  recommendation-engine feature.

**Avatars** — visual density on the Discourse side. Imported
topics, intros, and reactions all gain visual identity when
profile photos render correctly. The difference between an
all-default-avatar demo and a real-photos demo is significant for
the "looks alive" property of the persuasion artifact.

## Schema additions to the export

The existing per-message JSON shape stays backward-compatible.
Three new fields and one new sibling file:

### Per-message JSON additions

```json
{
  "id": 14278,
  // ... existing fields preserved ...
  "reactions": [
    {"emoji": "❤", "custom_emoji_id": null, "count": 6}
  ],
  "reactions_detail": [
    {"sender_id": "12345678", "emoji": "❤", "date": "2026-04-22T01:22:23-07:00"},
    {"sender_id": "23456789", "emoji": "❤", "date": "2026-04-22T01:25:11-07:00"},
    {"sender_id": "34567890", "emoji": "❤", "date": "2026-04-22T01:31:42-07:00"}
    // ... one entry per (person, emoji) pair ...
  ],
  "reactions_anonymous_count": 0
}
```

- **`reactions_detail`** — array of per-person reaction entries
  with `sender_id`, `emoji`, and `date`. Empty array when the
  message has no reactions or when all reactions are anonymous.
- **`reactions_anonymous_count`** — number of reactions whose
  user-data the API didn't return because the reactor opted into
  reaction-anonymity. Aggregate counts still resolve correctly
  via the existing `reactions[].count` field; this just lets us
  know how many of those aren't attributable.

The existing `reactions` field stays unchanged so any downstream
consumers that expect aggregate-only continue to work.

### New sibling file: `senders.json` per chat

Per-chat `output/messages/<chat-slug>-<chat-id>/senders.json` with
a single object keyed by sender_id:

```json
{
  "12345678": {
    "sender_id": "12345678",
    "username": "ogreenius",
    "display_name": "Oshyan Greene",
    "first_name": "Oshyan",
    "last_name": "Greene",
    "phone": null,
    "avatar_path": "avatars/12345678.jpg",
    "avatar_bytes_size": 23456,
    "fetched_at": "2026-05-23T14:00:00-07:00"
  },
  "23456789": { ... }
}
```

- **`avatar_path`** — relative path inside the chat directory.
  Avatars stored as JPEGs at `avatars/<sender_id>.jpg`. Null
  when the user has no avatar set or when fetch failed.
- Phone numbers are typically null for non-contacts; included
  for completeness.
- One sender record per unique sender_id seen across the chat's
  messages.

A `senders.json` per chat (rather than one global file) keeps the
export per-chat self-contained and easier to selectively re-run.

## Implementation outline

The existing script is `messages.js` (full message history pull).
Two new responsibilities to add:

### Reaction detail fetch

After fetching messages for a topic/chat, iterate over messages
with non-empty `reactions[]`. For each, call:

```js
const result = await client.invoke(
  new Api.messages.GetMessageReactionsList({
    peer: peerInputObject,
    msgId: message.id,
    limit: 100
  })
);
```

The result includes a `reactions` array of
`MessagePeerReaction` objects, each with:
- `peerId` — the user who reacted (or null if anonymous)
- `reaction` — the emoji (or custom emoji reference)
- `date` — when the reaction was added

Iterate, populate `reactions_detail[]`. Increment
`reactions_anonymous_count` when `peerId` is null. Paginate via
the `nextOffset` field if the response indicates more results
exist (rare in practice — most messages have well under 100
reactions).

### Avatar fetch

Collect the set of unique sender_ids encountered while processing
messages. After messages are processed, for each sender_id:

```js
const fullUser = await client.invoke(
  new Api.users.GetFullUser({ id: senderId })
);
```

The `fullUser.user.photo` field contains the profile photo
reference. Use gramjs's `client.downloadProfilePhoto(user)` to
download as a Buffer, write to
`avatars/<sender_id>.jpg`. Populate the sender record in
`senders.json`.

### Rate limit handling

MTProto enforces FloodWait throttling. The existing script already
handles this for the message-history walk. The new calls add
roughly:

- N additional API calls per chat where N = number of messages
  with non-empty reactions. For the existing corpus that's
  probably a few hundred to a few thousand calls total.
- M additional API calls per chat where M = unique sender count.
  ~400-500 per main popup group based on the export stats.

Total order of magnitude: low thousands of extra API calls across
all chats. Should be runnable within a single uninterrupted
session, but the script should:

- Catch `FloodWaitError` and respect the suggested wait time.
- Add a small inter-call delay (50-200ms) to stay under the
  threshold proactively.
- Persist progress incrementally so a partial run can resume
  without redoing completed work.

Realistic re-export runtime: tens of minutes to a couple of hours
depending on how strict the rate limits are during the run.

### Idempotency and re-running

The script should be re-runnable without redoing already-completed
work:

- Avatar downloads: skip if `avatars/<sender_id>.jpg` already
  exists.
- Reaction detail fetches: easiest to re-run from scratch when
  significant time has passed since the last run, since
  reactions can be added/removed. For incremental updates, gate
  the per-message call on `message.edit_date > last_export_date`
  or just re-fetch everything (cheaper than tracking diffs).
- Final output writes: atomic — write to a temp file then rename.

## Operational considerations

- **Reactions are mutable.** A user can add or remove a reaction
  at any time. The `reactions_detail` snapshot reflects the
  state at fetch time only. For demo purposes this is fine — the
  reactions are imported as historical record. For ongoing sync
  it would need a re-fetch policy.
- **Anonymous reactions exist but are rare.** Some Telegram
  channels allow anonymous reactions; users can also set their
  account to hide their reaction identity. In practice the popup
  groups don't appear to use anonymous mode (verifiable via the
  export: if `count > sum(per-person attribution)`, the
  difference is anonymous).
- **Privacy considerations.** Per-person reaction data is
  visible to everyone in the Telegram group already — Telegram
  shows "tap to see who reacted" on any message. So exporting
  it doesn't widen exposure; it just preserves data that's
  already visible to anyone with access to the group.
- **Avatar files.** Avatars are individual JPEGs, typically a
  few KB to ~50 KB each. For ~500 unique senders across the
  full corpus that's tens of MB of additional disk usage. Not a
  concern.

## What stays out of scope

- **Custom emoji rendering.** Telegram supports custom emoji
  (animated, premium). The export captures `custom_emoji_id`
  but doesn't currently resolve those to actual emoji images.
  Out of scope for v1 — fall back to a placeholder or skip
  custom-emoji reactions in the imported Discourse rendering.
- **Reaction edit history.** Telegram doesn't expose
  add/remove history for reactions, only the current state.
- **Sender phone number scraping.** Phone numbers are
  available for contacts but not for non-contacts; not useful
  for the demo purpose and creates privacy concerns. Leave as
  null.
- **Sticker reactions.** Telegram is rolling out sticker
  reactions; the API returns these similarly but the rendering
  side on Discourse is harder. Treat as a future addition.

## Validation after re-export

Spot-checks before declaring the re-export complete:

- For a sample message known to have multiple reactions: count
  matches and per-person attribution looks correct.
- Sender count in `senders.json` matches the unique-sender
  count in the chat metadata.
- Avatar files exist for senders with non-null `avatar_path` and
  open as valid JPEGs.
- Aggregate `reactions[].count` still matches what it was in
  the prior export (sanity check that we haven't broken the
  existing field).

## Future additions worth flagging

(Not part of this extension but adjacent enough to mention.)

- **Per-user reaction-frequency histogram** — derived from
  `reactions_detail`, useful for the social-graph stretch goal.
- **User-to-user reaction graph** — edge weight = count of
  times user A reacted to user B's messages. Direct input for
  the `merefield/discourse-user-network-vis` plugin or any
  recommendation engine.
- **Sticker reactions** — when prioritized.
- **Custom-emoji resolution** — download the custom emoji
  images and reference them by stable ID.

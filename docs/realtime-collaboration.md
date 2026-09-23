# Real-time collaboration

The block editor supports multi-user editing on the same live page:

- **Separate blocks** (Level 1, always available): each block is a separate
  element; the editor shows per-block selection + presence cursors.
- **Real-time same-block** (Level 2): a shared Yjs document per context
  (`template:{id}` / `object:{id}`) syncs block-tree structure, non-text fields,
  and rich text via **Tiptap + y-prosemirror** (Yjs-native). Multiple cursors
  co-edit the same block.

## Transport

- **In scope:** a WebSocket relay — a small `y-websocket` Node sidecar
  (`collab:serve` npm script). The Symfony side only *admits* editors via
  `GET /api/editor/room/{contextType}/{contextId}?token=` (validates the editor's
  ephemeral API token + permission); the actual Yjs update relay is
  client-to-client through the Node relay (the server is not in the keystroke path).
- **Presence/fallback:** a cache-backed `PresenceStore` (`cache.database`)
  provides presence + soft locks without a hub (polling fallback).
- **Plugin transports:** Redis / Mercure WebSocket are **plugin territory** via
  `CollaborationTransportInterface` (tagged `iikiti.cms.collaboration_transport`).

## Persistence

- Autosave snapshots the Yjs doc → JSON block tree → `POST /api/editor/save`
  (ETag concurrency). `Publish` → `POST /api/editor/publish` (draft → published).
- The server is the durable source of truth between autosaves; clients converge
  via Yjs between saves.

## Deploy

- Run the relay sidecar: `npm run collab:serve` (exposes the y-websocket server).
- Document the host/port in the site config (`iikiti.collab.ws_url`).

## Follow-ups

- A PHP WebSocket server alternative is pluggable via the transport interface.

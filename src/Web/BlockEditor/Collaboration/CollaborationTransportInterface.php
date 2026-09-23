<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Collaboration;

/**
 * Pluggable realtime transport for collaboration.
 *
 * Core does NOT ship a concrete transport (Redis/Mercure/WS-in-PHP are plugin
 * territory). The in-scope transport is a WebSocket relay (a `y-websocket` Node
 * sidecar) — the server's realtime surface is the {@see \iikiti\CMS\Controller\Api\Editor\EditorRoomController}
 * admission gate + {@see PresenceStore}; actual Yjs update relay happens
 * client-to-client through the relay, not through PHP.
 *
 * Plugins register an implementation to add alternative transports.
 */
interface CollaborationTransportInterface
{
	/**
	 * The transport name, e.g. `websocket`, `redis`, `mercure`.
	 */
	public function getName(): string;

	/**
	 * Whether this transport is usable in the current deployment
	 * (e.g. a configured websocket/mercure hub URL).
	 */
	public function isAvailable(): bool;

	/**
	 * Publish a realtime message (presence/awareness update) to a room.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function publish(string $room, array $payload): void;
}

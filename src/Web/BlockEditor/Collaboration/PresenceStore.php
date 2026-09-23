<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Collaboration;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Presence + per-context soft-lock state, persisted in the shared database cache
 * so it survives multi-instance deploys. Used by the polling fallback (no
 * realtime hub) and to populate the editor's presence list. Auto-expires on a
 * heartbeat TTL.
 */
final class PresenceStore
{
	private const TTL = 20; // seconds; editors refresh on each heartbeat

	public function __construct(
		#[Autowire(service: 'cache.database')]
		private readonly CacheItemPoolInterface $cache,
	) {
	}

	/**
	 * @param array{userId:int|string, displayName:string, blockId?:string|null} $presence
	 */
	public function setPresence(string $room, array $presence): void
	{
		$key = $this->key($room, $presence['userId']);
		$item = $this->cache->getItem($key);
		$item->set($presence);
		$item->expiresAfter(self::TTL);
		$this->cache->save($item);
	}

	/**
	 * @return list<array{userId:int|string, displayName:string, blockId?:string|null}>
	 */
	public function getPresence(string $room): array
	{
		// Presence items are keyed per user; the cache layer doesn't enumerate,
		// so presence for the realtime (Yjs awareness) path is served by the
		// websocket relay. This method supports the fallback path via a
		// separate room-index item.
		$index = $this->cache->getItem($this->indexKey($room));
		$ids = $index->isHit() ? array_values((array) $index->get()) : [];

		$presence = [];
		foreach ($ids as $userId) {
			$item = $this->cache->getItem($this->key($room, $userId));
			if ($item->isHit()) {
				$presence[] = is_array($item->get()) ? $item->get() : [];
			}
		}

		return $presence;
	}

	/**
	 * @param array{userId:int|string} $presence
	 */
	public function setRoomIndex(string $room, array $presence): void
	{
		$index = $this->cache->getItem($this->indexKey($room));
		$ids = $index->isHit() ? (array) $index->get() : [];
		$ids[(string) $presence['userId']] = (string) $presence['userId'];
		$index->set(array_values($ids));
		$index->expiresAfter(self::TTL);
		$this->cache->save($index);
	}

	public function leave(string $room, int|string $userId): void
	{
		$this->cache->deleteItem($this->key($room, $userId));
	}

	private function key(string $room, int|string $userId): string
	{
		return 'iikiti_presence:'.md5($room.':'.$userId);
	}

	private function indexKey(string $room): string
	{
		return 'iikiti_presence_index:'.md5($room);
	}
}

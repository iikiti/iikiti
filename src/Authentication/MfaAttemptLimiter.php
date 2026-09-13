<?php

namespace iikiti\CMS\Authentication;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Limits repeated multi-factor challenge failures.
 *
 * A code has few digits, so an attacker who already knows the password could
 * otherwise guess it by brute force. Failed attempts are counted in a
 * server-side cache keyed by user, so discarding the session cookie does not
 * reset the counter.
 */
class MfaAttemptLimiter
{
	private const KEY_PREFIX = 'mfa_attempts_';

	public function __construct(
		private readonly CacheItemPoolInterface $cache,
		private readonly int $maxAttempts = 5,
		private readonly int $lockoutSeconds = 300,
	) {
	}

	public function isLocked(string $userIdentifier): bool
	{
		$record = $this->getRecord($userIdentifier);

		return null !== $record && $record['locked_until'] > time();
	}

	public function registerFailure(string $userIdentifier): void
	{
		$record = $this->getRecord($userIdentifier) ?? ['attempts' => 0, 'locked_until' => 0];
		++$record['attempts'];

		if ($record['attempts'] >= $this->maxAttempts) {
			$record['locked_until'] = time() + $this->lockoutSeconds;
		}

		$item = $this->cache->getItem($this->cacheKey($userIdentifier));
		$item->set($record);
		$item->expiresAfter(max(1, $this->lockoutSeconds * 2));
		$this->cache->save($item);
	}

	public function reset(string $userIdentifier): void
	{
		$this->cache->deleteItem($this->cacheKey($userIdentifier));
	}

	public function getSecondsUntilUnlock(string $userIdentifier): int
	{
		$record = $this->getRecord($userIdentifier);
		if (null === $record) {
			return 0;
		}

		return max(0, $record['locked_until'] - time());
	}

	/**
	 * @return array{attempts:int,locked_until:int}|null
	 */
	private function getRecord(string $userIdentifier): ?array
	{
		$item = $this->cache->getItem($this->cacheKey($userIdentifier));
		if (false === $item->isHit()) {
			return null;
		}

		$record = $item->get();
		if (!is_array($record)) {
			return null;
		}

		$attempts = $record['attempts'] ?? 0;
		$lockedUntil = $record['locked_until'] ?? 0;
		if (!is_int($attempts) || !is_int($lockedUntil)) {
			return null;
		}

		// An expired lockout starts a fresh window rather than locking the
		// user out again on their next mistake.
		if ($attempts >= $this->maxAttempts && $lockedUntil <= time()) {
			return null;
		}

		return ['attempts' => $attempts, 'locked_until' => $lockedUntil];
	}

	private function cacheKey(string $userIdentifier): string
	{
		return self::KEY_PREFIX.hash('sha256', $userIdentifier);
	}
}

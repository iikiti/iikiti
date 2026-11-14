<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Dos;

use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tracks per-client error bursts and bounds in-flight expensive-route concurrency.
 *
 * All state lives in `cache.firewall_ratelimit`. Counters are best-effort: a cache failure
 * never blocks a request, because fail-open here only forgoes anomaly signals.
 */
final class FirewallAnomalyDetector
{
	private const TRACKED_STATUSES = [401, 403, 404, 422];
	private const EXPENSIVE_GROUPS = ['search', 'admin', 'public'];
	private const SLOT_TTL_SECONDS = 300;
	private const KEY_PREFIX = 'fw_anomaly_v1_';

	public function __construct(
		#[Autowire(service: 'cache.firewall_ratelimit')]
		private readonly CacheItemPoolInterface $cache,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Records a response status and reports whether the client's 4xx burst exceeded the threshold.
	 *
	 * @param array<string,mixed> $dosConfig
	 */
	public function recordResponseStatus(string $clientIp, int $statusCode, array $dosConfig, int|string|null $siteId): bool
	{
		if (!in_array($statusCode, self::TRACKED_STATUSES, true)) {
			return false;
		}
		$threshold = $this->positiveInt($dosConfig, 'errorStormThreshold');
		$window = $this->positiveInt($dosConfig, 'errorStormWindow');
		$subject = IpCidrNormalizer::subnetKey($clientIp) ?? $clientIp;

		$count = $this->increment($this->key('err', $subject, $siteId), $window);

		return null !== $count && $count > $threshold;
	}

	/**
	 * Acquires a slot for an expensive route. Cheap routes are not applicable; a cap breach is
	 * reported as `exceeded`; an unavailable counter store fails open as `unavailable`.
	 *
	 * @param array<string,mixed> $dosConfig
	 */
	public function acquireConcurrencySlot(string $clientIp, string $routeGroup, array $dosConfig, int|string|null $siteId): FirewallConcurrencySlotResult
	{
		if (!in_array($routeGroup, self::EXPENSIVE_GROUPS, true)) {
			return FirewallConcurrencySlotResult::notApplicable();
		}
		$perIp = $this->positiveInt($dosConfig, 'expensiveRouteConcurrencyPerIp');
		$perSite = $this->positiveInt($dosConfig, 'expensiveRouteConcurrencyPerSite');
		$ipKey = $this->key('conc-ip', $clientIp, $siteId);
		$siteKey = $this->key('conc-site', '', $siteId);

		$ipCount = $this->increment($ipKey, self::SLOT_TTL_SECONDS);
		if (null === $ipCount) {
			return FirewallConcurrencySlotResult::unavailable();
		}
		if ($ipCount > $perIp) {
			$this->decrement($ipKey);

			return FirewallConcurrencySlotResult::exceededCap();
		}

		$siteCount = $this->increment($siteKey, self::SLOT_TTL_SECONDS);
		if (null === $siteCount || $siteCount > $perSite) {
			$this->decrement($ipKey);
			if (null !== $siteCount) {
				$this->decrement($siteKey);
			}

			return null === $siteCount ? FirewallConcurrencySlotResult::unavailable() : FirewallConcurrencySlotResult::exceededCap();
		}

		return FirewallConcurrencySlotResult::granted($ipKey."\0".$siteKey);
	}

	/** Releases a slot previously returned by {@see acquireConcurrencySlot()}. */
	public function releaseConcurrencySlot(string $slotKey): void
	{
		[$ipKey, $siteKey] = array_pad(explode("\0", $slotKey, 2), 2, '');
		$this->decrement($ipKey);
		if ('' !== $siteKey) {
			$this->decrement($siteKey);
		}
	}

	private function key(string $scope, string $subject, int|string|null $siteId): string
	{
		return self::KEY_PREFIX.hash('sha256', implode("\0", [$scope, (string) ($siteId ?? '0'), $subject]));
	}

	/** Atomically-enough increments a counter with a TTL; returns null when the cache is unavailable. */
	private function increment(string $key, int $ttl): ?int
	{
		try {
			$item = $this->cache->getItem($key);
			$count = ($item->isHit() && is_int($item->get()) ? (int) $item->get() : 0) + 1;
			$item->set($count);
			$item->expiresAfter($ttl);
			$this->cache->save($item);

			return $count;
		} catch (\Throwable $exception) {
			$this->logger->warning('Firewall anomaly counter unavailable.', ['exception' => $exception]);

			return null;
		}
	}

	private function decrement(string $key): void
	{
		try {
			$item = $this->cache->getItem($key);
			if (!$item->isHit() || !is_int($item->get())) {
				return;
			}
			$count = max(0, (int) $item->get() - 1);
			$item->set($count);
			$this->cache->save($item);
		} catch (\Throwable $exception) {
			$this->logger->warning('Firewall concurrency slot release failed.', ['exception' => $exception]);
		}
	}

	/** @param array<string,mixed> $dosConfig */
	private function positiveInt(array $dosConfig, string $key): int
	{
		$value = $dosConfig[$key] ?? null;

		return is_int($value) && $value > 0 ? $value : 1;
	}
}

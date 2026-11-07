<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FirewallRuleSetCache
{
	public function __construct(
		#[Autowire(service: 'cache.firewall')]
		private CacheItemPoolInterface $cache,
		private LoggerInterface $logger,
		#[Autowire('%iikiti_firewall_ruleset_ttl%')]
		private int $ttl = 300,
	) {
	}

	/** @param callable():FirewallCompiledRuleSet $create */
	public function get(int|string|null $siteId, bool $includeSiteRules, callable $create): FirewallCompiledRuleSet
	{
		$key = $this->key($siteId, $includeSiteRules);
		try {
			$item = $this->cache->getItem($key);
			$cached = $item->get();
			if ($item->isHit() && $cached instanceof FirewallCompiledRuleSet) {
				return $cached;
			}
		} catch (\Throwable $exception) {
			$this->logger->warning('Firewall ruleset cache read failed; compiling rules for this request.', ['exception' => $exception]);

			return $create();
		}

		$compiled = $create();
		try {
			$item->set($compiled);
			$item->expiresAfter($this->ttl);
			if (!$this->cache->save($item)) {
				$this->logger->warning('Firewall ruleset cache write was not stored.');
			}
		} catch (\Throwable $exception) {
			$this->logger->warning('Firewall ruleset cache write failed.', ['exception' => $exception]);
		}

		return $compiled;
	}

	public function invalidateSite(int|string|null $siteId): void
	{
		try {
			$siteDeleted = $this->cache->deleteItem($this->key($siteId, true));
			$globalDeleted = $this->cache->deleteItem($this->key($siteId, false));
			if (!$siteDeleted || !$globalDeleted) {
				$this->logger->error('One or more site firewall ruleset cache entries were not invalidated.', ['site_id' => $siteId]);
			}
		} catch (\Throwable $exception) {
			$this->logger->error('Could not invalidate a site firewall ruleset cache.', [
				'site_id' => $siteId,
				'exception' => $exception,
			]);
		}
	}

	public function invalidateAll(): void
	{
		try {
			if (!$this->cache->clear()) {
				$this->logger->error('Firewall ruleset cache could not be fully cleared.');
			}
		} catch (\Throwable $exception) {
			$this->logger->error('Could not invalidate the firewall ruleset cache.', ['exception' => $exception]);
		}
	}

	private function key(int|string|null $siteId, bool $includeSiteRules): string
	{
		return 'firewall_ruleset_v1_'.hash('sha256', ($siteId ?? '0').':'.(int) $includeSiteRules);
	}
}

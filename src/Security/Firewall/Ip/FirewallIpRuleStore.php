<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Ip;

use iikiti\CMS\Repository\Object\FirewallIpRuleRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Loads managed IP rules and caches the compiled {@see FirewallIpMatcher} per site scope.
 *
 * Additional rule sources (for example Milestone 2 dynamic bans) are plugged in via
 * {@see FirewallIpRuleProviderInterface} without changing this constructor's callers.
 */
final readonly class FirewallIpRuleStore
{
	private const CACHE_KEY_PREFIX = 'firewall_ip_matcher_v1_';

	/**
	 * @param iterable<FirewallIpRuleProviderInterface> $providers
	 */
	public function __construct(
		private FirewallIpRuleRepository $rules,
		#[Autowire(service: 'cache.firewall')]
		private CacheItemPoolInterface $cache,
		private LoggerInterface $logger,
		#[AutowireIterator('iikiti.security.firewall_ip_rule_provider')]
		private iterable $providers = [],
		#[Autowire('%iikiti_firewall_ruleset_ttl%')]
		private int $ttl = 300,
	) {
	}

	/**
	 * Returns the compiled matcher covering global rules and, when supplied, one site's rules.
	 */
	public function getMatcher(int|string|null $siteId): FirewallIpMatcher
	{
		$key = self::cacheKey($siteId);
		try {
			$item = $this->cache->getItem($key);
			$cached = $item->get();
			if ($item->isHit() && $cached instanceof FirewallIpMatcher) {
				return $cached;
			}
		} catch (\Throwable $exception) {
			$this->logger->warning('Firewall IP matcher cache read failed; compiling rules for this request.', ['exception' => $exception]);
		}

		$matcher = $this->compile($siteId);
		$this->store($key, $matcher);

		return $matcher;
	}

	public function invalidateSite(int|string|null $siteId): void
	{
		try {
			$this->cache->deleteItem(self::cacheKey($siteId));
			$this->cache->deleteItem(self::cacheKey(null));
		} catch (\Throwable $exception) {
			$this->logger->error('Could not invalidate the firewall IP matcher cache.', ['site_id' => $siteId, 'exception' => $exception]);
		}
	}

	public function invalidateAll(): void
	{
		try {
			if (!$this->cache->clear()) {
				$this->logger->error('Firewall IP matcher cache could not be fully cleared.');
			}
		} catch (\Throwable $exception) {
			$this->logger->error('Could not clear the firewall IP matcher cache.', ['exception' => $exception]);
		}
	}

	private function compile(int|string|null $siteId): FirewallIpMatcher
	{
		$specs = [];
		foreach ($this->rules->findForSiteAndGlobal($siteId) as $rule) {
			$specs[] = $rule->toSpec();
		}
		foreach ($this->providers as $provider) {
			foreach ($provider->getRuleSpecs($siteId) as $spec) {
				$specs[] = $spec;
			}
		}

		return new FirewallIpMatcher($specs);
	}

	private function store(string $key, FirewallIpMatcher $matcher): void
	{
		try {
			$item = $this->cache->getItem($key);
			$item->set($matcher);
			$item->expiresAfter($this->ttl);
			if (!$this->cache->save($item)) {
				$this->logger->warning('Firewall IP matcher cache write was not stored.');
			}
		} catch (\Throwable $exception) {
			$this->logger->warning('Firewall IP matcher cache write failed.', ['exception' => $exception]);
		}
	}

	private static function cacheKey(int|string|null $siteId): string
	{
		return self::CACHE_KEY_PREFIX.hash('sha256', (string) ($siteId ?? '0'));
	}
}

<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Policy;

use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Persists Application/Site protection policies and serves the resolved, merged policy from cache.
 */
final class FirewallProtectionPolicyStore
{
	private const PROPERTY = 'security_firewall_protection_policy';
	private const CACHE_KEY_PREFIX = 'firewall_protection_policy_v1_';

	/** Version reported when no policy has been stored yet. */
	private const EMPTY_VERSION = 1;

	public function __construct(
		private readonly FirewallProtectionPolicyValidator $validator,
		private readonly FirewallProtectionPolicyResolver $resolver,
		#[Autowire(service: 'cache.firewall')]
		private readonly CacheItemPoolInterface $cache,
		private readonly LoggerInterface $logger,
		#[Autowire('%iikiti_firewall_ruleset_ttl%')]
		private readonly int $ttl = 300,
	) {
	}

	/** @return array{version:int,rateLimits:list<array<string,mixed>>,dos:array<string,mixed>,bots:array<string,mixed>} */
	public function getApplicationPolicy(Application $application): array
	{
		return $this->read($application, true);
	}

	/** @return array{version:int,rateLimits:list<array<string,mixed>>,dos:array<string,mixed>,bots:array<string,mixed>} */
	public function getSitePolicy(Site $site): array
	{
		return $this->read($site, false);
	}

	/**
	 * @param array<string,mixed> $policy
	 *
	 * @return int The new policy version.
	 */
	public function setApplicationPolicy(Application $application, array $policy): int
	{
		return $this->write($application, $policy, true, null);
	}

	/** @param array<string,mixed> $policy */
	public function setSitePolicy(Site $site, array $policy): int
	{
		return $this->write($site, $policy, false, $site->getId());
	}

	/**
	 * Returns the effective policy for a site (or the global scope when null), cached until invalidated.
	 *
	 * @return array{rateLimits:list<array<string,mixed>>,dos:array<string,mixed>,bots:array<string,mixed>}
	 */
	public function getResolvedPolicy(?Application $application, ?Site $site): array
	{
		$siteId = $site?->getId();
		$key = self::cacheKey($siteId);
		try {
			$item = $this->cache->getItem($key);
			$cached = $item->get();
			if ($item->isHit() && is_array($cached)) {
				/** @var array{rateLimits:list<array<string,mixed>>,dos:array<string,mixed>,bots:array<string,mixed>} $cached */
				return $cached;
			}
		} catch (\Throwable $exception) {
			$this->logger->warning('Firewall protection policy cache read failed; resolving for this request.', ['exception' => $exception]);
		}

		$resolved = $this->resolver->resolve(
			null === $application ? null : $this->stripVersion($this->getApplicationPolicy($application)),
			null === $site ? null : $this->stripVersion($this->getSitePolicy($site)),
		);
		$this->store($key, $resolved);

		return $resolved;
	}

	public function invalidateSite(int|string|null $siteId): void
	{
		try {
			$this->cache->deleteItem(self::cacheKey($siteId));
		} catch (\Throwable $exception) {
			$this->logger->error('Could not invalidate the firewall protection policy cache.', ['site_id' => $siteId, 'exception' => $exception]);
		}
	}

	/**
	 * @return array{version:int,rateLimits:list<array<string,mixed>>,dos:array<string,mixed>,bots:array<string,mixed>}
	 */
	private function read(Application|Site $owner, bool $isApplication): array
	{
		$property = $owner->getProperties()->get(self::PROPERTY);
		if (null === $property) {
			return ['version' => self::EMPTY_VERSION, ...$this->validator->validate([], $isApplication)];
		}
		$value = $property->getValue();
		if (!is_array($value) || !isset($value['version']) || !is_int($value['version']) || $value['version'] < 1) {
			throw new \UnexpectedValueException('Stored firewall protection policy is malformed.');
		}

		try {
			return ['version' => $value['version'], ...$this->validator->validate($value, $isApplication)];
		} catch (\InvalidArgumentException $exception) {
			throw new \UnexpectedValueException('Stored firewall protection policy is malformed.', 0, $exception);
		}
	}

	/**
	 * @param array<string,mixed> $policy
	 */
	private function write(Application|Site $owner, array $policy, bool $isApplication, int|string|null $siteId): int
	{
		$validated = $this->validator->validate($policy, $isApplication);
		$version = $this->read($owner, $isApplication)['version'];
		if (PHP_INT_MAX === $version) {
			throw new \OverflowException('Firewall protection policy version exhausted.');
		}
		++$version;
		$owner->setProperty(self::PROPERTY, ['version' => $version, ...$validated]);
		if ($isApplication) {
			// An Application change affects every site's resolved policy.
			$this->invalidateAll();
		} else {
			$this->invalidateSite($siteId);
		}

		return $version;
	}

	/** @param array<string,mixed> $resolved */
	private function store(string $key, array $resolved): void
	{
		try {
			$item = $this->cache->getItem($key);
			$item->set($resolved);
			$item->expiresAfter($this->ttl);
			if (!$this->cache->save($item)) {
				$this->logger->warning('Firewall protection policy cache write was not stored.');
			}
		} catch (\Throwable $exception) {
			$this->logger->warning('Firewall protection policy cache write failed.', ['exception' => $exception]);
		}
	}

	private function invalidateAll(): void
	{
		try {
			$this->cache->clear();
		} catch (\Throwable $exception) {
			$this->logger->error('Could not clear the firewall protection policy cache.', ['exception' => $exception]);
		}
	}

	/**
	 * @param array{version:int,rateLimits:list<array<string,mixed>>,dos:array<string,mixed>,bots:array<string,mixed>} $policy
	 *
	 * @return array{rateLimits:list<array<string,mixed>>,dos:array<string,mixed>,bots:array<string,mixed>}
	 */
	private function stripVersion(array $policy): array
	{
		return ['rateLimits' => $policy['rateLimits'], 'dos' => $policy['dos'], 'bots' => $policy['bots']];
	}

	private static function cacheKey(int|string|null $siteId): string
	{
		return self::CACHE_KEY_PREFIX.hash('sha256', (string) ($siteId ?? '0'));
	}
}

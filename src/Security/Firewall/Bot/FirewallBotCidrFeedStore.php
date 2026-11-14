<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Bot;

use iikiti\CMS\Security\Firewall\FirewallBotCidrProviderInterface;
use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Stores published bot CIDR feeds on disk and serves normalized, cached lists.
 *
 * Feeds are written only after signature verification by the caller; this store
 * never trusts raw network input.
 */
final readonly class FirewallBotCidrFeedStore
{
	private const CACHE_KEY_PREFIX = 'firewall_bot_cidrs_v1_';
	private const SOURCE_KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/D';
	private const MAX_CIDRS_PER_SOURCE = 10000;

	/**
	 * @param iterable<FirewallBotCidrProviderInterface> $providers
	 */
	public function __construct(
		#[Autowire('%iikiti_security_firewall_pack_directory%')]
		private string $packDirectory,
		#[Autowire(service: 'cache.firewall')]
		private CacheItemPoolInterface $cache,
		private LoggerInterface $logger,
		#[AutowireIterator('iikiti.security.firewall_bot_cidr_provider')]
		private iterable $providers = [],
		#[Autowire('%iikiti_firewall_ruleset_ttl%')]
		private int $ttl = 300,
	) {
	}

	public function hasSource(string $sourceKey): bool
	{
		return [] !== $this->getCidrs($sourceKey);
	}

	public function matches(string $sourceKey, string $clientIp): bool
	{
		foreach ($this->getCidrs($sourceKey) as $cidr) {
			if (IpCidrNormalizer::matches($clientIp, $cidr)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Persists a normalized feed for a source and invalidates its cached list.
	 *
	 * @param list<string> $cidrs
	 */
	public function saveFeed(string $sourceKey, array $cidrs): void
	{
		$this->assertSourceKey($sourceKey);
		$normalized = $this->normalizeList($cidrs);
		$this->ensureDirectory($this->directory());

		$payload = json_encode([
			'schemaVersion' => 1,
			'source' => $sourceKey,
			'updatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
			'cidrs' => $normalized,
		], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

		$path = $this->feedPath($sourceKey);
		$temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
		if (false === file_put_contents($temporary, $payload, LOCK_EX) || !rename($temporary, $path)) {
			@unlink($temporary);
			throw new \RuntimeException('Unable to persist firewall bot CIDR feed.');
		}

		$this->invalidate($sourceKey);
	}

	/** @return list<string> */
	private function getCidrs(string $sourceKey): array
	{
		if (1 !== preg_match(self::SOURCE_KEY_PATTERN, $sourceKey)) {
			return [];
		}

		$cacheKey = self::CACHE_KEY_PREFIX.$sourceKey;
		try {
			$item = $this->cache->getItem($cacheKey);
			if ($item->isHit() && is_array($cached = $item->get())) {
				return $this->normalizeList($cached);
			}
		} catch (\Throwable $exception) {
			$this->logger->warning('Firewall bot CIDR cache read failed; loading feed from disk.', ['exception' => $exception]);
		}

		try {
			$cidrs = $this->loadAll($sourceKey);
		} catch (\InvalidArgumentException $exception) {
			$this->logger->warning('Firewall bot CIDR feed rejected; treating source as unavailable.', ['source' => $sourceKey, 'exception' => $exception]);

			return [];
		}
		$this->storeCache($cacheKey, $cidrs);

		return $cidrs;
	}

	/** @return list<string> */
	private function loadAll(string $sourceKey): array
	{
		$cidrs = $this->readFeedFile($sourceKey);
		foreach ($this->providers as $provider) {
			if ($provider->getSourceKey() === $sourceKey) {
				$cidrs = [...$cidrs, ...$provider->getCidrs()];
			}
		}

		return $this->normalizeList($cidrs);
	}

	/** @return list<string> */
	private function readFeedFile(string $sourceKey): array
	{
		$path = $this->feedPath($sourceKey);
		if (!is_file($path)) {
			return [];
		}

		$contents = file_get_contents($path);
		$data = false === $contents ? null : json_decode($contents, true);
		if (!is_array($data) || 1 !== ($data['schemaVersion'] ?? null) || ($data['source'] ?? null) !== $sourceKey || !is_array($data['cidrs'] ?? null)) {
			$this->logger->warning('Ignoring malformed firewall bot CIDR feed.', ['source' => $sourceKey]);

			return [];
		}

		return array_values($data['cidrs']);
	}

	/**
	 * @param array<mixed> $cidrs
	 *
	 * @return list<string>
	 */
	private function normalizeList(array $cidrs): array
	{
		$normalized = [];
		foreach ($cidrs as $cidr) {
			if (!is_string($cidr)) {
				continue;
			}
			try {
				$normalized[] = IpCidrNormalizer::normalize($cidr)['cidr'];
			} catch (\InvalidArgumentException) {
				// Invalid entries are dropped rather than failing the whole feed.
				continue;
			}
		}
		$unique = array_values(array_unique($normalized));
		if (count($unique) > self::MAX_CIDRS_PER_SOURCE) {
			throw new \InvalidArgumentException('Firewall bot CIDR feed exceeds the maximum entry count.');
		}

		return $unique;
	}

	/** @param list<string> $cidrs */
	private function storeCache(string $cacheKey, array $cidrs): void
	{
		try {
			$item = $this->cache->getItem($cacheKey);
			$item->set($cidrs);
			$item->expiresAfter($this->ttl);
			$this->cache->save($item);
		} catch (\Throwable $exception) {
			$this->logger->warning('Firewall bot CIDR cache write failed.', ['exception' => $exception]);
		}
	}

	private function invalidate(string $sourceKey): void
	{
		try {
			$this->cache->deleteItem(self::CACHE_KEY_PREFIX.$sourceKey);
		} catch (\Throwable $exception) {
			$this->logger->warning('Firewall bot CIDR cache invalidation failed.', ['exception' => $exception]);
		}
	}

	private function assertSourceKey(string $sourceKey): void
	{
		if (1 !== preg_match(self::SOURCE_KEY_PATTERN, $sourceKey)) {
			throw new \InvalidArgumentException('Firewall bot CIDR source key is invalid.');
		}
	}

	/** Feeds sit beside the rule-pack directory so both share one var/firewall root. */
	private function directory(): string
	{
		return dirname(rtrim($this->packDirectory, '/')).'/bot-cidrs';
	}

	private function feedPath(string $sourceKey): string
	{
		return $this->directory().'/'.$sourceKey.'.json';
	}

	private function ensureDirectory(string $directory): void
	{
		if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
			throw new \RuntimeException('Unable to create firewall bot CIDR directory.');
		}
	}
}

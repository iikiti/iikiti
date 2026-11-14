<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Bot;

use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Forward-confirmed reverse DNS verification for opt-in bot identity checks.
 *
 * PTR must end in an allowlisted suffix at a label boundary, and the forward A/AAAA
 * lookup of that hostname must include the client IP. Results (positive and negative)
 * are cached so repeated requests do not amplify DNS traffic.
 */
final readonly class FirewallFcrdnsVerifier
{
	private const CACHE_KEY_PREFIX = 'firewall_fcrdns_v1_';
	private const POSITIVE_TTL = 3600;
	private const NEGATIVE_TTL = 900;

	public function __construct(
		private DnsLookupInterface $dns,
		#[Autowire(service: 'cache.firewall_ratelimit')]
		private CacheItemPoolInterface $cache,
	) {
	}

	/** @param list<string> $allowedSuffixes */
	public function verify(string $clientIp, array $allowedSuffixes): bool
	{
		$ip = IpCidrNormalizer::normalizeIp($clientIp);
		$suffixes = $this->normalizeSuffixes($allowedSuffixes);
		if (null === $ip || [] === $suffixes) {
			return false;
		}

		$cacheKey = self::CACHE_KEY_PREFIX.hash('sha256', $ip.'|'.implode(',', $suffixes));
		$cached = $this->readCache($cacheKey);
		if (null !== $cached) {
			return $cached;
		}

		$verified = $this->lookup($ip, $suffixes);
		$this->writeCache($cacheKey, $verified);

		return $verified;
	}

	/** @param list<string> $suffixes */
	private function lookup(string $ip, array $suffixes): bool
	{
		$hostname = $this->dns->reverseLookup($ip);
		if (null === $hostname || !$this->hasAllowedSuffix($hostname, $suffixes)) {
			return false;
		}

		return in_array($ip, $this->dns->forwardLookup($hostname), true);
	}

	/** @param list<string> $suffixes */
	private function hasAllowedSuffix(string $hostname, array $suffixes): bool
	{
		foreach ($suffixes as $suffix) {
			// Require the leading dot so "evilgoogle.com" never satisfies ".google.com".
			if (str_ends_with($hostname, $suffix)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<string> $suffixes
	 *
	 * @return list<string>
	 */
	private function normalizeSuffixes(array $suffixes): array
	{
		$normalized = [];
		foreach ($suffixes as $suffix) {
			$candidate = strtolower(trim($suffix));
			if (1 === preg_match('/^\.[a-z0-9-]+(\.[a-z0-9-]+)+$/D', $candidate)) {
				$normalized[] = $candidate;
			}
		}

		return array_values(array_unique($normalized));
	}

	private function readCache(string $key): ?bool
	{
		try {
			$item = $this->cache->getItem($key);

			return $item->isHit() && is_bool($item->get()) ? $item->get() : null;
		} catch (\Throwable) {
			return null;
		}
	}

	private function writeCache(string $key, bool $verified): void
	{
		try {
			$item = $this->cache->getItem($key);
			$item->set($verified);
			$item->expiresAfter($verified ? self::POSITIVE_TTL : self::NEGATIVE_TTL);
			$this->cache->save($item);
		} catch (\Throwable) {
			// Caching is an optimisation; verification still returns a correct answer.
		}
	}
}

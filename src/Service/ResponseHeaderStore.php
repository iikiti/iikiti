<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ResponseHeaderStore
{
	private const string PROPERTY = 'response_headers_policy';
	private const string CACHE_PREFIX = 'response_header_policy_v1_';
	private const int CACHE_TTL_SECONDS = 3600;

	public function __construct(
		private readonly ResponseHeaderPolicy $policy,
		#[Autowire(service: 'cache.app')]
		private readonly ?CacheItemPoolInterface $cache = null,
	) {
	}

	/** @return array{version:int,rules:list<array<string,mixed>>} */
	public function getPolicy(Application|Site $owner): array
	{
		$property = $owner->getProperties()->get(self::PROPERTY);
		if (null === $property) {
			return ['version' => 1, 'rules' => []];
		}
		$value = $property->getValue();
		if (!is_array($value) || !isset($value['version'], $value['rules']) || !is_int($value['version']) || $value['version'] < 1 || !is_array($value['rules'])) {
			throw new \UnexpectedValueException('Stored response header policy is malformed.');
		}
		$isApplication = $owner instanceof Application;
		$cacheItem = null;
		if (null !== $this->cache) {
			try {
				$ownerId = (string) ($owner->getId() ?? 'transient');
				$cacheKey = self::CACHE_PREFIX.($isApplication ? 'app_' : 'site_').$ownerId.'_'.hash('sha256', serialize($value));
				$cacheItem = $this->cache->getItem($cacheKey);
				if ($cacheItem->isHit()) {
					$cached = $cacheItem->get();
					if (is_array($cached) && is_int($cached['version'] ?? null) && is_array($cached['rules'] ?? null)) {
						/** @var array{version:int,rules:list<array<string,mixed>>} $cached */
						return $cached;
					}
				}
			} catch (\Throwable) {
				$cacheItem = null;
			}
		}

		try {
			$rules = $this->policy->validateRules($value['rules'], $isApplication);
		} catch (\InvalidArgumentException $exception) {
			throw new \UnexpectedValueException('Stored response header policy is malformed.', 0, $exception);
		}

		$result = ['version' => $value['version'], 'rules' => $rules];
		if (null !== $cacheItem) {
			try {
				$cacheItem->set($result);
				$cacheItem->expiresAfter(self::CACHE_TTL_SECONDS);
				$this->cache->save($cacheItem);
			} catch (\Throwable) {
				// Cache writes are best-effort; validated policy remains usable.
			}
		}

		return $result;
	}

	/** @param list<array<string,mixed>> $rules */
	public function setPolicy(Application|Site $owner, array $rules): int
	{
		$rules = $this->policy->validateRules($rules, $owner instanceof Application);
		$version = $this->getPolicy($owner)['version'];
		if (PHP_INT_MAX === $version) {
			throw new \OverflowException('Response header policy version exhausted.');
		}
		++$version;
		$owner->setProperty(self::PROPERTY, ['version' => $version, 'rules' => $rules]);

		return $version;
	}
}

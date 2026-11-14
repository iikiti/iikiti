<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Entity\Object\Site;

/**
 * Persists and versions the per-site security header policy.
 *
 * The policy is stored as a site configuration property so it is versioned
 * implicitly alongside the Site object. Each replacement bumps the version so
 * administrators can roll back by re-applying an older observed policy.
 */
final class SecurityHeaderStore
{
	private const PROPERTY = 'security_headers_policy';

	/** @var array{version:int,headers:list<array{name:string,mode:string,value:string}>} */
	public const DEFAULT_POLICY = [
		'version' => 1,
		'headers' => [],
	];

	/** @return array{version:int,headers:list<array{name:string,mode:string,value:string}>} */
	public function getPolicy(Site $site): array
	{
		$property = $site->getProperties()->get(self::PROPERTY);
		if (null === $property) {
			return self::DEFAULT_POLICY;
		}
		$value = $property->getValue();
		if (!is_array($value) || !isset($value['version'], $value['headers']) || !is_array($value['headers'])) {
			throw new \UnexpectedValueException('Stored site security header policy is malformed.');
		}
		if (!is_int($value['version'])) {
			$value['version'] = (int) $value['version'];
		}
		$headers = [];
		foreach ($value['headers'] as $header) {
			if (!is_array($header) || !isset($header['name'], $header['mode'], $header['value'])) {
				throw new \UnexpectedValueException('Stored site security header policy contains a malformed header.');
			}
			$headers[] = [
				'name' => (string) $header['name'],
				'mode' => (string) $header['mode'],
				'value' => (string) $header['value'],
			];
		}

		return ['version' => (int) $value['version'], 'headers' => $headers];
	}

	/**
	 * Replace the policy, bumping the version. Returns the stored version.
	 *
	 * @param list<array{name:string,mode:string,value:string}> $headers
	 */
	public function setPolicy(Site $site, array $headers): int
	{
		$policy = $this->getPolicy($site);
		$version = (int) $policy['version'] + 1;

		$site->setProperty(self::PROPERTY, [
			'version' => $version,
			'headers' => $headers,
		]);

		return $version;
	}
}

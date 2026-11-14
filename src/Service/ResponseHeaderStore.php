<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;

final class ResponseHeaderStore
{
	private const PROPERTY = 'response_headers_policy';

	public function __construct(private readonly ResponseHeaderPolicy $policy)
	{
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
		try {
			$rules = $this->policy->validateRules($value['rules'], $owner instanceof Application);
		} catch (\InvalidArgumentException $exception) {
			throw new \UnexpectedValueException('Stored response header policy is malformed.', 0, $exception);
		}

		return ['version' => $value['version'], 'rules' => $rules];
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

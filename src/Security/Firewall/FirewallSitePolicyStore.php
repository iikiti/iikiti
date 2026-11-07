<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use iikiti\CMS\Entity\Object\Site;

final class FirewallSitePolicyStore
{
	private const PROPERTY = 'security_firewall_rules';

	/** @return list<array<string,mixed>> */
	public function getRules(Site $site): array
	{
		$property = $site->getProperties()->get(self::PROPERTY);
		if (null === $property) {
			return [];
		}
		$value = $property->getValue();
		if (!is_array($value) || !array_is_list($value)) {
			throw new \UnexpectedValueException('Stored site firewall policy is malformed.');
		}
		foreach ($value as $rule) {
			if (!is_array($rule)) {
				throw new \UnexpectedValueException('Stored site firewall policy contains a malformed rule.');
			}
		}

		return $value;
	}

	/** @param list<array<string,mixed>> $rules */
	public function setRules(Site $site, array $rules): void
	{
		$site->setProperty(self::PROPERTY, $rules);
	}
}

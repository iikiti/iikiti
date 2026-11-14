<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Condition;

use iikiti\CMS\Security\Firewall\FirewallConditionIndexInterface;
use iikiti\CMS\Security\Firewall\FirewallConditionInterface;
use iikiti\CMS\Security\Firewall\FirewallRequestContext;
use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;

/**
 * Matches the request client IP against one or more IPv4/IPv6 addresses or CIDR ranges.
 */
final class IpCidrCondition implements FirewallConditionInterface, FirewallConditionIndexInterface
{
	private const MAX_VALUES = 256;

	public function getName(): string
	{
		return 'ip.cidr';
	}

	public function validateOptions(array $options): void
	{
		if ([] !== array_diff(array_keys($options), ['value', 'negate'])) {
			throw new \InvalidArgumentException('Firewall ip.cidr contains unsupported options.');
		}

		$values = $this->normalizeValueList($options['value'] ?? null);
		foreach ($values as $value) {
			IpCidrNormalizer::normalize($value);
		}

		if (isset($options['negate']) && !is_bool($options['negate'])) {
			throw new \InvalidArgumentException('Firewall ip.cidr negate must be a boolean.');
		}
	}

	public function getRequestIndexKeys(array $options): array
	{
		return [FirewallRequestContext::indexKey('ip')];
	}

	public function couldMatchRequest(array $options, FirewallRequestContext $context): bool
	{
		return $context->hasValues('ip');
	}

	/** @param array<string,mixed> $options */
	public function matches(array $options, FirewallRequestContext $context): bool
	{
		$context->assertWithinBudget();

		$inRange = false;
		foreach ($this->normalizeValueList($options['value'] ?? null) as $cidr) {
			$context->assertWithinBudget();
			if (IpCidrNormalizer::matches($context->clientIp, $cidr)) {
				$inRange = true;
				break;
			}
		}

		return (bool) ($options['negate'] ?? false) ? !$inRange : $inRange;
	}

	/**
	 * @return list<string>
	 *
	 * @throws \InvalidArgumentException
	 */
	private function normalizeValueList(mixed $value): array
	{
		if (is_string($value) && '' !== $value) {
			return [$value];
		}

		if (is_array($value) && array_is_list($value) && [] !== $value && count($value) <= self::MAX_VALUES) {
			foreach ($value as $entry) {
				if (!is_string($entry) || '' === $entry) {
					throw new \InvalidArgumentException('Firewall ip.cidr values must be non-empty strings.');
				}
			}

			return $value;
		}

		throw new \InvalidArgumentException('Firewall ip.cidr requires a non-empty value or a list of up to 256 values.');
	}
}

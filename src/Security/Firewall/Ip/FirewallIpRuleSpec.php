<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Ip;

/**
 * Immutable, normalized view of an IP/CIDR firewall rule used for compiled matching.
 */
final readonly class FirewallIpRuleSpec
{
	private const ACTIONS = ['allow', 'block', 'report'];
	private const INHERITANCES = ['default', 'required'];
	private const SCOPES = ['global', 'site'];

	public function __construct(
		public string $id,
		public string $cidr,
		public string $action,
		public string $inheritance,
		public string $scope,
		public int $priority = 0,
		public bool $enabled = true,
		public ?int $expiresAt = null,
		public string $reason = '',
	) {
		if ('' === $id || '' === $cidr) {
			throw new \InvalidArgumentException('IP rule requires a non-empty id and CIDR.');
		}
		if (!in_array($action, self::ACTIONS, true)) {
			throw new \InvalidArgumentException(sprintf('Unsupported IP rule action "%s".', $action));
		}
		if (!in_array($inheritance, self::INHERITANCES, true)) {
			throw new \InvalidArgumentException(sprintf('Unsupported IP rule inheritance "%s".', $inheritance));
		}
		if (!in_array($scope, self::SCOPES, true)) {
			throw new \InvalidArgumentException(sprintf('Unsupported IP rule scope "%s".', $scope));
		}
	}

	public function isExpired(?int $now = null): bool
	{
		if (null === $this->expiresAt) {
			return false;
		}

		return $this->expiresAt <= ($now ?? time());
	}
}

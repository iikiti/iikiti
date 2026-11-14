<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\RateLimit;

/**
 * Request facts needed to select and key rate-limit rules. Built once per request.
 */
final readonly class FirewallRateLimitContext
{
	public function __construct(
		public string $clientIp,
		public string $method,
		public string $path,
		public string $routeGroup,
		public bool $authenticated,
		public ?string $userIdentifier = null,
		public ?string $tokenIdentifier = null,
		public ?string $botIdentity = null,
		public int|string|null $siteId = null,
	) {
	}
}

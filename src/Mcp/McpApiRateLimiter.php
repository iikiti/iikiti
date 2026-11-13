<?php

namespace iikiti\CMS\Mcp;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/** Applies a per-authenticated-principal and per-site MCP call limit. */
final class McpApiRateLimiter
{
	public function __construct(
		#[Autowire(service: 'limiter.mcp_api')]
		private readonly RateLimiterFactory $limiterFactory,
		private readonly Security $security,
		private readonly RequestStack $requestStack,
	) {
	}

	/** Returns the seconds until the caller may retry, or null when accepted. */
	public function consume(): ?int
	{
		$request = $this->requestStack->getCurrentRequest();
		$user = $this->security->getUser();
		$identity = $user?->getUserIdentifier() ?? $request?->getClientIp() ?? 'anonymous';
		$site = $request?->headers->get('X-SITE-HOST') ?: $request?->getHost() ?: 'global';
		$key = hash('sha256', strtolower($site)."\0".$identity);
		$rateLimit = $this->limiterFactory->create($key)->consume();

		if ($rateLimit->isAccepted()) {
			return null;
		}

		return max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());
	}
}

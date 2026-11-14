<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Challenge;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selects the challenge provider for a request. Providers are consulted in tag order, so
 * plugin providers registered with a higher priority take precedence over the core fallback.
 */
final readonly class FirewallChallengeManager
{
	/**
	 * @param iterable<FirewallChallengeProviderInterface> $providers
	 */
	public function __construct(
		#[AutowireIterator('iikiti.security.firewall_challenge_provider')]
		private iterable $providers,
	) {
	}

	/** @param array<string,mixed> $botsConfig */
	public function hasValidClearance(Request $request, array $botsConfig): bool
	{
		foreach ($this->providers as $provider) {
			if ($provider->supports($request) && $provider->hasValidClearance($request, $botsConfig)) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string,mixed> $botsConfig */
	public function challenge(Request $request, array $botsConfig, string $reason): ?Response
	{
		foreach ($this->providers as $provider) {
			if ($provider->supports($request)) {
				return $provider->createChallengeResponse($request, $botsConfig, $reason);
			}
		}

		return null;
	}
}

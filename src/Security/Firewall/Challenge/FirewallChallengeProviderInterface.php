<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Challenge;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interactive verification hook for suspicious browser traffic. Plugins may register
 * their own providers; the core proof-of-work provider is the fallback.
 */
#[AutoconfigureTag('iikiti.security.firewall_challenge_provider')]
interface FirewallChallengeProviderInterface
{
	public function getName(): string;

	/** Whether this provider can challenge the request (for example an HTML GET). */
	public function supports(Request $request): bool;

	/** @param array<string,mixed> $botsConfig */
	public function hasValidClearance(Request $request, array $botsConfig): bool;

	/** @param array<string,mixed> $botsConfig */
	public function createChallengeResponse(Request $request, array $botsConfig, string $reason): Response;
}

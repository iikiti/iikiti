<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Mapper;

use iikiti\CMS\ApiResource\FirewallTrustedAutomationResource;
use iikiti\CMS\Entity\Object\FirewallTrustedAutomation;

/**
 * Maps automations to API output. Secret material is never copied: `secret` is always null and only
 * `hasSecret` reports whether one is stored.
 */
final class FirewallTrustedAutomationMapper
{
	public function toResource(FirewallTrustedAutomation $automation): FirewallTrustedAutomationResource
	{
		return new FirewallTrustedAutomationResource(
			id: $automation->getId(),
			siteId: $automation->getSiteId(),
			name: $automation->getName(),
			handle: $automation->getHandle(),
			category: $automation->getCategory(),
			authMode: $automation->getAuthMode(),
			cidrs: $automation->getCidrs(),
			headerName: '' === $automation->getHeaderName() ? null : $automation->getHeaderName(),
			publicKey: $automation->getPublicKey(),
			secret: null,
			hasSecret: null !== $automation->getSecretMaterial(),
			allowedPaths: $automation->getAllowedPaths(),
			bypassWaf: $automation->isBypassWaf(),
			rateLimitOverride: $automation->getRateLimitOverride(),
			enabled: $automation->isEnabled(),
			priority: $automation->getPriority(),
		);
	}
}

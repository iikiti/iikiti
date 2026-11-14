<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\ApiResource\FirewallApplicationProtectionResource;
use iikiti\CMS\Security\Firewall\Admin\FirewallApplicationLookup;
use iikiti\CMS\Security\Firewall\Policy\FirewallProtectionPolicyStore;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @implements ProviderInterface<FirewallApplicationProtectionResource> */
#[AutoconfigureTag('api_platform.state_provider')]
final readonly class FirewallApplicationProtectionProvider implements ProviderInterface
{
	public function __construct(
		private FirewallApplicationLookup $applications,
		private FirewallProtectionPolicyStore $policies,
	) {
	}

	public function provide(Operation $operation, array $uriVariables = [], array $context = []): FirewallApplicationProtectionResource
	{
		$application = $this->applications->require($uriVariables);
		$policy = $this->policies->getApplicationPolicy($application);

		return new FirewallApplicationProtectionResource(
			applicationId: $application->getId(),
			version: $policy['version'],
			rateLimits: $policy['rateLimits'],
			dos: $policy['dos'],
			bots: $policy['bots'],
		);
	}
}

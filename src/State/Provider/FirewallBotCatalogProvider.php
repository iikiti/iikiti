<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\ApiResource\FirewallBotCatalogResource;
use iikiti\CMS\Security\Firewall\Bot\FirewallBotCatalog;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @implements ProviderInterface<FirewallBotCatalogResource> */
#[AutoconfigureTag('api_platform.state_provider')]
final readonly class FirewallBotCatalogProvider implements ProviderInterface
{
	public function __construct(private FirewallBotCatalog $catalog)
	{
	}

	/** @return list<FirewallBotCatalogResource> */
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
	{
		$resources = [];
		foreach ($this->catalog->all() as $definition) {
			$resources[] = new FirewallBotCatalogResource(
				id: $definition->id,
				name: $definition->name,
				category: $definition->category,
				userAgentPatterns: $definition->userAgentPatterns,
				verificationMethod: $definition->verificationMethod,
				description: $definition->description,
			);
		}

		return $resources;
	}
}

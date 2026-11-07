<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\ApiResource\FirewallRulePackResource;
use iikiti\CMS\Security\Firewall\FirewallRulePackStore;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @implements ProviderInterface<FirewallRulePackResource> */
#[AutoconfigureTag('api_platform.state_provider')]
final readonly class FirewallRulePackStatusProvider implements ProviderInterface
{
	public function __construct(private FirewallRulePackStore $packs)
	{
	}

	/** @return list<FirewallRulePackResource> */
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
	{
		return array_map(
			static fn (array $status): FirewallRulePackResource => new FirewallRulePackResource(
				$status['key'], $status['name'], $status['activeVersion'], $status['checksum'], $status['versions'],
			),
			$this->packs->getStatuses(),
		);
	}
}

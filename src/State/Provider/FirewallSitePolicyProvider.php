<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\ApiResource\FirewallSitePolicyResource;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\Firewall\FirewallSitePolicyStore;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<FirewallSitePolicyResource> */
#[AutoconfigureTag('api_platform.state_provider')]
final readonly class FirewallSitePolicyProvider implements ProviderInterface
{
	public function __construct(private FirewallSitePolicyStore $policies)
	{
	}

	public function provide(Operation $operation, array $uriVariables = [], array $context = []): FirewallSitePolicyResource
	{
		$site = SiteRegistry::getCurrent();
		if ((string) $site->getId() !== (string) ($uriVariables['siteId'] ?? '')) {
			throw new NotFoundHttpException('Site firewall policy not found.');
		}

		return new FirewallSitePolicyResource($site->getId(), $this->policies->getRules($site));
	}
}

<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\FirewallSiteProtectionResource;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Security\Firewall\Admin\FirewallSiteScope;
use iikiti\CMS\Security\Firewall\Policy\FirewallProtectionPolicyStore;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @implements ProviderInterface<FirewallSiteProtectionResource> */
#[AutoconfigureTag('api_platform.state_provider')]
final readonly class FirewallSiteProtectionProvider implements ProviderInterface
{
	public function __construct(
		private FirewallProtectionPolicyStore $policies,
		private EntityManagerInterface $entityManager,
	) {
	}

	public function provide(Operation $operation, array $uriVariables = [], array $context = []): FirewallSiteProtectionResource
	{
		$site = FirewallSiteScope::assertCurrent($uriVariables);
		$policy = $this->policies->getSitePolicy($site);

		return new FirewallSiteProtectionResource(
			siteId: $site->getId(),
			version: $policy['version'],
			rateLimits: $policy['rateLimits'],
			dos: $policy['dos'],
			bots: $policy['bots'],
			effective: $this->policies->getResolvedPolicy($this->applicationFor($site), $site),
		);
	}

	/** The effective view must include the Application layer, so `required` rules are visible to admins. */
	private function applicationFor(Site $site): ?Application
	{
		/** @var SiteRepository $sites */
		$sites = $this->entityManager->getRepository(Site::class);

		return $sites->getApplicationBySite($site);
	}
}

<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Policy;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\Object\SiteRepository;

/**
 * Resolves protection policy from the local Application/Site stores (single-runtime deployments).
 */
final readonly class LocalFirewallProtectionPolicyResolver implements FirewallProtectionPolicyResolverInterface
{
	public function __construct(
		private FirewallProtectionPolicyStore $policies,
		private EntityManagerInterface $entityManager,
	) {
	}

	public function resolveFor(?Site $site, bool $includeSiteRules, ?string $host = null, ?string $clientIp = null): FirewallResolvedPolicy
	{
		$application = null === $site ? null : $this->applicationFor($site);
		$siteScope = $includeSiteRules ? $site : null;

		return FirewallResolvedPolicy::fromArray(
			$this->policies->getResolvedPolicy($application, $siteScope),
		);
	}

	private function applicationFor(Site $site): ?Application
	{
		/** @var SiteRepository $sites */
		$sites = $this->entityManager->getRepository(Site::class);

		return $sites->getApplicationBySite($site);
	}
}

<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Policy;

use iikiti\CMS\Entity\Object\Site;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chooses the protection policy source for this runtime. API runtimes read the local stores;
 * consumer runtimes ask the API runtime over the signed internal resolver.
 */
final readonly class FirewallProtectionPolicyResolverSelector implements FirewallProtectionPolicyResolverInterface
{
	private const CONSUMER_ROLE = 'consumer';

	public function __construct(
		private LocalFirewallProtectionPolicyResolver $local,
		private RemoteFirewallProtectionResolver $remote,
		#[Autowire('%application_role%')] private string $applicationRole,
	) {
	}

	public function resolveFor(?Site $site, bool $includeSiteRules, ?string $host = null, ?string $clientIp = null): FirewallResolvedPolicy
	{
		if (self::CONSUMER_ROLE === $this->applicationRole) {
			return $this->remote->resolveFor($site, $includeSiteRules, $host, $clientIp);
		}

		return $this->local->resolveFor($site, $includeSiteRules);
	}
}

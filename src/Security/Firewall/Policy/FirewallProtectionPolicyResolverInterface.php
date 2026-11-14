<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Policy;

use iikiti\CMS\Entity\Object\Site;

/**
 * Supplies the effective protection policy for a request scope.
 *
 * The local implementation reads the Application/Site policy store. Consumer-mode deployments
 * substitute a remote implementation that asks the API runtime over a signed internal request.
 */
interface FirewallProtectionPolicyResolverInterface
{
	/**
	 * Returns the policy for the site, or the Application-only policy when `$includeSiteRules` is false.
	 * A null site resolves the global (no-site) scope.
	 */
	public function resolveFor(?Site $site, bool $includeSiteRules, ?string $host = null, ?string $clientIp = null): FirewallResolvedPolicy;
}

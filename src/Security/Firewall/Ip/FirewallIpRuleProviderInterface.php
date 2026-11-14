<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Ip;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Supplies additional IP rule specs (for example dynamic bans) to {@see FirewallIpRuleStore}.
 * Implementations must return only normalized, bounded specs.
 */
#[AutoconfigureTag('iikiti.security.firewall_ip_rule_provider')]
interface FirewallIpRuleProviderInterface
{
	/** @return iterable<FirewallIpRuleSpec> */
	public function getRuleSpecs(int|string|null $siteId): iterable;
}

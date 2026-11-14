<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Mapper;

use iikiti\CMS\ApiResource\FirewallIpRuleResource;
use iikiti\CMS\Entity\Object\FirewallIpRule;

/** Maps a managed IP rule entity to its API representation. */
final class FirewallIpRuleMapper
{
	public function toResource(FirewallIpRule $rule): FirewallIpRuleResource
	{
		return new FirewallIpRuleResource(
			id: $rule->getId(),
			siteId: $rule->getSiteId(),
			cidr: $rule->getCidr(),
			ipVersion: 'IPv'.$rule->getIpVersion(),
			action: $rule->getAction(),
			inheritance: $rule->getInheritance(),
			reason: $rule->getReason(),
			enabled: $rule->isEnabled(),
			expiresAt: $rule->getExpiresAt()?->format(\DateTimeInterface::ATOM),
			priority: $rule->getPriority(),
			createdAt: $rule->getCreatedDate()?->format(\DateTimeInterface::ATOM),
		);
	}
}

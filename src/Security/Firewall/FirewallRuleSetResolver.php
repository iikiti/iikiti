<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use iikiti\CMS\Entity\Object\Site;

final readonly class FirewallRuleSetResolver
{
	public function __construct(
		private CoreFirewallRules $coreRules,
		private FirewallRulePackStore $packs,
		private FirewallSitePolicyStore $sitePolicies,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function getRulesForSite(Site $site, bool $includeSiteRules = true): array
	{
		$rules = array_merge($this->coreRules->getRules(), $this->packs->getActiveRules());
		foreach ($includeSiteRules ? $this->sitePolicies->getRules($site) : [] as $rule) {
			if (!is_string($rule['id'] ?? null)) {
				throw new \InvalidArgumentException('Stored site firewall policy is malformed.');
			}
			$rule['id'] = sprintf('site.%s:%s', (string) $site->getId(), $rule['id']);
			$rules[] = $rule;
		}

		return $rules;
	}
}

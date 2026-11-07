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
		private FirewallRuleValidator $validator,
		private FirewallConditionRegistry $conditions,
		private FirewallRuleSetCache $cache,
	) {
	}

	public function getRulesForSite(Site $site, bool $includeSiteRules = true): FirewallCompiledRuleSet
	{
		$siteId = $site->getId();

		return $this->cache->get($siteId, $includeSiteRules, function () use ($site, $siteId, $includeSiteRules): FirewallCompiledRuleSet {
			$rules = array_merge($this->coreRules->getRules(), $this->packs->getActiveRules());
			foreach ($includeSiteRules ? $this->sitePolicies->getRules($site) : [] as $rule) {
				if (!is_string($rule['id'] ?? null)) {
					throw new \InvalidArgumentException('Stored site firewall policy is malformed.');
				}
				$rule['id'] = sprintf('site.%s:%s', (string) $siteId, $rule['id']);
				$rules[] = $rule;
			}

			return new FirewallCompiledRuleSet($this->validator->validateRules($rules), $this->conditions);
		});
	}
}

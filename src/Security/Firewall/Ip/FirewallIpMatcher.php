<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Ip;

use iikiti\CMS\Security\Firewall\FirewallAction;
use iikiti\CMS\Security\Firewall\FirewallFinding;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Compiled IP/CIDR rule index evaluated in strict precedence order:
 * global required, then site, then global default.
 */
final class FirewallIpMatcher
{
	private const TIER_GLOBAL_REQUIRED = 'global_required';
	private const TIER_SITE = 'site';
	private const TIER_GLOBAL_DEFAULT = 'global_default';

	/** @var array<string,array{exactByIp:array<string,list<FirewallIpRuleSpec>>,networks:list<FirewallIpRuleSpec>}> */
	private array $tiers = [];

	/** @param list<FirewallIpRuleSpec> $rules */
	public function __construct(array $rules = [])
	{
		$grouped = [
			self::TIER_GLOBAL_REQUIRED => [],
			self::TIER_SITE => [],
			self::TIER_GLOBAL_DEFAULT => [],
		];
		foreach ($rules as $rule) {
			if (!$rule->enabled) {
				continue;
			}
			$grouped[self::tierFor($rule)][] = $rule;
		}

		foreach ($grouped as $tier => $tierRules) {
			$this->tiers[$tier] = self::indexTier($tierRules);
		}
	}

	public function match(string $clientIp, ?int $now = null): FirewallIpMatchResult
	{
		$normalizedIp = IpCidrNormalizer::normalizeIp($clientIp);
		if (null === $normalizedIp) {
			return new FirewallIpMatchResult('none', null, []);
		}

		$findings = [];
		foreach ([self::TIER_GLOBAL_REQUIRED, self::TIER_SITE, self::TIER_GLOBAL_DEFAULT] as $tier) {
			foreach ($this->matchingRulesInTier($tier, $normalizedIp, $now) as $rule) {
				$finding = self::findingFor($rule);
				$findings[] = $finding;

				if ('report' === $rule->action) {
					continue;
				}

				return new FirewallIpMatchResult($rule->action, $rule, $findings);
			}
		}

		return new FirewallIpMatchResult($findings !== [] ? 'report' : 'none', null, $findings);
	}

	/**
	 * @param list<FirewallIpRuleSpec> $rules
	 *
	 * @return array{exactByIp:array<string,list<FirewallIpRuleSpec>>,networks:list<FirewallIpRuleSpec>}
	 */
	private static function indexTier(array $rules): array
	{
		$exactByIp = [];
		$networks = [];
		foreach ($rules as $rule) {
			$parsed = IpCidrNormalizer::normalize($rule->cidr);
			if (self::isHostRule($parsed['version'], $parsed['prefix'])) {
				$exactByIp[$parsed['ip']][] = $rule;
				continue;
			}
			$networks[] = $rule;
		}

		self::sortByPriority($networks);
		foreach ($exactByIp as &$bucket) {
			self::sortByPriority($bucket);
		}

		return ['exactByIp' => $exactByIp, 'networks' => $networks];
	}

	/** @return list<FirewallIpRuleSpec> */
	private function matchingRulesInTier(string $tier, string $normalizedIp, ?int $now): array
	{
		$index = $this->tiers[$tier];
		$matches = $index['exactByIp'][$normalizedIp] ?? [];
		foreach ($index['networks'] as $rule) {
			if (IpUtils::checkIp($normalizedIp, $rule->cidr)) {
				$matches[] = $rule;
			}
		}

		$active = array_values(array_filter(
			$matches,
			static fn (FirewallIpRuleSpec $rule): bool => !$rule->isExpired($now),
		));
		self::sortByPriority($active);

		return $active;
	}

	private static function tierFor(FirewallIpRuleSpec $rule): string
	{
		if ('site' === $rule->scope) {
			return self::TIER_SITE;
		}

		return 'required' === $rule->inheritance ? self::TIER_GLOBAL_REQUIRED : self::TIER_GLOBAL_DEFAULT;
	}

	private static function isHostRule(int $version, int $prefix): bool
	{
		return (4 === $version && 32 === $prefix) || (6 === $version && 128 === $prefix);
	}

	/** @param list<FirewallIpRuleSpec> $rules */
	private static function sortByPriority(array &$rules): void
	{
		usort($rules, static fn (FirewallIpRuleSpec $a, FirewallIpRuleSpec $b): int => $a->priority <=> $b->priority);
	}

	private static function findingFor(FirewallIpRuleSpec $rule): FirewallFinding
	{
		$action = 'block' === $rule->action ? FirewallAction::Deny : FirewallAction::Report;
		$message = '' !== $rule->reason ? $rule->reason : sprintf('IP rule matched (%s).', $rule->action);

		return new FirewallFinding(
			$rule->id,
			$message,
			$action,
			$rule->priority,
			[['condition' => 'ip.cidr', 'target' => 'ip', 'operator' => 'cidr']],
		);
	}
}

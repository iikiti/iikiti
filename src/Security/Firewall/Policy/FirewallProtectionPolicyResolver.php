<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Policy;

/**
 * Merges core defaults, Application policy and Site policy into the effective protection policy.
 *
 * Rate limits: Application `required` rules are enforced and cannot be disabled or loosened by
 * Site rules; Application `default` rules are overridable by id from Site rules; Site adds rules.
 * DoS and bot settings: Application `required` > Site > Application `default` > core defaults.
 */
final class FirewallProtectionPolicyResolver
{
	/** Key under which validated DoS/bot blocks carry the Application-required setting names. */
	public const REQUIRED_KEYS = '_required';

	/**
	 * @param array{rateLimits:list<array<string,mixed>>,dos:array<string,mixed>,bots:array<string,mixed>}|null $applicationPolicy
	 * @param array{rateLimits:list<array<string,mixed>>,dos:array<string,mixed>,bots:array<string,mixed>}|null $sitePolicy
	 *
	 * @return array{rateLimits:list<array<string,mixed>>,dos:array<string,mixed>,bots:array<string,mixed>}
	 */
	public function resolve(?array $applicationPolicy, ?array $sitePolicy): array
	{
		$appRules = $applicationPolicy['rateLimits'] ?? [];

		return [
			'rateLimits' => $this->mergeRateLimits($appRules, $sitePolicy['rateLimits'] ?? []),
			'dos' => $this->mergeSettings(
				CoreProtectionPolicyDefaults::dos(),
				$applicationPolicy['dos'] ?? null,
				$sitePolicy['dos'] ?? null,
			),
			'bots' => $this->mergeSettings(
				CoreProtectionPolicyDefaults::bots(),
				$applicationPolicy['bots'] ?? null,
				$sitePolicy['bots'] ?? null,
			),
		];
	}

	/**
	 * @param list<array<string,mixed>> $applicationRules
	 * @param list<array<string,mixed>> $siteRules
	 *
	 * @return list<array<string,mixed>>
	 */
	private function mergeRateLimits(array $applicationRules, array $siteRules): array
	{
		$defaults = [...CoreProtectionPolicyDefaults::rateLimits(), ...$applicationRules];
		$required = [];
		$effective = [];
		foreach ($defaults as $rule) {
			if ('required' === ($rule['inheritance'] ?? 'default')) {
				$required[$rule['id']] = $rule;
				continue;
			}
			$effective[$rule['id']] = $rule;
		}

		foreach ($siteRules as $rule) {
			$id = (string) $rule['id'];
			if (isset($required[$id])) {
				continue;
			}
			$effective[$id] = $rule;
		}

		$merged = [...array_values($required), ...array_values($effective)];
		usort($merged, static fn (array $a, array $b): int => ($a['priority'] <=> $b['priority']) ?: strcmp((string) $a['id'], (string) $b['id']));

		return array_values(array_filter($merged, static fn (array $rule): bool => (bool) ($rule['enabled'] ?? true)));
	}

	/**
	 * Precedence per setting key: Application `required` > Site > Application `default` > core.
	 *
	 * @param array<string,mixed>      $core
	 * @param array<string,mixed>|null $application
	 * @param array<string,mixed>|null $site
	 *
	 * @return array<string,mixed>
	 */
	private function mergeSettings(array $core, ?array $application, ?array $site): array
	{
		$requiredKeys = $application[self::REQUIRED_KEYS] ?? [];
		$applicationDefaults = array_diff_key($application ?? [], [self::REQUIRED_KEYS => true]);
		$requiredValues = array_intersect_key($applicationDefaults, array_flip($requiredKeys));
		$defaultValues = array_diff_key($applicationDefaults, $requiredValues);
		$siteValues = array_diff_key($site ?? [], [self::REQUIRED_KEYS => true]);

		// Site overrides Application default; Application required overrides everything below it.
		$resolved = array_replace($core, $defaultValues, $siteValues, $requiredValues);
		unset($resolved[self::REQUIRED_KEYS]);

		return $resolved;
	}
}

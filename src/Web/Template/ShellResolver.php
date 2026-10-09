<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Selects which shells (header, footer, sidebars, dialogs) render for a request.
 *
 * Shells are global, not per-template. Each shell carries `display_rules`
 * (`[{rule, config}, ...]`) evaluated with the same `iikiti.cms.template_rule`
 * registry that templates use, so plugin-registered rules work for shells too.
 * A shell is shown when it is enabled and at least one of its display rules
 * matches. Every matching shell renders, ordered by ascending `priority`
 * (ties keep stored order).
 */
final class ShellResolver
{
	/**
	 * @param iterable<TemplateRuleInterface> $rules
	 */
	public function __construct(
		#[AutowireIterator('iikiti.cms.template_rule')]
		private readonly iterable $rules = [],
	) {
	}

	/**
	 * @param list<array<string,mixed>> $shells Shells for one role, as stored
	 *
	 * @return list<array<string,mixed>> Matching shells in render order
	 */
	public function resolve(array $shells, TemplateResolutionContext $context): array
	{
		$matching = [];
		foreach ($shells as $index => $shell) {
			if (false === ($shell['enabled'] ?? true)) {
				continue;
			}
			if (!$this->matchesAnyRule($shell, $context)) {
				continue;
			}
			$matching[] = ['index' => $index, 'priority' => (int) ($shell['priority'] ?? 0), 'shell' => $shell];
		}

		usort($matching, static fn (array $a, array $b): int => [$a['priority'], $a['index']] <=> [$b['priority'], $b['index']]);

		return array_map(static fn (array $entry): array => $entry['shell'], $matching);
	}

	/**
	 * @param array<string,mixed> $shell
	 */
	private function matchesAnyRule(array $shell, TemplateResolutionContext $context): bool
	{
		$displayRules = is_array($shell['display_rules'] ?? null) ? $shell['display_rules'] : [];

		foreach ($displayRules as $displayRule) {
			$ruleName = (string) ($displayRule['rule'] ?? '');
			$config = is_array($displayRule['config'] ?? null) ? $displayRule['config'] : [];

			foreach ($this->rules as $rule) {
				if ($rule->getName() === $ruleName && $rule->matches($config, $context)) {
					return true;
				}
			}
		}

		return false;
	}
}

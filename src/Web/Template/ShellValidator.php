<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template;

use iikiti\CMS\Entity\Object\Shell;
use iikiti\CMS\Web\BlockEditor\Render\RootContainerRule;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Validates a shell before save: a known role, container-only root blocks (the
 * same rule templates follow), and display rules that name a registered rule.
 */
final class ShellValidator
{
	/**
	 * @param iterable<TemplateRuleInterface> $rules
	 */
	public function __construct(
		#[AutowireIterator('iikiti.cms.template_rule')]
		private readonly iterable $rules,
	) {
	}

	/**
	 * @param list<array<string,mixed>> $blocks
	 * @param list<array<string,mixed>> $displayRules
	 *
	 * @return list<string> human-readable errors; empty when valid
	 */
	public function validate(string $role, array $blocks, array $displayRules): array
	{
		$errors = [];

		if (!in_array($role, Shell::ROLES, true)) {
			$errors[] = sprintf('Unknown shell role "%s".', $role);
		}

		foreach (RootContainerRule::violations(['shell' => $blocks]) as $violation) {
			$errors[] = sprintf('Root block "%s" must be a container.', $violation['type']);
		}

		$known = [];
		foreach ($this->rules as $rule) {
			$known[$rule->getName()] = true;
		}
		foreach ($displayRules as $displayRule) {
			$name = (string) ($displayRule['rule'] ?? '');
			if (!isset($known[$name])) {
				$errors[] = sprintf('Display rule "%s" is not registered.', $name);
			}
		}

		return $errors;
	}
}

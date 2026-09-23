<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template;

use iikiti\CMS\Entity\Object\Template;

/**
 * A single assignment-rule type (e.g. "this template applies to all `Page` objects").
 *
 * Plugins add new rule types by implementing this interface and tagging the service
 * with `iikiti.cms.template_rule` (auto-tagged via the interface). Rules are matched
 * against the {@see TemplateResolutionContext}; the matching template's assignments
 * carry a `priority` for conflict resolution (highest wins).
 */
interface TemplateRuleInterface
{
	/**
	 * Rule type id, e.g. `object_type`, `object`, `site`.
	 */
	public function getName(): string;

	/**
	 * Whether a rule of this type, configured with `$config`, applies to the
	 * given resolution context.
	 *
	 * @param array<string,mixed> $config The rule's configuration stored on the template
	 */
	public function matches(array $config, TemplateResolutionContext $context): bool;
}

<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template\Rule;

use iikiti\CMS\Web\Template\TemplateResolutionContext;
use iikiti\CMS\Web\Template\TemplateRuleInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Matches objects by their class/FQCN (or short type name), e.g.
 * `{ "rule": "object_type", "config": { "type": "Page" } }`.
 */
#[AutoconfigureTag('iikiti.cms.template_rule')]
final class ObjectTypeRule implements TemplateRuleInterface
{
	public function getName(): string
	{
		return 'object_type';
	}

	#[\Override]
	public function matches(array $config, TemplateResolutionContext $context): bool
	{
		$expected = (string) ($config['type'] ?? '');
		if ('' === $expected) {
			return false;
		}

		$fqcn = $context->objectFqcn();
		if (null === $fqcn) {
			return $context->objectType === $expected;
		}

		if ($fqcn === $expected || str_ends_with($fqcn, '\\'.$expected)) {
			return true;
		}

		return $context->objectType === $expected;
	}
}

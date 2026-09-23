<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template\Rule;

use iikiti\CMS\Web\Template\TemplateResolutionContext;
use iikiti\CMS\Web\Template\TemplateRuleInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Matches a single object by id, e.g.
 * `{ "rule": "object", "config": { "object_id": 42 } }`.
 */
#[AutoconfigureTag('iikiti.cms.template_rule')]
final class ObjectRule implements TemplateRuleInterface
{
	public function getName(): string
	{
		return 'object';
	}

	#[\Override]
	public function matches(array $config, TemplateResolutionContext $context): bool
	{
		$objectId = $config['object_id'] ?? null;

		return null !== $context->object &&
			null !== $objectId &&
			(string) $context->object->getId() === (string) $objectId;
	}
}

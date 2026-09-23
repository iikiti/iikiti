<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template\Rule;

use iikiti\CMS\Web\Template\TemplateResolutionContext;
use iikiti\CMS\Web\Template\TemplateRuleInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Matches a whole site, e.g.
 * `{ "rule": "site", "config": { "site_id": 1 } }`.
 */
#[AutoconfigureTag('iikiti.cms.template_rule')]
final class SiteRule implements TemplateRuleInterface
{
	public function getName(): string
	{
		return 'site';
	}

	#[\Override]
	public function matches(array $config, TemplateResolutionContext $context): bool
	{
		$siteId = $config['site_id'] ?? null;

		return null !== $context->site &&
			null !== $siteId &&
			(int) $context->site->getId() === (int) $siteId;
	}
}

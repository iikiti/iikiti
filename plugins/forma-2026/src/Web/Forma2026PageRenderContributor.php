<?php

declare(strict_types=1);

namespace iikiti\Extension\Forma2026\Web;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Web\BlockEditor\PageRenderContribution;
use iikiti\CMS\Web\BlockEditor\PageRenderContributorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Adds Forma-2026 theme body classes when a page renders with the Forma theme active.
 */
final class Forma2026PageRenderContributor implements PageRenderContributorInterface
{
	public function __construct(
		private readonly ?PluginActivationChecker $activationChecker = null,
	) {
	}

	public function contribute(
		Request $request,
		?Site $site,
		?DbObject $object,
		Template $template,
		bool $editorMode,
		array $state,
	): ?PageRenderContribution {
		if (null !== $this->activationChecker && null !== $site && !$this->activationChecker->isActiveForSite('forma-2026', $site)) {
			return null;
		}

		if (!in_array($template->getLayout(), [Template::DEFAULT_LAYOUT, Template::LEGACY_DEFAULT_LAYOUT], true)) {
			return null;
		}

		return new PageRenderContribution(bodyClasses: ['forma-theme']);
	}
}

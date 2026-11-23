<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\Web;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Web\BlockEditor\PageRenderContribution;
use iikiti\CMS\Web\BlockEditor\PageRenderContributorInterface;
use iikiti\CMS\Web\Fragment\FragmentContext;
use iikiti\CMS\Web\Fragment\FragmentRuntime;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contributes the consent banner fragment to rendered public pages when active.
 */
final class ConsentPageRenderContributor implements PageRenderContributorInterface
{
	public function __construct(
		private readonly ?FragmentRuntime $fragmentRuntime = null,
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
		if (
			$editorMode
			|| !$site instanceof Site
			|| null === $this->fragmentRuntime
			|| !$this->fragmentRuntime->isAvailable('consent-management.banner', $site)
		) {
			return null;
		}

		$html = $this->fragmentRuntime->render(
			'consent-management.banner',
			new FragmentContext($site, $request->getLocale()),
		)->html;

		if ('' === $html) {
			return null;
		}

		return new PageRenderContribution(
			bodyBefore: [$html],
			templateVars: ['iikiti_consent_enabled' => true],
		);
	}
}

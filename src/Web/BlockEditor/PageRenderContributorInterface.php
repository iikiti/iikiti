<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\Template;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Allows plugins to contribute `<head>` tags, JSON data blocks, body classes,
 * and `<body>` fragments to rendered pages without hardcoding plugin code into
 * {@see PageRendering} or `templates/base/layout.twig`.
 */
#[AutoconfigureTag('iikiti.cms.page_render_contributor')]
interface PageRenderContributorInterface
{
	/**
	 * @param array<string,mixed> $state Prepared page-rendering state
	 */
	public function contribute(
		Request $request,
		?Site $site,
		?DbObject $object,
		Template $template,
		bool $editorMode,
		array $state,
	): ?PageRenderContribution;
}

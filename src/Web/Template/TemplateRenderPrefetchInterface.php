<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template;

use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Allows plugins (such as media/social embed resolvers) to prefetch async
 * data concurrently while {@see TemplateRenderer} renders region trees and shells.
 */
#[AutoconfigureTag('iikiti.cms.template_render_prefetch')]
interface TemplateRenderPrefetchInterface
{
	/**
	 * Execute `$render` concurrently with any prefetched async work for
	 * `$regionTrees` and `$shells`, returning an updated render context.
	 *
	 * @param array<string,list<array<string,mixed>>> $regionTrees
	 * @param array<string,list<array<string,mixed>>> $shells
	 * @param callable(BlockRenderContext): mixed       $render
	 */
	public function prefetchDuringRender(
		array $regionTrees,
		array $shells,
		BlockRenderContext $context,
		FiberTaskRunner $runner,
		callable $render,
		?HtmlFragmentComposer $composer = null,
	): BlockRenderContext;
}

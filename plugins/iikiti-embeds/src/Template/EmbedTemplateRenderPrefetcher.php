<?php

declare(strict_types=1);

namespace iikiti\Extension\Embeds\Template;

use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\Template\TemplateRenderPrefetchInterface;
use iikiti\Extension\Embeds\Embed\EmbedResolver;

/**
 * Prefetches allowlisted social oEmbed requests concurrently while the template
 * renderer evaluates local regions and shells.
 */
final class EmbedTemplateRenderPrefetcher implements TemplateRenderPrefetchInterface
{
	public function __construct(
		private readonly EmbedResolver $embedResolver,
		private readonly ?PluginActivationChecker $activationChecker = null,
	) {
	}

	#[\Override]
	public function prefetchDuringRender(
		array $regionTrees,
		array $shells,
		BlockRenderContext $context,
		FiberTaskRunner $runner,
		callable $render,
		?HtmlFragmentComposer $composer = null,
	): BlockRenderContext {
		if (null !== $this->activationChecker && !$this->activationChecker->isActive('iikiti-embeds')) {
			$render($context);

			return $context;
		}

		$urls = $this->embedUrls($regionTrees, $context, $shells);
		if ([] === $urls) {
			$render($context);

			return $context;
		}

		if (null !== $composer) {
			$updatedContext = $context;
			$deferred = $this->embedResolver->renderWhilePrefetchingOembeds(
				$urls,
				$runner,
				function (array $embedValues) use ($context, $render, &$updatedContext): void {
					$updatedContext = $context->withPrefetchedEmbeds($context->prefetchedEmbeds + $embedValues);
					$render($updatedContext);
				},
			);
			$composer->replaceDeferredEmbeds($deferred);

			return $updatedContext;
		}

		$embedValues = $this->embedResolver->prefetchOembeds($urls, $runner);
		$updatedContext = $context->withPrefetchedEmbeds($context->prefetchedEmbeds + $embedValues);
		$render($updatedContext);

		return $updatedContext;
	}

	/**
	 * @param array<string,list<array<string,mixed>>> $regionTrees
	 * @param array<string,list<array<string,mixed>>> $shells
	 * @return list<string>
	 */
	private function embedUrls(array $regionTrees, BlockRenderContext $context, array $shells): array
	{
		$urls = [];
		foreach ([$regionTrees, $context->dynamicBlocks, $shells] as $tree) {
			$this->collectEmbedUrls($tree, $urls);
		}

		return array_values(array_unique($urls));
	}

	/**
	 * @param array<string,mixed>|list<mixed> $node
	 * @param list<string>                    $urls
	 * @param-out list<string>                $urls
	 */
	private function collectEmbedUrls(array $node, array &$urls): void
	{
		if ('social_embed' === ($node['type'] ?? null)) {
			$url = $node['content']['url'] ?? null;
			if (is_string($url) && '' !== trim($url)) {
				$urls[] = $url;
			}
		}

		foreach ($node as $value) {
			if (is_array($value)) {
				$this->collectEmbedUrls($value, $urls);
			}
		}
	}
}

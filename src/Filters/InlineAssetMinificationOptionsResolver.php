<?php

declare(strict_types=1);

namespace iikiti\CMS\Filters;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class InlineAssetMinificationOptionsResolver
{
	public function __construct(
		private EventDispatcherInterface $eventDispatcher,
		private bool $defaultCssMinify,
		private bool $defaultJsMinify,
		private bool $defaultJsUglify,
	) {
	}

	public function resolveForRequest(Request $request): InlineAssetMinificationOptions
	{
		$existing = $request->attributes->get(InlineAssetMinificationOptions::REQUEST_ATTRIBUTE);
		if ($existing instanceof InlineAssetMinificationOptions) {
			return $existing;
		}
		$options = InlineAssetMinificationOptions::fromArray($existing);
		if (null !== $options) {
			$request->attributes->set(InlineAssetMinificationOptions::REQUEST_ATTRIBUTE, $options);

			return $options;
		}

		$site = SiteRegistry::isInitialized() && SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null;

		return $this->resolve($request, $site);
	}

	public function resolve(Request $request, ?Site $site): InlineAssetMinificationOptions
	{
		$configuration = null === $site ? null : $site->getConfiguration();
		$event = new InlineAssetMinificationOptionsEvent(
			$request,
			$site,
			$this->siteBoolean($configuration?->get('inline_css_minify'), $this->defaultCssMinify),
			$this->siteBoolean($configuration?->get('inline_js_minify'), $this->defaultJsMinify),
			$this->siteBoolean($configuration?->get('inline_js_uglify'), $this->defaultJsUglify),
		);
		$this->eventDispatcher->dispatch($event, InlineAssetMinificationOptionsEvent::NAME);
		$options = $event->options();
		$request->attributes->set(InlineAssetMinificationOptions::REQUEST_ATTRIBUTE, $options);

		return $options;
	}

	private function siteBoolean(mixed $configuredValue, bool $default): bool
	{
		return is_bool($configuredValue) ? $configuredValue : $default;
	}
}

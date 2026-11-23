<?php

declare(strict_types=1);

namespace iikiti\CMS\Filters;

use iikiti\CMS\Registry\SiteRegistry;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSOpaqueRule;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSRenderMode;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSRenderer;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSRenderOptions;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSRule;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSStyleSheet;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class InlineCssFilter extends AbstractFilter
{
	private const string CACHE_PREFIX = 'inline_css_min_v2_';
	private const int CACHE_TTL_SECONDS = 86_400;
	private const int MAX_CACHEABLE_BYTES = 262_144;

	public function __construct(
		private readonly InlineAssetMinificationOptionsResolver $options,
		#[Autowire(service: 'cache.app')]
		private readonly ?CacheItemPoolInterface $cache = null,
	) {
	}

	public function filter(ResponseEvent $event): void
	{
		$request = $event->getRequest();
		if (!$event->isMainRequest()
			|| !self::isHtmlResponseType($request, $event->getResponse())
			|| !$this->options->resolveForRequest($request)->cssMinify) {
			return;
		}

		$document = self::getHtmlDocument($event);
		$changed = false;
		foreach ($document->querySelectorAll('style') as $style) {
			$source = $style->textContent;
			if ('' === $source || InlineAssetMinificationHeuristic::containsPreservedCssComment($source)) {
				continue;
			}

			$minified = $this->minifyCached($source);
			if (null !== $minified && strlen($minified) < strlen($source)) {
				$style->textContent = $minified;
				$changed = true;
			}
		}

		if ($changed) {
			self::saveHtmlDocument($event, $document);
		}
	}

	private function minifyCached(string $source): ?string
	{
		$canCache = null !== $this->cache && strlen($source) <= self::MAX_CACHEABLE_BYTES;
		$cacheItem = null;
		if ($canCache) {
			try {
				$siteId = SiteRegistry::isInitialized() && SiteRegistry::hasCurrent()
					? (string) SiteRegistry::getCurrent()->getId()
					: '0';
				$cacheItem = $this->cache->getItem(self::CACHE_PREFIX.$siteId.'_'.hash('sha256', $source));
				if ($cacheItem->isHit()) {
					$cached = $cacheItem->get();
					if (is_array($cached) && is_bool($cached['changed'] ?? null)) {
						return $cached['changed']
							&& is_string($cached['output'] ?? null)
							&& self::isSafeInlineStyle($cached['output'])
							? $cached['output']
							: null;
					}
				}
			} catch (\Throwable) {
				$cacheItem = null;
			}
		}

		$minified = $this->minifySource($source);
		$result = null !== $minified
			&& strlen($minified) < strlen($source)
			&& self::isSafeInlineStyle($minified)
			? $minified
			: null;

		if (null !== $cacheItem) {
			try {
				$cacheItem->set(null !== $result
					? ['changed' => true, 'output' => $result]
					: ['changed' => false]);
				$cacheItem->expiresAfter(self::CACHE_TTL_SECONDS);
				$this->cache->save($cacheItem);
			} catch (\Throwable) {
				// Cache writes are best-effort; minified output remains usable.
			}
		}

		return $result;
	}

	private static function isSafeInlineStyle(string $css): bool
	{
		return 1 !== preg_match('/<\/style/i', $css);
	}

	private function minifySource(string $source): ?string
	{
		try {
			$stylesheet = CSSStyleSheet::parse($source);
			if ([] !== $stylesheet->getParseErrors()) {
				return null;
			}

			return CSSRenderer::render($stylesheet, new CSSRenderOptions(
				mode: CSSRenderMode::Minified,
				keepComments: false,
				ruleCallback: static function (CSSRule $rule, string $text): string {
					if ($rule instanceof CSSOpaqueRule) {
						return str_replace(["\r\n", "\r", "\n"], '', $text);
					}

					return $text;
				},
			));
		} catch (\Throwable) {
			return null;
		}
	}
}

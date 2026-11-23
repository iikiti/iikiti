<?php

declare(strict_types=1);

namespace iikiti\CMS\Filters;

use iikiti\CMS\Registry\SiteRegistry;
use MatthiasMullie\Minify\JS;
use Peast\Peast;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class InlineJavaScriptFilter extends AbstractFilter
{
	private const string CACHE_PREFIX = 'inline_js_min_v2_';
	private const int CACHE_TTL_SECONDS = 86_400;
	private const int MAX_CACHEABLE_BYTES = 262_144;

	private const array SCRIPT_TYPES = [
		'application/ecmascript',
		'application/javascript',
		'text/ecmascript',
		'text/javascript',
	];

	public function __construct(
		private readonly InlineAssetMinificationOptionsResolver $options,
		private readonly TerserUglifier $uglifier,
		#[Autowire(service: 'cache.app')]
		private readonly ?CacheItemPoolInterface $cache = null,
	) {
	}

	public function filter(ResponseEvent $event): void
	{
		$request = $event->getRequest();
		if (!$event->isMainRequest()
			|| !self::isHtmlResponseType($request, $event->getResponse())) {
			return;
		}
		$options = $this->options->resolveForRequest($request);
		if (!$options->jsMinify) {
			return;
		}

		$document = self::getHtmlDocument($event);
		$changed = false;
		/** @var list<array{element:\Dom\Element,source:string,minified:string,cacheItem:?CacheItemInterface}> $pendingScripts */
		$pendingScripts = [];
		foreach ($document->querySelectorAll('script') as $script) {
			$rawType = $script->getAttribute('type');
			$normalizedType = is_string($rawType) ? strtolower(trim($rawType)) : '';
			if ($script->hasAttribute('src') || !self::isJavaScriptType($normalizedType)) {
				continue;
			}
			$source = $script->textContent;
			if ('' === $source || InlineAssetMinificationHeuristic::isAlreadyMinified($source)) {
				continue;
			}

			$isModule = 'module' === $normalizedType;
			$cacheItem = $this->readCacheItem($source, $isModule, $options->jsUglify);
			if (null !== $cacheItem && $cacheItem->isHit()) {
				$cached = $cacheItem->get();
				if (is_array($cached) && is_bool($cached['changed'] ?? null)) {
					if ($cached['changed']
						&& is_string($cached['output'] ?? null)
						&& strlen($cached['output']) < strlen($source)
						&& self::isSafeInlineScript($cached['output'])) {
						$script->textContent = $cached['output'];
						$changed = true;
					}
					continue;
				}
			}

			try {
				Peast::latest($source, [
					'sourceType' => $isModule
						? Peast::SOURCE_TYPE_MODULE
						: Peast::SOURCE_TYPE_SCRIPT,
				])->parse();
				$minified = (new JS($source))->minify();
			} catch (\Throwable) {
				$this->writeCacheItem($cacheItem, null);
				continue;
			}
			if (strlen($minified) < strlen($source) && self::isSafeInlineScript($minified)) {
				$pendingScripts[] = [
					'element' => $script,
					'source' => $source,
					'minified' => $minified,
					'cacheItem' => $cacheItem,
				];
			} else {
				$this->writeCacheItem($cacheItem, null);
			}
		}

		$uglifyFailed = false;
		if ($options->jsUglify && [] !== $pendingScripts) {
			try {
				$uglifiedScripts = $this->uglifier->minifyAll(array_column($pendingScripts, 'minified'));
				foreach ($uglifiedScripts as $index => $uglified) {
					if (strlen($uglified) < strlen($pendingScripts[$index]['minified'])
						&& self::isSafeInlineScript($uglified)) {
						$pendingScripts[$index]['minified'] = $uglified;
					}
				}
			} catch (\Throwable) {
				// Keep the whitespace-minified fallback usable, but do not poison
				// the jsUglify=true cache key with non-uglified output.
				$uglifyFailed = true;
			}
		}

		foreach ($pendingScripts as $pendingScript) {
			$improved = strlen($pendingScript['minified']) < strlen($pendingScript['source'])
				&& self::isSafeInlineScript($pendingScript['minified']);
			if (!$uglifyFailed) {
				$this->writeCacheItem($pendingScript['cacheItem'], $improved ? $pendingScript['minified'] : null);
			}
			if (!$improved) {
				continue;
			}
			$pendingScript['element']->textContent = $pendingScript['minified'];
			$changed = true;
		}

		if ($changed) {
			self::saveHtmlDocument($event, $document);
		}
	}

	private function readCacheItem(string $source, bool $isModule, bool $jsUglify): ?CacheItemInterface
	{
		if (null === $this->cache || strlen($source) > self::MAX_CACHEABLE_BYTES) {
			return null;
		}

		try {
			$siteId = SiteRegistry::isInitialized() && SiteRegistry::hasCurrent()
				? (string) SiteRegistry::getCurrent()->getId()
				: '0';
			$key = self::CACHE_PREFIX
				.$siteId.'_'
				.($isModule ? 'm_' : 's_')
				.($jsUglify ? 'u_' : 'n_')
				.hash('sha256', $source);

			return $this->cache->getItem($key);
		} catch (\Throwable) {
			return null;
		}
	}

	private static function isSafeInlineScript(string $script): bool
	{
		return 1 !== preg_match('/<\/script|<!--/i', $script);
	}

	private function writeCacheItem(?CacheItemInterface $item, ?string $output): void
	{
		if (null === $item || null === $this->cache) {
			return;
		}

		try {
			$item->set(null !== $output
				? ['changed' => true, 'output' => $output]
				: ['changed' => false]);
			$item->expiresAfter(self::CACHE_TTL_SECONDS);
			$this->cache->save($item);
		} catch (\Throwable) {
			// Cache writes are best-effort; minified output remains usable.
		}
	}

	private static function isJavaScriptType(string $type): bool
	{
		return '' === $type || 'module' === $type || in_array($type, self::SCRIPT_TYPES, true);
	}
}

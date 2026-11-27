<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Filters;

use Dom\Document;
use Dom\HTMLDocument;
use iikiti\CMS\Event\Subscriber\OutputParser;
use iikiti\CMS\Filters\AbstractFilter;
use iikiti\CMS\Filters\HtmlFilter;
use iikiti\CMS\Filters\InlineAssetMinificationOptions;
use iikiti\CMS\Filters\InlineAssetMinificationOptionsResolver;
use iikiti\CMS\Filters\InlineCssFilter;
use iikiti\CMS\Filters\InlineJavaScriptFilter;
use iikiti\CMS\Filters\ResponseFilterInterface;
use iikiti\CMS\Filters\TerserUglifier;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use Jimbo2150\CssIndexedDom\IndexedHtmlDocument;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HtmlFilterIndexedDomTest extends TestCase
{
	public function testGetHtmlDocumentWrapsDomHtmlResponseWithoutReparsing(): void
	{
		$composer = new HtmlFragmentComposer('<!doctype html><html><body><p id="lead" class="card">Hello</p></body></html>');
		$rawDocument = $composer->document();
		$response = new DomHtmlResponse($rawDocument);
		$event = $this->responseEvent(new Request(), $response);

		$wrapped = ExposedHtmlFilter::callGetHtmlDocument($event);

		self::assertInstanceOf(IndexedHtmlDocument::class, $wrapped);
		self::assertSame($rawDocument, $wrapped->document());
		self::assertTrue($wrapped->isDirty());
		self::assertSame(0, $wrapped->indexStats()->rebuildCount);
		self::assertSame('Hello', $wrapped->querySelector('.card')?->textContent);
		self::assertFalse($wrapped->isDirty());
		self::assertSame(1, $wrapped->indexStats()->rebuildCount);
	}

	public function testLazyIndexRemainsUnbuiltWhenNoSelectorQueriesRunInOutputParser(): void
	{
		$previousHtmlFilter = $_ENV['HTML_FILTER'] ?? null;
		$_ENV['HTML_FILTER'] = '1';

		try {
			$resolver = new InlineAssetMinificationOptionsResolver(new EventDispatcher(), false, false, false);
			$observer = new class extends AbstractFilter implements ResponseFilterInterface {
				public ?bool $dirtyDuringPass = null;
				public ?int $rebuildCountDuringPass = null;

				public function filter(ResponseEvent $event): void
				{
					$document = self::getHtmlDocument($event);
					$this->dirtyDuringPass = $document->isDirty();
					$this->rebuildCountDuringPass = $document->indexStats()->rebuildCount;
				}

				public function getPriority(): int
				{
					return 0;
				}
			};

			$parser = new OutputParser(
				null,
				new InlineCssFilter($resolver),
				new InlineJavaScriptFilter($resolver, new TerserUglifier(dirname(__DIR__, 2))),
				[$observer],
			);

			$composer = new HtmlFragmentComposer(
				'<!doctype html><html><body><!-- remove --><p class="card">Hello    world</p></body></html>',
			);
			$response = new DomHtmlResponse($composer->document());
			$request = $this->requestWithOptions(new InlineAssetMinificationOptions(false, false, false));

			$parser->onKernelResponse($this->responseEvent($request, $response));

			self::assertTrue($observer->dirtyDuringPass);
			self::assertSame(0, $observer->rebuildCountDuringPass);

			$content = (string) $response->getContent();
			self::assertStringNotContainsString('remove', $content);
			self::assertStringContainsString('<p class="card">Hello world</p>', $content);
		} finally {
			if (null === $previousHtmlFilter) {
				unset($_ENV['HTML_FILTER']);
			} else {
				$_ENV['HTML_FILTER'] = $previousHtmlFilter;
			}
		}
	}

	public function testOutputParserSharesSingleIndexAcrossCoreAndPluginFilters(): void
	{
		$previousHtmlFilter = $_ENV['HTML_FILTER'] ?? null;
		$_ENV['HTML_FILTER'] = '1';

		try {
			$cache = new ArrayAdapter();
			$resolver = new InlineAssetMinificationOptionsResolver(new EventDispatcher(), true, true, false);
			$customFilter = new class extends AbstractFilter implements ResponseFilterInterface {
				public ?bool $dirtyAfterQueries = null;
				public ?int $rebuildCountAfterQueries = null;
				public int $enhancedCount = 0;

				public function filter(ResponseEvent $event): void
				{
					$document = self::getHtmlDocument($event);
					foreach ($document->querySelectorAll('.card') as $card) {
						$document->addClass($card, 'enhanced');
						$document->setAttribute($card, 'data-indexed', 'yes');
					}
					$this->enhancedCount = $document->querySelectorAll('.card.enhanced')->count();
					$this->dirtyAfterQueries = $document->isDirty();
					$this->rebuildCountAfterQueries = $document->indexStats()->rebuildCount;
					self::saveHtmlDocument($event, $document);
				}

				public function getPriority(): int
				{
					return 0;
				}
			};

			$parser = new OutputParser(
				null,
				new InlineCssFilter($resolver, $cache),
				new InlineJavaScriptFilter($resolver, new TerserUglifier(dirname(__DIR__, 2)), $cache),
				[$customFilter],
			);

			$composer = new HtmlFragmentComposer(
				'<!doctype html><html><head><style>.card { color: red; }</style><script>'."\n".'function hi(name) { return name; }'."\n".'</script></head><body><!-- strip --><div class="card">Hello    world</div></body></html>',
			);
			$response = new DomHtmlResponse($composer->document());
			$request = $this->requestWithOptions(new InlineAssetMinificationOptions(true, true, false));

			$parser->onKernelResponse($this->responseEvent($request, $response));

			self::assertFalse($customFilter->dirtyAfterQueries);
			self::assertSame(1, $customFilter->rebuildCountAfterQueries);
			self::assertSame(1, $customFilter->enhancedCount);

			$content = (string) $response->getContent();
			self::assertStringNotContainsString('strip', $content);
			self::assertStringContainsString('<div class="card enhanced" data-indexed="yes">Hello world</div>', $content);
			self::assertStringContainsString('.card{color:red;}', $content);
			self::assertStringContainsString('function hi(name){return name}', $content);
		} finally {
			if (null === $previousHtmlFilter) {
				unset($_ENV['HTML_FILTER']);
			} else {
				$_ENV['HTML_FILTER'] = $previousHtmlFilter;
			}
		}
	}

	public function testRawHtmlDocumentCompatibilityInSaveAndMinify(): void
	{
		// Deferred mode: saving the underlying raw HTMLDocument invalidates an existing wrapper index.
		$deferredRequest = new Request();
		$deferredRequest->attributes->set(AbstractFilter::DEFER_HTML_SAVE_ATTRIBUTE, true);
		$response = new Response('<!doctype html><html><body><p class="item">One</p></body></html>', headers: ['Content-Type' => 'text/html']);
		$deferredEvent = $this->responseEvent($deferredRequest, $response);

		$wrapper = ExposedHtmlFilter::callGetHtmlDocument($deferredEvent);
		self::assertSame(1, $wrapper->querySelectorAll('.item')->count());
		self::assertFalse($wrapper->isDirty());

		$raw = $wrapper->document();
		$added = $raw->createElement('span');
		$added->setAttribute('class', 'raw-added');
		$added->textContent = 'Two';
		$raw->body?->appendChild($added);

		ExposedHtmlFilter::callSaveHtmlDocument($deferredEvent, $raw);
		self::assertTrue($wrapper->isDirty());

		$reloadedWrapper = ExposedHtmlFilter::callGetHtmlDocument($deferredEvent);
		self::assertSame($wrapper, $reloadedWrapper);
		self::assertSame('Two', $reloadedWrapper->querySelector('.raw-added')?->textContent);

		AbstractFilter::flushHtmlDocument($deferredEvent);
		self::assertStringContainsString('<span class="raw-added">Two</span>', (string) $response->getContent());

		// Deferred mode when saving a raw HTMLDocument with no prior wrapper cached.
		$freshDeferredRequest = new Request();
		$freshDeferredRequest->attributes->set(AbstractFilter::DEFER_HTML_SAVE_ATTRIBUTE, true);
		$freshResponse = new Response('', headers: ['Content-Type' => 'text/html']);
		$freshEvent = $this->responseEvent($freshDeferredRequest, $freshResponse);
		$freshRaw = HTMLDocument::createFromString('<!doctype html><html><body><p>Fresh</p></body></html>', LIBXML_COMPACT | LIBXML_NOERROR);
		ExposedHtmlFilter::callSaveHtmlDocument($freshEvent, $freshRaw);
		AbstractFilter::flushHtmlDocument($freshEvent);
		self::assertStringContainsString('<p>Fresh</p>', (string) $freshResponse->getContent());

		// Non-deferred mode saving a raw HTMLDocument directly.
		$syncResponse = new Response('', headers: ['Content-Type' => 'text/html']);
		$syncEvent = $this->responseEvent(new Request(), $syncResponse);
		$syncRaw = HTMLDocument::createFromString('<!doctype html><html><body><p>Sync</p></body></html>', LIBXML_COMPACT | LIBXML_NOERROR);
		ExposedHtmlFilter::callSaveHtmlDocument($syncEvent, $syncRaw);
		self::assertStringContainsString('<p>Sync</p>', (string) $syncResponse->getContent());

		// HtmlFilter::minifyHtml() accepts both IndexedHtmlDocument and raw Dom\Document.
		$rawDoc = HTMLDocument::createFromString('<!doctype html><html><body><!-- c --><p>A    B</p></body></html>', LIBXML_COMPACT | LIBXML_NOERROR);
		ExposedHtmlFilter::callMinifyHtml($rawDoc);
		self::assertSame('<!DOCTYPE html><html><head></head><body><p>A B</p></body></html>', $rawDoc->saveHTML($rawDoc));

		$indexedDoc = IndexedHtmlDocument::createFromString('<!doctype html><html><body><!-- c --><p>C    D</p></body></html>', LIBXML_COMPACT | LIBXML_NOERROR, lazyIndex: true);
		ExposedHtmlFilter::callMinifyHtml($indexedDoc);
		self::assertSame('<!DOCTYPE html><html><head></head><body><p>C D</p></body></html>', $indexedDoc->saveHtml());
	}

	public function testComposedDomHtmlResponseSharesSingleIndexThroughOutputParser(): void
	{
		$previousHtmlFilter = $_ENV['HTML_FILTER'] ?? null;
		$_ENV['HTML_FILTER'] = '1';

		try {
			$composer = new HtmlFragmentComposer(
				'<!doctype html><html><head><template data-iikiti-fragment="custom-css"></template></head><body><template data-iikiti-fragment="page"></template></body></html>',
			);
			$composer->insertIntoPlaceholder('template[data-iikiti-fragment="custom-css"]', '<style>.card { color: red; }</style>');
			$composer->insertIntoPlaceholder('template[data-iikiti-fragment="page"]', '<main><!-- remove --><section class="card"><template data-iikiti-async-embed="tok"></template></section></main>');
			$composer->replaceDeferredEmbeds(['tok' => '<script>'."\n".'function greet(n) { return n; }'."\n".'</script><p>Hi    there</p>']);

			$composedIndexed = $composer->indexedDocument();
			$response = new DomHtmlResponse($composedIndexed);
			self::assertSame(1, $composedIndexed->indexStats()->rebuildCount);

			$cache = new ArrayAdapter();
			$resolver = new InlineAssetMinificationOptionsResolver(new EventDispatcher(), true, true, false);
			$pluginFilter = new class extends AbstractFilter implements ResponseFilterInterface {
				public ?IndexedHtmlDocument $seenDocument = null;
				public ?int $rebuildCount = null;

				public function filter(ResponseEvent $event): void
				{
					$document = self::getHtmlDocument($event);
					$this->seenDocument = $document;
					$card = $document->querySelector('.card');
					if (null !== $card) {
						$document->addClass($card, 'filtered');
					}
					$this->rebuildCount = $document->indexStats()->rebuildCount;
					self::saveHtmlDocument($event, $document);
				}

				public function getPriority(): int
				{
					return 0;
				}
			};

			$parser = new OutputParser(
				null,
				new InlineCssFilter($resolver, $cache),
				new InlineJavaScriptFilter($resolver, new TerserUglifier(dirname(__DIR__, 2)), $cache),
				[$pluginFilter],
			);
			$request = $this->requestWithOptions(new InlineAssetMinificationOptions(true, true, false));
			$parser->onKernelResponse($this->responseEvent($request, $response));

			self::assertSame($composedIndexed, $pluginFilter->seenDocument);
			self::assertSame(1, $pluginFilter->rebuildCount);
			self::assertFalse($composedIndexed->isDirty());

			$content = (string) $response->getContent();
			self::assertStringNotContainsString('remove', $content);
			self::assertStringContainsString('<section class="card filtered">', $content);
			self::assertStringContainsString('.card{color:red;}', $content);
			self::assertStringContainsString('function greet(n){return n}', $content);
			self::assertStringContainsString('<p>Hi there</p>', $content);
		} finally {
			if (null === $previousHtmlFilter) {
				unset($_ENV['HTML_FILTER']);
			} else {
				$_ENV['HTML_FILTER'] = $previousHtmlFilter;
			}
		}
	}

	private function requestWithOptions(InlineAssetMinificationOptions $options): Request
	{
		$request = new Request();
		$request->attributes->set(InlineAssetMinificationOptions::REQUEST_ATTRIBUTE, $options);

		return $request;
	}

	private function responseEvent(Request $request, Response $response): ResponseEvent
	{
		return new ResponseEvent(
			$this->createStub(HttpKernelInterface::class),
			$request,
			HttpKernelInterface::MAIN_REQUEST,
			$response,
		);
	}
}

final class ExposedHtmlFilter extends HtmlFilter
{
	public static function callGetHtmlDocument(ResponseEvent $event): IndexedHtmlDocument
	{
		return self::getHtmlDocument($event);
	}

	public static function callSaveHtmlDocument(ResponseEvent $event, IndexedHtmlDocument|HTMLDocument $document): void
	{
		self::saveHtmlDocument($event, $document);
	}

	public static function callMinifyHtml(IndexedHtmlDocument|Document $dom): void
	{
		self::minifyHtml($dom);
	}
}

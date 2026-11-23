<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Filters;

use Jimbo2150\PhpCssTypedOm\CSSOM\CSSRenderMode;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSRenderer;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSRenderOptions;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSStyleRule;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSStyleSheet;
use iikiti\CMS\Event\Subscriber\OutputParser;
use iikiti\CMS\Filters\InlineAssetMinificationOptions;
use iikiti\CMS\Filters\InlineAssetMinificationOptionsResolver;
use iikiti\CMS\Filters\InlineCssFilter;
use iikiti\CMS\Filters\InlineJavaScriptFilter;
use iikiti\CMS\Filters\TerserUglifier;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Stopwatch\Stopwatch;

final class InlineAssetMinificationFilterTest extends TestCase
{
	public function testCssFilterMinifiesStyleBodiesWithoutTouchingAttributes(): void
	{
		$response = new Response('<!doctype html><html><head><style>.card { color: red; margin: 0  1px; }</style></head><body><div style="color: red; margin: 0  1px"></div></body></html>', headers: ['Content-Type' => 'text/html; charset=UTF-8']);
		$request = $this->requestWithOptions(new InlineAssetMinificationOptions(true, false, false));

		$this->cssFilter()->filter($this->responseEvent($request, $response));

		self::assertStringContainsString('.card{color:red;margin:0  1px;}', (string) $response->getContent());
		self::assertStringContainsString('style="color: red; margin: 0  1px"', (string) $response->getContent());
	}

	public function testCssWithNewlinesIsNotTreatedAsAlreadyMinified(): void
	{
		$html = '<!doctype html><html><head><style>' . "\n" . '.card { color: red; }' . "\n" . '</style></head><body></body></html>';
		$response = new Response($html, headers: ['Content-Type' => 'text/html']);

		$this->cssFilter()->filter($this->responseEvent(
			$this->requestWithOptions(new InlineAssetMinificationOptions(true, false, false)),
			$response,
		));

		self::assertStringContainsString('.card{color:red;}', (string) $response->getContent());
	}

	public function testCssRendererMinifiesGroupingRulesAndRetainsOpaqueRules(): void
	{
		$html = '<!doctype html><html><head><style>' . "\n"
			.'@media (min-width: 40rem) {' . "\n"
			.'  .card { color: red; }' . "\n"
			.'}' . "\n"
			.'@custom-unknown foo { bar:   baz; }'
			.'</style></head><body></body></html>';
		$response = new Response($html, headers: ['Content-Type' => 'text/html']);

		$this->cssFilter()->filter($this->responseEvent(
			$this->requestWithOptions(new InlineAssetMinificationOptions(true, false, false)),
			$response,
		));

		$content = (string) $response->getContent();
		self::assertStringContainsString('.card{color:red;}', $content);
		self::assertStringContainsString('@custom-unknown foo', $content);
		self::assertStringContainsString('bar:   baz', $content);
	}

	public function testCssWithParseDiagnosticsRemainsUnchanged(): void
	{
		$html = '<!doctype html><html><head><style>' . "\n" . '.card { color: ; }' . "\n" . '</style></head><body></body></html>';
		$response = new Response($html, headers: ['Content-Type' => 'text/html']);

		$this->cssFilter()->filter($this->responseEvent(
			$this->requestWithOptions(new InlineAssetMinificationOptions(true, false, false)),
			$response,
		));

		self::assertSame($html, $response->getContent());
	}

	public function testCssLicenseAndPreserveCommentsAreRetained(): void
	{
		foreach (['/*! license */', '/* @license project */', '/* @preserve project */'] as $comment) {
			$html = '<!doctype html><html><head><style>'.$comment."\n".'.card { color: red; }</style></head><body></body></html>';
			$response = new Response($html, headers: ['Content-Type' => 'text/html']);

			$this->cssFilter()->filter($this->responseEvent(
				$this->requestWithOptions(new InlineAssetMinificationOptions(true, false, false)),
				$response,
			));

			self::assertSame($html, $response->getContent());
		}
	}

	public function testTypedOmStylesheetsCanBeMutatedAndMinifiedByPlugins(): void
	{
		$stylesheet = CSSStyleSheet::parse('.card { color: red; }');
		$rule = $stylesheet->getCssRules()[0];
		self::assertInstanceOf(CSSStyleRule::class, $rule);
		$rule->getStyle()->setProperty('color', 'blue');

		self::assertSame(
			'.card{color:blue;}',
			CSSRenderer::render($stylesheet, new CSSRenderOptions(CSSRenderMode::Minified)),
		);
	}

	public function testCssFilterLeavesCompactStylesUnchanged(): void
	{
		$html = '<!doctype html><html><head><style>.card{color:red}</style></head><body></body></html>';
		$response = new Response($html, headers: ['Content-Type' => 'text/html']);

		$this->cssFilter()->filter($this->responseEvent(
			$this->requestWithOptions(new InlineAssetMinificationOptions(true, false, false)),
			$response,
		));

		self::assertSame($html, $response->getContent());
	}

	public function testCssFilterMinifiesOpaqueRulesByStrippingNewlines(): void
	{
		$html = '<!doctype html><html><head><style>@custom-unknown foo {' . "\n" . '  bar: baz;' . "\n" . '}</style></head><body></body></html>';
		$response = new Response($html, headers: ['Content-Type' => 'text/html']);

		$this->cssFilter()->filter($this->responseEvent(
			$this->requestWithOptions(new InlineAssetMinificationOptions(true, false, false)),
			$response,
		));

		// Opaque rules retain source verbatim; newlines are stripped so the
		// rendered output is shorter than the formatted source.
		self::assertStringContainsString('@custom-unknown foo {  bar: baz;}', (string) $response->getContent());
	}

	public function testJavaScriptFilterMinifiesOnlyInlineJavaScript(): void
	{
		$html = '<!doctype html><html><head><script>/* inline */' . "\n" . 'function addNumbers(firstValue, secondValue) { return firstValue + secondValue; }</script>'
			.'<script src="/app.js">/* external */' . "\n" . 'function externalSource() { return false; }</script>'
			.'<script type="module">' . "\n" . 'export const moduleValue = await Promise.resolve(1);' . "\n" . '</script>'
			.'<script type="application/ld+json">{' . "\n" . '  "title": "formatted"' . "\n" . '}</script>'
			.'<script type="importmap">{ "imports": { "app": "/app.js" } }</script></head><body></body></html>';
		$response = new Response($html, headers: ['Content-Type' => 'text/html']);

		$this->javascriptFilter()->filter($this->responseEvent(
			$this->requestWithOptions(new InlineAssetMinificationOptions(false, true, false)),
			$response,
		));

		$content = (string) $response->getContent();
		self::assertStringContainsString('function addNumbers(firstValue,secondValue){return firstValue+secondValue}', $content);
		self::assertStringContainsString('src="/app.js">/* external */', $content);
		self::assertStringContainsString('export const moduleValue=await Promise.resolve(1)', $content);
		self::assertStringContainsString('"title": "formatted"', $content);
		self::assertStringNotContainsString('"title":"formatted"', $content);
		self::assertStringContainsString('"app": "/app.js"', $content);
	}

	public function testOptionalUglificationManglesLocalIdentifiers(): void
	{
		$response = new Response('<!doctype html><html><head><script>' . "\n" . 'function addNumbers(firstValue, secondValue) { return firstValue + secondValue; }' . "\n" . '</script></head><body></body></html>', headers: ['Content-Type' => 'text/html']);

		$this->javascriptFilter()->filter($this->responseEvent(
			$this->requestWithOptions(new InlineAssetMinificationOptions(false, true, true)),
			$response,
		));

		$content = (string) $response->getContent();
		self::assertStringContainsString('function addNumbers(', $content);
		self::assertStringNotContainsString('firstValue', $content);
		self::assertStringNotContainsString('secondValue', $content);
	}

	public function testMalformedJavaScriptRemainsUnchanged(): void
	{
		$html = '<!doctype html><html><head><script>' . "\n" . 'function ( {' . "\n" . '</script></head><body></body></html>';
		$response = new Response($html, headers: ['Content-Type' => 'text/html']);

		$this->javascriptFilter()->filter($this->responseEvent(
			$this->requestWithOptions(new InlineAssetMinificationOptions(false, true, false)),
			$response,
		));

		self::assertSame($html, $response->getContent());
	}

	public function testFiltersSkipSubrequestsAndWhenDisabled(): void
	{
		$html = '<!doctype html><html><head><style>/* comment */' . "\n" . '.card { color: red; }</style></head><body></body></html>';
		$response = new Response($html, headers: ['Content-Type' => 'text/html']);
		$filter = $this->cssFilter();
		$filter->filter($this->responseEvent(
			$this->requestWithOptions(new InlineAssetMinificationOptions(true, false, false)),
			$response,
			HttpKernelInterface::SUB_REQUEST,
		));
		$filter->filter($this->responseEvent(
			$this->requestWithOptions(new InlineAssetMinificationOptions(false, false, false)),
			$response,
		));

		self::assertSame($html, $response->getContent());
	}

	public function testCssAndJsFiltersUseContentHashCacheAndFallbackOnCacheErrors(): void
	{
		$cache = new ArrayAdapter();
		$resolver = new InlineAssetMinificationOptionsResolver(new EventDispatcher(), true, true, false);
		$cssFilter = new InlineCssFilter($resolver, $cache);
		$jsFilter = new InlineJavaScriptFilter($resolver, new TerserUglifier(dirname(__DIR__, 2)), $cache);

		$html = '<!doctype html><html><head><style>.card { color: red; }</style><script>' . "\n" . 'function sum(a, b) { return a + b; }' . "\n" . '</script></head><body></body></html>';
		foreach ([1, 2] as $_pass) {
			$response = new Response($html, headers: ['Content-Type' => 'text/html']);
			$event = $this->responseEvent($this->requestWithOptions(new InlineAssetMinificationOptions(true, true, false)), $response);
			$cssFilter->filter($event);
			$jsFilter->filter($event);
			self::assertStringContainsString('.card{color:red;}', (string) $response->getContent());
			self::assertStringContainsString('function sum(a,b){return a+b}', (string) $response->getContent());
		}
		self::assertCount(2, $cache->getValues());

		$brokenCache = $this->createStub(CacheItemPoolInterface::class);
		$brokenCache->method('getItem')->willThrowException(new \RuntimeException('cache down'));
		$fallbackResponse = new Response($html, headers: ['Content-Type' => 'text/html']);
		$fallbackEvent = $this->responseEvent($this->requestWithOptions(new InlineAssetMinificationOptions(true, true, false)), $fallbackResponse);
		(new InlineCssFilter($resolver, $brokenCache))->filter($fallbackEvent);
		(new InlineJavaScriptFilter($resolver, new TerserUglifier(dirname(__DIR__, 2)), $brokenCache))->filter($fallbackEvent);
		self::assertStringContainsString('.card{color:red;}', (string) $fallbackResponse->getContent());
		self::assertStringContainsString('function sum(a,b){return a+b}', (string) $fallbackResponse->getContent());
	}

	public function testOutputParserRunsCoreDomFiltersInSingleDeferredPass(): void
	{
		$resolver = new InlineAssetMinificationOptionsResolver(new EventDispatcher(), true, true, false);
		$cache = new ArrayAdapter();
		$parser = new OutputParser(
			new Stopwatch(),
			new InlineCssFilter($resolver, $cache),
			new InlineJavaScriptFilter($resolver, new TerserUglifier(dirname(__DIR__, 2)), $cache),
		);
		$composer = new HtmlFragmentComposer(
			'<!doctype html><html><head><style>.hero { color: green; }</style><script>' . "\n" . 'var x = 1 + 2;' . "\n" . '</script></head><body><!-- remove me --><p>Hello    world</p><pre>keep   spaces</pre></body></html>',
		);
		$response = new DomHtmlResponse($composer->document());
		$request = $this->requestWithOptions(new InlineAssetMinificationOptions(true, true, false));

		$parser->onKernelResponse($this->responseEvent($request, $response));

		$content = (string) $response->getContent();
		self::assertStringNotContainsString('remove me', $content);
		self::assertStringContainsString('<p>Hello world</p>', $content);
		self::assertStringContainsString('<pre>keep   spaces</pre>', $content);
		self::assertStringContainsString('.hero{color:green;}', $content);
		self::assertStringContainsString('var x=1+2', $content);
	}

	public function testOutputParserRunsHtmlFilterWithoutStopwatchPreservesSvgAndRespectsSetContent(): void
	{
		$resolver = new InlineAssetMinificationOptionsResolver(new EventDispatcher(), false, false, false);
		$parser = new OutputParser(
			null,
			new InlineCssFilter($resolver),
			new InlineJavaScriptFilter($resolver, new TerserUglifier(dirname(__DIR__, 2))),
		);
		$composer = new HtmlFragmentComposer(
			'<!doctype html><!-- top comment --><html><body><!-- inner --><p>A    B</p><svg><style>.a  {  fill: red;  }</style><script>const  x  =  1;</script></svg></body></html>',
		);
		$response = new DomHtmlResponse($composer->document());
		$request = $this->requestWithOptions(new InlineAssetMinificationOptions(false, false, false));

		$parser->onKernelResponse($this->responseEvent($request, $response));

		$content = (string) $response->getContent();
		self::assertStringNotContainsString('top comment', $content);
		self::assertStringNotContainsString('inner', $content);
		self::assertStringContainsString('<p>A B</p>', $content);
		self::assertStringContainsString('<style>.a  {  fill: red;  }</style>', $content);
		self::assertStringContainsString('<script>const  x  =  1;</script>', $content);

		// A DomHtmlResponse overridden via setContent() before OutputParser must not be clobbered by its stale DOM.
		$overridden = new DomHtmlResponse((new HtmlFragmentComposer('<!doctype html><html><body><p>Stale</p></body></html>'))->document());
		$overridden->setContent('<!doctype html><html><body><!-- gone --><p>Fresh    text</p></body></html>');
		$overriddenRequest = $this->requestWithOptions(new InlineAssetMinificationOptions(false, false, false));
		$parser->onKernelResponse($this->responseEvent($overriddenRequest, $overridden));

		$overriddenContent = (string) $overridden->getContent();
		self::assertStringContainsString('<p>Fresh text</p>', $overriddenContent);
		self::assertStringNotContainsString('Stale', $overriddenContent);
		self::assertStringNotContainsString('gone', $overriddenContent);

		// Post-flush content updates (e.g. CSP nonce injection) must not be reverted by a subsequent filter pass that writes back.
		$overridden->setContent(str_replace('<p>', '<style>.post { color: blue; }</style><p data-nonce="abc">', $overriddenContent));
		$this->cssFilter()->filter($this->responseEvent(
			$this->requestWithOptions(new InlineAssetMinificationOptions(true, false, false)),
			$overridden,
		));
		$postFlushContent = (string) $overridden->getContent();
		self::assertStringContainsString('data-nonce="abc"', $postFlushContent);
		self::assertStringContainsString('.post{color:blue;}', $postFlushContent);
	}

	public function testTerserFailureDoesNotPoisonUglifyCacheAndRawtextClosingTagsAreRejected(): void
	{
		$cache = new ArrayAdapter();
		$resolver = new InlineAssetMinificationOptionsResolver(new EventDispatcher(), true, true, true);
		$jsSource = "\nfunction addNumbers(firstValue, secondValue) { return firstValue + secondValue; }\n";
		$html = '<!doctype html><html><head><script>'.$jsSource.'</script></head><body></body></html>';

		// Pass 1: broken Terser project dir falls back to whitespace minification without caching under jsUglify=true.
		$brokenJsFilter = new InlineJavaScriptFilter($resolver, new TerserUglifier('/nonexistent-terser-dir'), $cache);
		$firstResponse = new Response($html, headers: ['Content-Type' => 'text/html']);
		$brokenJsFilter->filter($this->responseEvent(
			$this->requestWithOptions(new InlineAssetMinificationOptions(false, true, true)),
			$firstResponse,
		));
		self::assertStringContainsString('function addNumbers(firstValue,secondValue){return firstValue+secondValue}', (string) $firstResponse->getContent());
		self::assertFalse($cache->hasItem('inline_js_min_v2_0_s_u_'.hash('sha256', $jsSource)));

		// Pass 2: healthy Terser uglifies and caches the result.
		$healthyJsFilter = new InlineJavaScriptFilter($resolver, new TerserUglifier(dirname(__DIR__, 2)), $cache);
		$secondResponse = new Response($html, headers: ['Content-Type' => 'text/html']);
		$healthyJsFilter->filter($this->responseEvent(
			$this->requestWithOptions(new InlineAssetMinificationOptions(false, true, true)),
			$secondResponse,
		));
		self::assertStringNotContainsString('firstValue', (string) $secondResponse->getContent());
		self::assertTrue($cache->hasItem('inline_js_min_v2_0_s_u_'.hash('sha256', $jsSource)));

		// Poisoned cache entries containing rawtext closing tags must be rejected by both filters.
		$poisonCache = new ArrayAdapter();
		$cssSource = '.card { color: red; margin: 0 1px; }';
		$cssKey = 'inline_css_min_v2_0_'.hash('sha256', $cssSource);
		$cssItem = $poisonCache->getItem($cssKey);
		$cssItem->set(['changed' => true, 'output' => '</style><script>alert(1)</script>']);
		$poisonCache->save($cssItem);

		$jsKey = 'inline_js_min_v2_0_s_n_'.hash('sha256', $jsSource);
		$jsItem = $poisonCache->getItem($jsKey);
		$jsItem->set(['changed' => true, 'output' => '</script><img src=x>']);
		$poisonCache->save($jsItem);

		$safeResponse = new Response('<!doctype html><html><head><style>'.$cssSource.'</style><script>'.$jsSource.'</script></head><body></body></html>', headers: ['Content-Type' => 'text/html']);
		$safeEvent = $this->responseEvent($this->requestWithOptions(new InlineAssetMinificationOptions(true, true, false)), $safeResponse);
		(new InlineCssFilter($resolver, $poisonCache))->filter($safeEvent);
		(new InlineJavaScriptFilter($resolver, new TerserUglifier(dirname(__DIR__, 2)), $poisonCache))->filter($safeEvent);

		$safeHtml = (string) $safeResponse->getContent();
		self::assertStringNotContainsString('alert(1)', $safeHtml);
		self::assertStringNotContainsString('<img src=x>', $safeHtml);
	}

	private function cssFilter(): InlineCssFilter
	{
		return new InlineCssFilter(new InlineAssetMinificationOptionsResolver(new EventDispatcher(), true, true, false));
	}

	private function javascriptFilter(): InlineJavaScriptFilter
	{
		return new InlineJavaScriptFilter(
			new InlineAssetMinificationOptionsResolver(new EventDispatcher(), true, true, false),
			new TerserUglifier(dirname(__DIR__, 2)),
		);
	}

	private function requestWithOptions(InlineAssetMinificationOptions $options): Request
	{
		$request = new Request();
		$request->attributes->set(InlineAssetMinificationOptions::REQUEST_ATTRIBUTE, $options);

		return $request;
	}

	private function responseEvent(Request $request, Response $response, int $requestType = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
	{
		return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $requestType, $response);
	}
}

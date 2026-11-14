<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Filters;

use Jimbo2150\PhpCssTypedOm\CSSOM\CSSRenderMode;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSRenderer;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSRenderOptions;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSStyleRule;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSStyleSheet;
use iikiti\CMS\Filters\InlineAssetMinificationOptions;
use iikiti\CMS\Filters\InlineAssetMinificationOptionsResolver;
use iikiti\CMS\Filters\InlineCssFilter;
use iikiti\CMS\Filters\InlineJavaScriptFilter;
use iikiti\CMS\Filters\TerserUglifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

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

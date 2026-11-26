<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Api\ApiSessionTokenProvider;
use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Async\FiberHttpClient;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Async\Promise;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Response\Composition\ControllerResponseComposer;
use iikiti\CMS\Response\Composition\ControllerResponsePlan;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use iikiti\CMS\Response\Composition\ResponseFragment;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\CMS\Tests\Support\Async\LocalBarrierHttpServer;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use iikiti\CMS\Web\BlockEditor\BlockType\CoreBlockTypeProvider;
use iikiti\CMS\Web\BlockEditor\PublicPageApiProxy;
use iikiti\CMS\Web\BlockEditor\Query\QueryExecutor;
use iikiti\CMS\Web\BlockEditor\Query\QueryFieldCatalog;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderer;
use iikiti\CMS\Web\BlockEditor\Twig\LocalizedTextExtension;
use iikiti\CMS\Web\Template\TemplateRenderer;
use iikiti\CMS\Web\Template\TemplateRenderPrefetchInterface;
use iikiti\Extension\CustomCss\Css\CustomCssCompiler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

/**
 * Layer 2 real loopback TCP concurrency integration tests using CurlHttpClient
 * against a multiplexed barrier server.
 */
final class AsyncHttpConcurrencyIntegrationTest extends TestCase
{
	private LocalBarrierHttpServer $server;

	protected function setUp(): void
	{
		if (!extension_loaded('curl')) {
			$this->markTestSkipped('ext-curl is required for CurlHttpClient integration tests.');
		}
		$probe = @stream_socket_server('tcp://127.0.0.1:0');
		if (false === $probe) {
			$this->markTestSkipped('Loopback TCP sockets cannot be bound in this environment.');
		}
		fclose($probe);

		$this->server = (new LocalBarrierHttpServer())->start();
	}

	protected function tearDown(): void
	{
		if (isset($this->server)) {
			$this->server->stop();
		}
	}

	public function testFiberTaskRunnerMultiplexesRealTcpConnectionsWhenOneIsBlocked(): void
	{
		$runner = new FiberTaskRunner(new CurlHttpClient());
		$holdUrl = $this->server->holdUrl('fiber_tcp', 'held-tcp-ok');
		$fastUrl = $this->server->fastUrl('fast_tcp', 'fiber_tcp', 'fast-tcp-ok');

		$results = $runner->run([
			'slow' => static fn (FiberHttpClient $http): string => $http->request('GET', $holdUrl)->getContent(),
			'fast' => static fn (FiberHttpClient $http): string => $http->request('GET', $fastUrl)->getContent(),
		]);

		self::assertSame(['slow' => 'held-tcp-ok', 'fast' => 'fast-tcp-ok'], $results);
		$events = $this->server->inspect()['events'];
		self::assertLessThan(
			array_search('responded:fast_tcp', $events, true),
			array_search('received:fiber_tcp', $events, true),
		);
		self::assertLessThan(
			array_search('responded:fiber_tcp', $events, true),
			array_search('responded:fast_tcp', $events, true),
		);
	}

	public function testTemplateRendererRunDuringDispatchesRealHttpBeforeLocalTwigRenderingFinishes(): void
	{
		$holdUrl = $this->server->holdUrl('twig_overlap', '<blockquote>async-tcp-embed</blockquote>');
		$token = bin2hex(random_bytes(8));
		$seenReceivedDuringTwig = false;

		$prefetcher = new class($holdUrl, $token) implements TemplateRenderPrefetchInterface {
			public function __construct(
				private readonly string $holdUrl,
				private readonly string $token,
			) {
			}

			public function prefetchDuringRender(
				array $regionTrees,
				array $shells,
				BlockRenderContext $context,
				FiberTaskRunner $runner,
				callable $render,
				?HtmlFragmentComposer $composer = null,
			): BlockRenderContext {
				$execution = $runner->runDuring(
					['remote' => fn (FiberHttpClient $http): string => $http->request('GET', $this->holdUrl)->getContent()],
					static fn (): mixed => $render($context),
				);
				$composer?->replaceDeferredEmbeds([$this->token => (string) $execution['tasks']['remote']]);

				return $context;
			}
		};

		$server = $this->server;
		$layoutEnv = new Environment(new ArrayLoader([
			'layout.twig' => '<main>{{ region_main|raw }}{{ probe_and_signal()|raw }}</main>',
		]), ['strict_variables' => false]);
		$layoutEnv->addFunction(new TwigFunction('probe_and_signal', static function () use ($server, $token, &$seenReceivedDuringTwig): string {
			$deadline = microtime(true) + 0.25;
			while (microtime(true) < $deadline) {
				$events = $server->inspect()['events'];
				if (in_array('received:twig_overlap', $events, true)) {
					$seenReceivedDuringTwig = true;
					break;
				}
				usleep(2_000);
			}
			$server->signal('twig_overlap');

			return '<template data-iikiti-async-embed="'.$token.'"></template>';
		}));

		$blockTwig = new Environment(new ArrayLoader([]), ['strict_variables' => false]);
		$blockTwig->addExtension(new LocalizedTextExtension());
		$blockRenderer = new BlockRenderer(
			new BlockTypeRegistry([new CoreBlockTypeProvider()]),
			$blockTwig,
			new QueryExecutor([]),
			new QueryFieldCatalog($this->createStub(EntityManagerInterface::class)),
			new CustomCssCompiler(),
		);
		$renderer = new TemplateRenderer(
			$blockRenderer,
			$layoutEnv,
			fiberTaskRunner: new FiberTaskRunner(new CurlHttpClient()),
			renderPrefetchers: [$prefetcher],
		);

		$template = new Template();
		(new \ReflectionClass(DbObject::class))->getProperty('properties')->setValue($template, new ArrayCollection());
		$template->setLayout('layout.twig');
		$template->setRegions([['id' => 'main', 'role' => 'main', 'name' => 'Main', 'allowed_types' => []]]);

		$composer = new HtmlFragmentComposer('<!DOCTYPE html><html><body><template data-iikiti-fragment="page"></template></body></html>');
		$renderer->renderIntoDocument(
			$template,
			new BlockRenderContext(editorMode: false),
			['main' => [['id' => 'c1', 'type' => 'container', 'content' => [], 'style' => ['base' => []], 'children' => []]]],
			$composer,
			'[data-iikiti-fragment="page"]',
		);
		$html = $composer->render();

		self::assertTrue($seenReceivedDuringTwig, 'primePending() should dispatch /hold before local Twig rendering completes.');
		self::assertStringContainsString('<blockquote>async-tcp-embed</blockquote>', $html);
		self::assertStringNotContainsString('data-iikiti-async-embed', $html);
	}

	public function testPublicPageApiProxyEagerDagAndFailFastOverRealTcpSockets(): void
	{
		$revision = str_repeat('a', 64);
		$slowWidgetId = str_repeat('5', 64);
		$slotWidget = str_repeat('1', 32);
		$slotA = str_repeat('2', 32);
		$slotB = str_repeat('3', 32);

		$manifestJson = json_encode([
			'html' => '<!DOCTYPE html><html><head><template data-iikiti-fragment="custom-css"></template></head><body><main>'
				.'<template data-iikiti-widget-slot="'.$slotWidget.'"></template>'
				.'<template data-iikiti-provider-slot="'.$slotA.'"></template>'
				.'<template data-iikiti-provider-slot="'.$slotB.'"></template>'
				.'</main></body></html>',
			'styles' => [],
			'editor_mode' => false,
			'revision' => $revision,
			'fragments' => [
				['kind' => 'widget', 'id' => $slowWidgetId, 'role' => 'widget', 'slot' => $slotWidget, 'depth' => 1],
				['kind' => 'provider', 'id' => 'sample.a', 'role' => 'provider', 'slot' => $slotA, 'depth' => 0, 'key' => 'a', 'dependsOn' => [], 'params' => []],
				['kind' => 'provider', 'id' => 'sample.b', 'role' => 'provider', 'slot' => $slotB, 'depth' => 0, 'key' => 'b', 'dependsOn' => ['a'], 'params' => []],
			],
			'inline_asset_minification' => ['css_minify' => true, 'js_minify' => true, 'js_uglify' => false],
		], JSON_THROW_ON_ERROR);

		$this->server->configure([
			[
				'path' => '/api/public/render/pages/dag/manifest',
				'action' => 'fast',
				'name' => 'manifest',
				'body' => $manifestJson,
			],
			[
				'path' => '/api/public/render/pages/dag/fragments/region',
				'fragmentId' => '@main',
				'action' => 'fast',
				'name' => 'speculative-main',
				'body' => json_encode(['found' => true, 'id' => 'main', 'revision' => $revision, 'html' => '', 'styles' => []], JSON_THROW_ON_ERROR),
			],
			[
				'path' => '/api/public/render/pages/dag/fragments/widget',
				'fragmentId' => $slowWidgetId,
				'action' => 'hold',
				'gate' => 'proxy_dag',
				'timeout' => 3.0,
				'body' => json_encode(['found' => true, 'id' => $slowWidgetId, 'revision' => $revision, 'html' => '<p>Slow TCP Widget</p>', 'styles' => []], JSON_THROW_ON_ERROR),
			],
			[
				'path' => '/api/public/render/providers/sample.a',
				'action' => 'fast',
				'name' => 'prov-a',
				'body' => json_encode(['html' => '<p>Provider A</p>', 'styles' => []], JSON_THROW_ON_ERROR),
			],
			[
				'path' => '/api/public/render/providers/sample.b',
				'action' => 'fast',
				'name' => 'prov-b',
				'signal' => 'proxy_dag',
				'body' => json_encode(['html' => '<p>Provider B</p>', 'styles' => []], JSON_THROW_ON_ERROR),
			],
		]);

		$curl = new CurlHttpClient();
		$api = new BusinessApiClient($curl, $this->server->baseUrl(), 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($curl),
			new GuestApiTokenManager('test-secret'),
		);

		$response = $proxy->page(Request::create('https://www.example.test/dag'), 'dag');
		$html = (string) $response->getContent();
		$events = $this->server->inspect()['events'];

		self::assertSame(200, $response->getStatusCode());
		self::assertInstanceOf(DomHtmlResponse::class, $response);
		self::assertStringContainsString('<p>Slow TCP Widget</p><p>Provider A</p><p>Provider B</p>', $html);
		self::assertLessThan(array_search('responded:prov-b', $events, true), array_search('responded:prov-a', $events, true));
		self::assertLessThan(array_search('responded:proxy_dag', $events, true), array_search('responded:prov-b', $events, true));

		// Fail-fast: when sibling returns HTTP 500, held widget is cancelled without waiting 3s.
		$failFastManifest = json_encode([
			'html' => '<!DOCTYPE html><html><head><template data-iikiti-fragment="custom-css"></template></head><body><main>'
				.'<template data-iikiti-widget-slot="'.$slotWidget.'"></template>'
				.'<template data-iikiti-provider-slot="'.$slotA.'"></template>'
				.'</main></body></html>',
			'styles' => [],
			'editor_mode' => false,
			'revision' => $revision,
			'fragments' => [
				['kind' => 'widget', 'id' => $slowWidgetId, 'role' => 'widget', 'slot' => $slotWidget, 'depth' => 1],
				['kind' => 'provider', 'id' => 'sample.a', 'role' => 'provider', 'slot' => $slotA, 'depth' => 0, 'key' => 'a', 'dependsOn' => [], 'params' => []],
			],
			'inline_asset_minification' => ['css_minify' => true, 'js_minify' => true, 'js_uglify' => false],
		], JSON_THROW_ON_ERROR);

		$this->server->configure([
			[
				'path' => '/api/public/render/pages/failfast/manifest',
				'action' => 'fast',
				'name' => 'ff-manifest',
				'body' => $failFastManifest,
			],
			[
				'path' => '/api/public/render/pages/failfast/fragments/region',
				'fragmentId' => '@main',
				'action' => 'fast',
				'name' => 'ff-main',
				'body' => json_encode(['found' => true, 'id' => 'main', 'revision' => $revision, 'html' => '', 'styles' => []], JSON_THROW_ON_ERROR),
			],
			[
				'path' => '/api/public/render/pages/failfast/fragments/widget',
				'fragmentId' => $slowWidgetId,
				'action' => 'hold',
				'gate' => 'fail_fast',
				'timeout' => 3.0,
				'body' => json_encode(['found' => true, 'id' => $slowWidgetId, 'revision' => $revision, 'html' => '<p>Never</p>', 'styles' => []], JSON_THROW_ON_ERROR),
			],
			[
				'path' => '/api/public/render/providers/sample.a',
				'action' => 'status',
				'name' => 'ff-err',
				'status' => 500,
				'body' => '{"message":"provider error"}',
			],
		]);

		$startedAt = microtime(true);
		$failResponse = $proxy->page(Request::create('https://www.example.test/failfast'), 'failfast');
		$elapsed = microtime(true) - $startedAt;

		self::assertSame(500, $failResponse->getStatusCode());
		self::assertLessThan(1.0, $elapsed, 'Fail-fast must cancel held widget instead of waiting for its 3s hold timeout.');
	}

	public function testControllerResponseComposerOverRealTcpSockets(): void
	{
		$composer = new ControllerResponseComposer(new FiberTaskRunner(new CurlHttpClient()));
		$holdUrl = $this->server->holdUrl('composer_tcp', 'First');
		$fastUrl = $this->server->fastUrl('fast_cmp', 'composer_tcp', 'Second');

		$response = $composer->compose(ControllerResponsePlan::html(
			'<!DOCTYPE html><html><body><main><template id="slot"></template></main></body></html>',
			[
				'first' => Promise::async(static fn (FiberHttpClient $client): ResponseFragment => ResponseFragment::html(
					'#slot',
					'<p>'.$client->request('GET', $holdUrl)->getContent().'</p>',
					order: 10,
				)),
				'second' => Promise::async(static fn (FiberHttpClient $client): ResponseFragment => ResponseFragment::html(
					'#slot',
					'<p>'.$client->request('GET', $fastUrl)->getContent().'</p>',
					order: 20,
				)),
			],
		));

		self::assertInstanceOf(DomHtmlResponse::class, $response);
		self::assertStringContainsString('<p>First</p><p>Second</p>', (string) $response->getContent());
		$events = $this->server->inspect()['events'];
		self::assertLessThan(
			array_search('responded:composer_tcp', $events, true),
			array_search('responded:fast_cmp', $events, true),
		);
	}
}

<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Template;

use iikiti\CMS\Tests\Support\IconResolverFactory;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Web\BlockEditor\Embed\EmbedResolver;
use iikiti\CMS\Web\BlockEditor\CustomCssCompiler;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use iikiti\CMS\Web\BlockEditor\BlockType\CoreBlockTypeProvider;
use iikiti\CMS\Web\BlockEditor\Query\QueryExecutor;
use iikiti\CMS\Web\BlockEditor\Query\QueryFieldCatalog;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderer;
use iikiti\CMS\Web\BlockEditor\Twig\BlockTwigExtension;
use iikiti\CMS\Web\BlockEditor\Twig\LocalizedTextExtension;
use iikiti\CMS\Web\Icon\IconFontAssets;
use iikiti\CMS\Web\Template\TemplateRenderer;
use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TemplateRendererTest extends TestCase
{
	private function renderer(string $layout = '<header>{{ region_header|raw }}</header><main id="main">{{ region_main|raw }}</main>'): TemplateRenderer
	{
		$layoutEnv = new Environment(new ArrayLoader([
			'layout.twig' => $layout,
		]), ['strict_variables' => false]);

		$blockTwig = new Environment(new FilesystemLoader(__DIR__.'/../../../templates'), [
			'strict_variables' => false,
		]);
		$blockTwig->addExtension(new LocalizedTextExtension());
		$blockRenderer = new BlockRenderer(
			new BlockTypeRegistry([new CoreBlockTypeProvider(IconResolverFactory::bundledOnly())]),
			$blockTwig,
			new QueryExecutor([]),
			new QueryFieldCatalog($this->createStub(EntityManagerInterface::class)),
			new CustomCssCompiler(),
		);

		return new TemplateRenderer($blockRenderer, $layoutEnv);
	}

	/**
	 * @param list<array<string,mixed>> $regions
	 */
	private function template(array $regions, string $layout = 'layout.twig'): Template
	{
		$t = new Template();
		$i = (new \ReflectionClass(DbObject::class))->getProperty('properties');
		$i->setValue($t, new ArrayCollection());
		$t->setLayout($layout);
		$t->setRegions($regions);

		return $t;
	}

	public function testRendersRegionsWhenMainIsPresent(): void
	{
		$template = $this->template([
			['id' => 'header', 'role' => 'header', 'name' => 'Header', 'allowed_types' => []],
			['id' => 'main', 'role' => 'main', 'name' => 'Main', 'allowed_types' => []],
		]);

		$html = $this->renderer()->render($template, new BlockRenderContext(editorMode: false), []);

		$this->assertStringContainsString('<main id="main">', $html);
	}

	public function testEmptyNonMainRegionRendersNoPlaceholderText(): void
	{
		$template = $this->template([
			['id' => 'header', 'role' => 'header', 'name' => 'Header', 'allowed_types' => []],
			['id' => 'main', 'role' => 'main', 'name' => 'Main', 'allowed_types' => []],
		]);

		$html = $this->renderer()->render($template, new BlockRenderContext(editorMode: false), []);

		$this->assertStringNotContainsString('Empty', $html);
	}

	public function testStaticSocialEmbedsArePrefetchedBeforeBlockRendering(): void
	{
		$url = 'https://twitter.com/example/status/1';
		$requested = [];
		$http = new MockHttpClient(static function (string $method, string $requestUrl) use (&$requested): MockResponse {
			$requested[] = $requestUrl;

			return new MockResponse(json_encode([
				'html' => '<blockquote cite="https://twitter.com/example/status/1">prefetched</blockquote>',
			], JSON_THROW_ON_ERROR));
		});
		$embedResolver = new EmbedResolver([], $http, new ArrayAdapter());
		$layoutEnv = new Environment(new ArrayLoader([
			'layout.twig' => '<main>{{ region_main|raw }}</main>',
		]), ['strict_variables' => false]);
		$blockTwig = new Environment(new FilesystemLoader(__DIR__.'/../../../templates'), [
			'strict_variables' => false,
		]);
		$blockTwig->addExtension(new LocalizedTextExtension());
		$blockTwig->addExtension(new BlockTwigExtension(
			$embedResolver,
			new QueryExecutor([]),
			IconResolverFactory::bundledOnly(),
			new IconFontAssets(),
		));
		$blockRenderer = new BlockRenderer(
			new BlockTypeRegistry([new CoreBlockTypeProvider(IconResolverFactory::bundledOnly())]),
			$blockTwig,
			new QueryExecutor([]),
			new QueryFieldCatalog($this->createStub(EntityManagerInterface::class)),
			new CustomCssCompiler(),
		);
		$renderer = new TemplateRenderer(
			$blockRenderer,
			$layoutEnv,
			fiberTaskRunner: new FiberTaskRunner($http),
			embedResolver: $embedResolver,
		);
		$template = $this->template([
			['id' => 'main', 'role' => 'main', 'name' => 'Main', 'allowed_types' => []],
		]);

		$composer = new HtmlFragmentComposer('<!DOCTYPE html><html><body><template data-iikiti-fragment="page"></template></body></html>');
		$renderer->renderIntoDocument($template, new BlockRenderContext(editorMode: false), [
			'main' => [[
				'id' => 'container-1',
				'type' => 'container',
				'content' => [],
				'style' => ['base' => []],
				'children' => [[
					'id' => 'embed-1',
					'type' => 'social_embed',
					'content' => ['url' => $url],
				]],
			]],
		], $composer, '[data-iikiti-fragment="page"]');
		$html = $composer->render();

		self::assertCount(1, $requested);
		self::assertStringContainsString('<blockquote', $html);
		self::assertStringContainsString('prefetched', $html);
	}

	public function testNoMainRegionShowsErrorInPublicMode(): void
	{
		$template = $this->template([
			['id' => 'header', 'role' => 'header', 'name' => 'Header', 'allowed_types' => []],
		]);

		$html = $this->renderer()->render($template, new BlockRenderContext(editorMode: false), []);

		$this->assertStringContainsString('main content area must be provided', $html);
	}

	public function testNoMainRegionShowsEditorErrorInEditMode(): void
	{
		$template = $this->template([
			['id' => 'sidebar', 'role' => 'sidebar', 'name' => 'Sidebar', 'allowed_types' => []],
		]);

		$html = $this->renderer()->render($template, new BlockRenderContext(editorMode: true), []);

		$this->assertStringContainsString('no_main_region', $html);
		$this->assertStringContainsString('main content area must be provided', $html);
	}

	public function testManifestContainsFixedSlotsAndRegionFragmentsShareItsRevision(): void
	{
		$renderer = $this->renderer();
		$template = $this->template([
			['id' => 'header', 'role' => 'header', 'name' => 'Header', 'allowed_types' => []],
			['id' => 'main', 'role' => 'main', 'name' => 'Main', 'allowed_types' => []],
		]);
		$context = new BlockRenderContext(editorMode: false);
		$trees = ['main' => [[
			'id' => 'container-1',
			'type' => 'container',
			'content' => [],
			'style' => ['base' => []],
			'children' => [['type' => 'text', 'content' => ['content' => '<p>API fragment</p>'], 'style' => ['base' => []]]],
		]]];

		$manifest = $renderer->renderManifest($template, $context, $trees);
		$fragment = $renderer->renderFragment($template, $context, $trees, 'region', 'main');

		self::assertCount(3, $manifest['fragments']);
		self::assertSame(['main', 'header'], array_column(array_slice($manifest['fragments'], 0, 2), 'id'));
		self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $manifest['fragments'][0]['slot']);
		self::assertStringContainsString($manifest['fragments'][1]['slot'], $manifest['html']);
		self::assertNotNull($fragment);
		self::assertSame($manifest['revision'], $fragment['revision']);
		self::assertStringContainsString($manifest['fragments'][2]['slot'], $fragment['html']);
		self::assertStringNotContainsString('API fragment', $fragment['html']);
		$widget = $renderer->renderFragment($template, $context, $trees, 'widget', $manifest['fragments'][2]['id']);
		self::assertNotNull($widget);
		self::assertSame($manifest['revision'], $widget['revision']);
		self::assertStringContainsString('API fragment', $widget['html']);
		$staleWidget = $renderer->renderFragment(
			$template,
			$context,
			$trees,
			'widget',
			$manifest['fragments'][2]['id'],
			expectedRevision: str_repeat('f', 64),
		);
		self::assertNotNull($staleWidget);
		self::assertFalse($staleWidget['found']);
		self::assertSame($manifest['revision'], $staleWidget['revision']);
		self::assertNull($renderer->renderFragment($template, $context, $trees, 'region', 'unknown'));

		$template->setLayout('changed.twig');
		$changed = $renderer->renderFragment($template, $context, $trees, 'region', 'main');
		self::assertNotNull($changed);
		self::assertNotSame($manifest['revision'], $changed['revision']);
	}

	public function testManifestOmitsConfiguredRegionsThatTheLayoutDoesNotRender(): void
	{
		$renderer = $this->renderer('<main>{{ region_main|raw }}</main>');
		$template = $this->template([
			['id' => 'main', 'role' => 'main', 'name' => 'Main', 'allowed_types' => []],
			['id' => 'aside-left', 'role' => 'sidebar', 'name' => 'Left sidebar', 'allowed_types' => []],
		]);
		$manifest = $renderer->renderManifest($template, new BlockRenderContext(editorMode: false), []);

		self::assertSame(['main'], array_column($manifest['fragments'], 'id'));
	}
}

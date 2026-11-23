<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Template;

use iikiti\CMS\Tests\Support\IconResolverFactory;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
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
use iikiti\CMS\Web\Fragment\FragmentContext;
use iikiti\CMS\Web\Fragment\FragmentProviderInterface;
use iikiti\CMS\Web\Fragment\FragmentProviderRegistry;
use iikiti\CMS\Web\Fragment\FragmentResult;
use iikiti\CMS\Web\Fragment\FragmentRuntime;
use iikiti\CMS\Web\Fragment\FragmentTwigExtension;
use iikiti\CMS\Web\BlockEditor\Render\CustomCssCollector;
use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;

final class TemplateRendererTest extends TestCase
{
	private function renderer(string $layout = '<header>{{ region_header|raw }}</header><main id="main">{{ region_main|raw }}</main>', ?FragmentTwigExtension $fragmentExtension = null): TemplateRenderer
	{
		$layoutEnv = new Environment(new ArrayLoader([
			'layout.twig' => $layout,
		]), ['strict_variables' => false]);
		if (null !== $fragmentExtension) {
			$layoutEnv->addExtension($fragmentExtension);
		}

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
		// Single default top-level container stays inline inside the `main` region fragment.
		$trees = ['main' => [[
			'id' => 'container-1',
			'type' => 'container',
			'content' => [],
			'style' => ['base' => []],
			'children' => [['type' => 'text', 'content' => ['content' => '<p>API fragment</p>'], 'style' => ['base' => []]]],
		]]];

		$manifest = $renderer->renderManifest($template, $context, $trees);
		$fragment = $renderer->renderFragment($template, $context, $trees, 'region', 'main');

		self::assertCount(2, $manifest['fragments']);
		self::assertSame(['main', 'header'], array_column($manifest['fragments'], 'id'));
		self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $manifest['fragments'][0]['slot']);
		self::assertStringContainsString($manifest['fragments'][1]['slot'], $manifest['html']);
		self::assertNotNull($fragment);
		self::assertSame($manifest['revision'], $fragment->metadata['revision']);
		self::assertStringContainsString('API fragment', $fragment->fragment->html);
		self::assertNull($renderer->renderFragment($template, $context, $trees, 'region', 'unknown'));

		// Multiple top-level containers split into widget fragments and inline their
		// widget slots directly into the manifest layout (omitting the empty region stub).
		$multiTrees = ['main' => [
			[
				'id' => 'container-1',
				'type' => 'container',
				'content' => [],
				'style' => ['base' => []],
				'children' => [['type' => 'text', 'content' => ['content' => '<p>First widget</p>'], 'style' => ['base' => []]]],
			],
			[
				'id' => 'container-2',
				'type' => 'container',
				'content' => [],
				'style' => ['base' => []],
				'children' => [['type' => 'text', 'content' => ['content' => '<p>Second widget</p>'], 'style' => ['base' => []]]],
			],
		]];
		$multiManifest = $renderer->renderManifest($template, $context, $multiTrees);
		self::assertCount(3, $multiManifest['fragments']);
		self::assertSame(['region', 'widget', 'widget'], array_column($multiManifest['fragments'], 'kind'));
		self::assertSame('header', $multiManifest['fragments'][0]['id']);
		self::assertStringContainsString('data-iikiti-widget-slot="'.$multiManifest['fragments'][1]['slot'].'"', $multiManifest['html']);
		self::assertStringContainsString('data-iikiti-widget-slot="'.$multiManifest['fragments'][2]['slot'].'"', $multiManifest['html']);

		$widget = $renderer->renderFragment($template, $context, $multiTrees, 'widget', $multiManifest['fragments'][1]['id']);
		self::assertNotNull($widget);
		self::assertSame($multiManifest['revision'], $widget->metadata['revision']);
		self::assertStringContainsString('First widget', $widget->fragment->html);

		$staleWidget = $renderer->renderFragment(
			$template,
			$context,
			$multiTrees,
			'widget',
			$multiManifest['fragments'][1]['id'],
			expectedRevision: str_repeat('f', 64),
		);
		self::assertNotNull($staleWidget);
		self::assertFalse($staleWidget->metadata['found']);
		self::assertSame($multiManifest['revision'], $staleWidget->metadata['revision']);

		$template->setLayout('changed.twig');
		$changed = $renderer->renderFragment($template, $context, $trees, 'region', 'main');
		self::assertNotNull($changed);
		self::assertNotSame($manifest['revision'], $changed->metadata['revision']);
	}

	public function testRenderRevisionVariesWithRequestLocale(): void
	{
		$renderer = $this->renderer();
		$template = $this->template([['id' => 'main', 'role' => 'main', 'name' => 'Main', 'allowed_types' => []]]);
		$enRequest = \Symfony\Component\HttpFoundation\Request::create('https://example.test/');
		$enRequest->setLocale('en');
		$frRequest = \Symfony\Component\HttpFoundation\Request::create('https://example.test/');
		$frRequest->setLocale('fr');

		$enManifest = $renderer->renderManifest($template, new BlockRenderContext(editorMode: false, request: $enRequest), []);
		$frManifest = $renderer->renderManifest($template, new BlockRenderContext(editorMode: false, request: $frRequest), []);

		self::assertNotSame($enManifest['revision'], $frManifest['revision']);
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

	public function testPluginFragmentUsesSharedProviderForManifestAndServerRenderedTwig(): void
	{
		$provider = new class implements FragmentProviderInterface {
			public function getName(): string
			{
				return 'sample-plugin.card';
			}

			public function render(FragmentContext $context): FragmentResult
			{
				$title = htmlspecialchars((string) ($context->params['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

				return new FragmentResult('<aside>'.$title.'</aside>', [['id' => 'sample-plugin.card', 'css' => '.sample-card{}']]);
			}
		};
		$site = $this->createStub(Site::class);
		$site->method('getEnabledPlugins')->willReturn(['sample-plugin']);
		$runtime = new FragmentRuntime(new FragmentProviderRegistry([$provider]));
		$extension = new FragmentTwigExtension(static fn (): FragmentRuntime => $runtime, new RequestStack());
		$layout = '<main>{{ region_main|raw }}{{ iikiti_fragment("sample-plugin.card", {title: "Twig card"}, {key: "card"}) }}</main>';
		$renderer = $this->renderer($layout, $extension);
		$template = $this->template([['id' => 'main', 'role' => 'main', 'name' => 'Main', 'allowed_types' => []]]);
		$customCss = new CustomCssCollector();
		$context = new BlockRenderContext(editorMode: false, site: $site, customCssCollector: $customCss);

		$manifest = $renderer->renderManifest($template, $context, []);
		self::assertCount(2, $manifest['fragments']);
		$providerDescriptor = $manifest['fragments'][1];
		self::assertSame('provider', $providerDescriptor['kind']);
		self::assertSame('sample-plugin.card', $providerDescriptor['id']);
		self::assertSame(['title' => 'Twig card'], $providerDescriptor['params']);
		self::assertStringContainsString('data-iikiti-provider-slot="'.$providerDescriptor['slot'].'"', $manifest['html']);

		$html = $this->renderer($layout, $extension)->render($template, $context, []);
		self::assertStringContainsString('<aside>Twig card</aside>', $html);
		self::assertSame([['id' => null, 'css' => '.sample-card{}']], $customCss->fragments(false));
	}

	public function testInlineablePluginProviderExecutesInManifestWithoutAddingServerFragment(): void
	{
		$provider = new class implements \iikiti\CMS\Web\Fragment\InlineableFragmentProviderInterface {
			public function getName(): string
			{
				return 'sample-plugin.theme';
			}

			public function render(FragmentContext $context): FragmentResult
			{
				return new FragmentResult('', [['id' => 'sample-plugin.theme', 'css' => '.theme{color:teal}']]);
			}
		};
		$site = $this->createStub(Site::class);
		$site->method('getEnabledPlugins')->willReturn(['sample-plugin']);
		$runtime = new FragmentRuntime(new FragmentProviderRegistry([$provider]));
		$extension = new FragmentTwigExtension(static fn (): FragmentRuntime => $runtime, new RequestStack());
		$layout = '<main>{{ region_main|raw }}{{ iikiti_fragment("sample-plugin.theme") }}</main>';
		$renderer = $this->renderer($layout, $extension);
		$template = $this->template([['id' => 'main', 'role' => 'main', 'name' => 'Main', 'allowed_types' => []]]);
		$customCss = new CustomCssCollector();
		$context = new BlockRenderContext(editorMode: false, site: $site, customCssCollector: $customCss);

		$manifest = $renderer->renderManifest($template, $context, []);
		self::assertCount(1, $manifest['fragments']);
		self::assertSame('region', $manifest['fragments'][0]['kind']);
		self::assertStringNotContainsString('data-iikiti-provider-slot', $manifest['html']);
		self::assertSame([['id' => null, 'css' => '.theme{color:teal}']], $customCss->fragments(false));
	}

	public function testExplicitInlineFragmentWithInvalidDependencyFailsBeforeRenderingOrAppendingStyles(): void
	{
		$serverProvider = new class implements FragmentProviderInterface {
			public function getName(): string
			{
				return 'sample-plugin.first';
			}

			public function render(FragmentContext $context): FragmentResult
			{
				return new FragmentResult('<p>first</p>');
			}
		};
		$inlineProvider = new class implements FragmentProviderInterface {
			public bool $rendered = false;

			public function getName(): string
			{
				return 'sample-plugin.second';
			}

			public function render(FragmentContext $context): FragmentResult
			{
				$this->rendered = true;

				return new FragmentResult('<p>second</p>', [['id' => 'sample-plugin.second', 'css' => '.leaked{}']]);
			}
		};
		$site = $this->createStub(Site::class);
		$site->method('getEnabledPlugins')->willReturn(['sample-plugin']);
		$runtime = new FragmentRuntime(new FragmentProviderRegistry([$serverProvider, $inlineProvider]));
		$extension = new FragmentTwigExtension(static fn (): FragmentRuntime => $runtime, new RequestStack());
		$layout = '<main>{{ region_main|raw }}'
			.'{{ iikiti_fragment("sample-plugin.first", {}, {key: "first"}) }}'
			.'{{ iikiti_fragment("sample-plugin.second", {}, {delivery: "inline", key: "second", dependsOn: ["first"]}) }}'
			.'</main>';
		$renderer = $this->renderer($layout, $extension);
		$template = $this->template([['id' => 'main', 'role' => 'main', 'name' => 'Main', 'allowed_types' => []]]);
		$customCss = new CustomCssCollector();
		$context = new BlockRenderContext(editorMode: false, site: $site, customCssCollector: $customCss);

		$manifest = $renderer->renderManifest($template, $context, []);

		self::assertFalse($inlineProvider->rendered);
		self::assertSame([], $customCss->fragments(false));
		self::assertStringContainsString('iikiti-error', $manifest['html']);
		self::assertSame([], $manifest['fragments']);
	}
}

<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Template;

use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class BaseLayoutTest extends TestCase
{
	public function testLegacyBodiesAndDomFragmentPageModeBothRender(): void
	{
		$twig = $this->createTwig();

		$legacyHtml = $twig->render('base/layout.twig', $this->layoutContext([
			'iikiti_page_body' => '<main>Legacy body</main>',
		]));
		self::assertStringContainsString('<main>Legacy body</main>', $legacyHtml);

		$fragmentLayout = $twig->render('base/layout.twig', $this->layoutContext([
			'iikiti_page_body' => '',
			'iikiti_use_fragment_composer' => true,
			'iikiti_head_html' => [
				'<link rel="canonical" href="https://example.test/page">',
				'<script type="application/ld+json">[{"name":"\\u003C\\/script\\u003E\\u003Cscript\\u003E"}]</script>',
			],
			'iikiti_body_classes' => ['forma-theme'],
		]));
		$composer = new HtmlFragmentComposer($fragmentLayout);
		$composer->insertIntoPlaceholder('[data-iikiti-fragment="page"]', '<main>Fragment body</main>');
		$composer->insertIntoPlaceholder('[data-iikiti-fragment="icon-font"]', '');
		$composer->insertIntoPlaceholder('[data-iikiti-fragment="custom-css"]', '<style>main{color:red}</style>');
		$composed = $composer->render();

		self::assertStringContainsString('<main>Fragment body</main>', $composed);
		self::assertStringNotContainsString('data-iikiti-fragment="page"', $composed);
		self::assertStringNotContainsString('data-iikiti-fragment="icon-font"', $composed);
		self::assertStringNotContainsString('data-iikiti-fragment="custom-css"', $composed);
		self::assertSame(1, substr_count($composed, '<link rel="canonical"'));
		self::assertStringContainsString('https://example.test/page', $composed);
		self::assertStringContainsString('application/ld+json', $composed);
		self::assertStringContainsString('forma-theme', $composed);
		self::assertStringNotContainsString('entry:forma-2026', $composed);
	}

	public function testImportMapEmittedBeforeEntryScriptsAndGenericJsonBlocksSupported(): void
	{
		$twig = $this->createTwig();

		$html = $twig->render('base/layout.twig', $this->layoutContext([
			'doc' => ['title' => '<script>alert("title")</script>'],
			'iikiti_json_blocks' => [
				'iikiti-custom-block' => '{"ok":true}',
			],
		]));

		self::assertStringContainsString('&lt;script&gt;alert(&quot;title&quot;)&lt;/script&gt;', $html);
		self::assertStringContainsString('<script type="importmap">{"imports":{"svelte":"/build/esm/svelte.mjs"}}</script>', $html);
		self::assertLessThan(
			strpos($html, '<script src="/build/iikiti.js"></script>'),
			strpos($html, '<script type="importmap">'),
		);
		self::assertStringContainsString('<script id="iikiti-custom-block" type="application/json">{"ok":true}</script>', $html);
	}

	public function testIikitiConfigScriptTagHexEscapesHtmlBreakoutSequences(): void
	{
		$twig = $this->createTwig();

		$config = [
			'seoPreviewDefaults' => [
				'siteName' => '<!--<script>alert(1)</script>',
				'pageTitle' => '</script><img src=x onerror=alert(2)>',
			],
		];

		$html = $twig->render('base/layout.twig', $this->layoutContext([
			'iikiti_config' => $config,
		]));

		self::assertStringContainsString('id="iikiti-config"', $html);
		self::assertStringNotContainsString('<!--<script>', $html);
		self::assertStringNotContainsString('</script><img', $html);
		self::assertStringContainsString('\\u003C!--\\u003Cscript\\u003E', $html);
	}

	private function createTwig(): Environment
	{
		$twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates'));
		$twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/'.$path));
		$twig->addFunction(new TwigFunction('encore_entry_link_tags', static fn (string $entry): string => 'entry:'.$entry));
		$twig->addFunction(new TwigFunction('encore_entry_script_tags', static fn (string $entry): string => '<script src="/build/'.$entry.'.js"></script>', ['is_safe' => ['html']]));
		$twig->addFunction(new TwigFunction('iikiti_import_map_json', static fn (): string => '{"imports":{"svelte":"/build/esm/svelte.mjs"}}', ['is_safe' => ['html']]));
		$twig->addGlobal('app', (object) ['request' => (object) ['locale' => 'en']]);

		return $twig;
	}

	/**
	 * @param array<string,mixed> $overrides
	 *
	 * @return array<string,mixed>
	 */
	private function layoutContext(array $overrides = []): array
	{
		return $overrides + [
			'doc' => ['title' => 'Test page'],
			'iikiti_page_body' => '',
			'iikiti_can_edit' => false,
			'iikiti_editor_mode' => false,
			'iikiti_icon_font' => false,
			'iikiti_config' => null,
			'iikiti_scope_classes' => [],
		];
	}
}

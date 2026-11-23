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
		$twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates'));
		$twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/'.$path));
		$twig->addFunction(new TwigFunction('encore_entry_link_tags', static fn (string $entry): string => 'entry:'.$entry));
		$twig->addFunction(new TwigFunction('encore_entry_script_tags', static fn (string $entry): string => ''));

		$legacyHtml = $twig->render('base/layout.twig', $this->layoutContext([
			'iikiti_page_body' => '<main>Legacy body</main>',
		]));
		self::assertStringContainsString('<main>Legacy body</main>', $legacyHtml);

		$fragmentLayout = $twig->render('base/layout.twig', $this->layoutContext([
			'iikiti_page_body' => '',
			'iikiti_use_fragment_composer' => true,
			'iikiti_seo' => $this->seoContext(),
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

		$formaLayout = $twig->render('base/layout.twig', $this->layoutContext([
			'iikiti_forma_theme' => true,
		]));
		self::assertStringContainsString('forma-theme', $formaLayout);
		self::assertStringNotContainsString('entry:forma-2026', $formaLayout);
	}

	public function testSeoHeadEscapesHtmlAndKeepsJsonLdInert(): void
	{
		$twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates'));
		$twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/'.$path));
		$twig->addFunction(new TwigFunction('encore_entry_link_tags', static fn (string $entry): string => 'entry:'.$entry));
		$twig->addFunction(new TwigFunction('encore_entry_script_tags', static fn (string $entry): string => ''));
		$twig->addGlobal('app', (object) ['request' => (object) ['locale' => 'en']]);

		$html = $twig->render('base/layout.twig', $this->layoutContext([
			'doc' => ['title' => '<script>alert("title")</script>'],
			'iikiti_seo' => $this->seoContext('<script>alert("description")</script>'),
		]));

		self::assertStringContainsString('&lt;script&gt;alert(&quot;title&quot;)&lt;/script&gt;', $html);
		self::assertStringContainsString('&lt;script&gt;alert&#x28;&quot;description&quot;&#x29;&lt;&#x2F;script&gt;', $html);
		self::assertStringNotContainsString('<script>alert("description")</script>', $html);
		self::assertStringContainsString('\\u003C\\/script\\u003E\\u003Cscript\\u003E', $html);
		self::assertStringContainsString('<script type="application/ld+json">', $html);
	}

	public function testIikitiConfigScriptTagHexEscapesHtmlBreakoutSequences(): void
	{
		$twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates'));
		$twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/'.$path));
		$twig->addFunction(new TwigFunction('encore_entry_link_tags', static fn (string $entry): string => 'entry:'.$entry));
		$twig->addFunction(new TwigFunction('encore_entry_script_tags', static fn (string $entry): string => ''));
		$twig->addGlobal('app', (object) ['request' => (object) ['locale' => 'en']]);

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

	/** @return array<string,mixed> */
	private function seoContext(string $description = 'A description'): array
	{
		return [
			'title' => 'Example | Site',
			'description' => $description,
			'canonicalUrl' => 'https://example.test/page',
			'robots' => ['index' => 'index', 'follow' => 'follow'],
			'openGraph' => [
				'title' => 'Example | Site',
				'description' => $description,
				'type' => 'website',
				'image' => 'https://example.test/image.png',
				'url' => 'https://example.test/page',
			],
			'twitter' => [
				'card' => 'summary_large_image',
				'title' => 'Example | Site',
				'description' => $description,
				'image' => 'https://example.test/image.png',
			],
			'structuredData' => [['@context' => 'https://schema.org', '@type' => 'WebSite']],
			'structuredDataJson' => json_encode(
				[['name' => '</script><script>&"\'']],
				JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
			),
		];
	}
}

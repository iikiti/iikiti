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

		$formaLayout = $twig->render('base/layout.twig', $this->layoutContext([
			'iikiti_forma_theme' => true,
		]));
		self::assertStringContainsString('forma-theme', $formaLayout);
		self::assertStringNotContainsString('entry:forma-2026', $formaLayout);
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

<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Template;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class FallbackTemplateTest extends TestCase
{
	public function testFallbackTemplatesRenderWithoutFormaPlugin(): void
	{
		$loader = new FilesystemLoader(\dirname(__DIR__, 3).'/templates');
		$twig = new Environment($loader);
		$twig->addFunction(new TwigFunction('iikiti_region', static fn (string $id, string $role, string $label, array $allowed, string $content = ''): string => sprintf('<div data-region="%s" data-role="%s">%s</div>', $id, $role, $content), ['is_safe' => ['html']]));

		$contentHtml = $twig->render('base/fallback-block-editor-content.twig', [
			'region_main' => '<p>Hello world</p>',
			'iikiti_shells' => [
				'header' => ['<nav>Top</nav>'],
				'footer' => ['<footer>Bottom</footer>'],
			],
		]);

		self::assertStringContainsString('<main id="main-content"', $contentHtml);
		self::assertStringContainsString('<p>Hello world</p>', $contentHtml);
		self::assertStringContainsString('<nav>Top</nav>', $contentHtml);
		self::assertStringContainsString('<footer>Bottom</footer>', $contentHtml);

		$errorHtml = $twig->render('base/fallback-error.html.twig', [
			'app' => ['request' => ['locale' => 'en']],
			'status_code' => 404,
			'title' => 'Page not found',
			'message' => 'Missing page',
			'action_label' => 'Go home',
		]);

		self::assertStringContainsString('ERROR 404', $errorHtml);
		self::assertStringContainsString('Page not found', $errorHtml);
		self::assertStringContainsString('Missing page', $errorHtml);
	}
}

<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Template;

use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Plugin\PluginLoader;
use iikiti\CMS\Plugin\PluginValidator;
use iikiti\CMS\Web\Template\HttpErrorPageRenderer;
use iikiti\Extension\Forma2026\Fragment\ThemeStylesFragment;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class Forma2026ThemeTest extends TestCase
{
	public function testDefaultLayoutUsesForma2026AndProvidesAccessibleMainLandmark(): void
	{
		self::assertSame('@Forma2026/default-block-editor-content.twig', Template::DEFAULT_LAYOUT);

		$html = $this->twig()->render(Template::DEFAULT_LAYOUT, [
			'iikiti_shells' => [],
			'region_main' => '<h1>Welcome</h1>',
		]);

		self::assertStringContainsString('href="#forma-main-content"', $html);
		self::assertStringContainsString('<main id="forma-main-content"', $html);
		self::assertStringContainsString('data-region-id="main"', $html);
		self::assertStringContainsString('<h1>Welcome</h1>', $html);
		self::assertStringContainsString('<!-- fragment:forma-2026.theme-styles -->', $html);
		self::assertStringNotContainsString('<header', $html);
		self::assertStringNotContainsString('<footer', $html);

		$legacyHtml = $this->twig()->render(Template::LEGACY_DEFAULT_LAYOUT, [
			'iikiti_shells' => [],
			'region_main' => '<h1>Welcome</h1>',
		]);
		self::assertStringContainsString('<main id="forma-main-content"', $legacyHtml);
	}

	public function testOptionalShellsRenderAsSemanticRegions(): void
	{
		$html = $this->twig()->render(Template::DEFAULT_LAYOUT, [
			'iikiti_shells' => [
				'header' => ['<div>Site identity</div>'],
				'aside' => ['<p>Related links</p>'],
				'footer' => ['<p>Copyright</p>'],
				'dialog' => ['<dialog>Preferences</dialog>'],
			],
			'region_main' => '<p>Page content</p>',
		]);

		self::assertStringContainsString('<header class="forma-site-header" data-iikiti-bar="top"', $html);
		self::assertStringContainsString('<aside class="forma-sidebar" aria-label="Additional content">', $html);
		self::assertStringContainsString('<footer class="forma-site-footer" data-iikiti-bar="bottom">', $html);
		self::assertStringContainsString('<dialog>Preferences</dialog>', $html);
		self::assertLessThan(strpos($html, 'data-region-id="header-1"'), strpos($html, 'class="forma-skip-link"'));
	}

	public function testThemeStylesAreScopedAndSupportKeyboardAndResponsivePreferences(): void
	{
		$css = file_get_contents(dirname(__DIR__, 3).'/plugins/forma-2026/assets/theme.css');
		self::assertIsString($css);
		self::assertStringContainsString('body.forma-theme .forma-skip-link:focus-visible', $css);
		self::assertStringContainsString('@media (max-width: 48rem)', $css);
		self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
		self::assertStringContainsString('@media (forced-colors: active)', $css);

		(new PluginLoader(dirname(__DIR__, 3), new PluginValidator()))->discover();
		$styles = (new ThemeStylesFragment())->render(new \iikiti\CMS\Web\Fragment\FragmentContext(null, 'en'));
		self::assertSame('', $styles->html);
		self::assertSame('forma-2026.theme-styles', $styles->styles[0]['id']);
		self::assertSame($css, $styles->styles[0]['css']);
	}

	public function testSpecificAndGenericErrorPagesDoNotExposeExceptionDetails(): void
	{
		$twig = $this->twig();
		$twig->addGlobal('app', (object) ['request' => (object) ['locale' => 'en']]);
		$exception = new \RuntimeException('sensitive file path /srv/private/secret.php');

		$error404 = $twig->render('bundles/TwigBundle/Exception/error404.html.twig', ['exception' => $exception]);
		$error500 = $twig->render('bundles/TwigBundle/Exception/error500.html.twig', ['exception' => $exception]);
		$error429 = $twig->render('bundles/TwigBundle/Exception/error.html.twig', [
			'status_code' => 429,
			'exception' => $exception,
		]);

		self::assertStringContainsString('Page not found', $error404);
		self::assertStringContainsString('ERROR 404', $error404);
		self::assertStringContainsString('Something went wrong', $error500);
		self::assertStringContainsString('ERROR 500', $error500);
		self::assertStringContainsString('Please slow down', $error429);
		foreach ([$error404, $error500, $error429] as $html) {
			self::assertStringContainsString('<main aria-labelledby="error-title">', $html);
			self::assertStringNotContainsString('sensitive file path', $html);
			self::assertStringNotContainsString('/srv/private/secret.php', $html);
		}

		$response = (new HttpErrorPageRenderer($twig))->render(500);
		self::assertSame(500, $response->getStatusCode());
		self::assertStringContainsString('Something went wrong', (string) $response->getContent());
		self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

		foreach ([418, 599] as $statusCode) {
			$genericError = $twig->render('bundles/TwigBundle/Exception/error.html.twig', [
				'status_code' => $statusCode,
				'exception' => $exception,
			]);
			self::assertStringContainsString('ERROR '.$statusCode, $genericError);
			self::assertStringNotContainsString('sensitive file path', $genericError);
		}
	}

	private function twig(): Environment
	{
		$root = dirname(__DIR__, 3);
		$loader = new FilesystemLoader($root.'/templates');
		$loader->addPath($root.'/plugins/forma-2026/templates', 'Forma2026');
		$twig = new Environment($loader, ['autoescape' => 'html']);
		$twig->addFunction(new TwigFunction(
			'iikiti_region',
			static fn (string $id, string $role, string $name, array $allowedTypes, string $html): string => sprintf('<div data-region-id="%s">%s</div>', $id, $html),
			['is_safe' => ['html']],
		));
		$twig->addFunction(new TwigFunction(
			'iikiti_fragment',
			static fn (string $provider): string => sprintf('<!-- fragment:%s -->', $provider),
			['is_safe' => ['html']],
		));

		return $twig;
	}
}

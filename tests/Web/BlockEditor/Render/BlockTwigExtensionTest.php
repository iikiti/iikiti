<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Render;

use iikiti\CMS\Web\BlockEditor\Embed\EmbedResolver;
use iikiti\CMS\Web\BlockEditor\Query\QueryExecutor;
use iikiti\CMS\Web\BlockEditor\Twig\BlockTwigExtension;
use iikiti\CMS\Web\Icon\IconFontAssets;
use iikiti\CMS\Tests\Support\IconResolverFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class BlockTwigExtensionTest extends TestCase
{
	private function makeTwig(bool $canEdit, bool $editorMode = false): Environment
	{
		$twig = new Environment(new ArrayLoader([
			'tpl' => '{{ iikiti_region("main", "main", "Main content", ["heading", "text"], html) }}',
		]), [
			'strict_variables' => false,
			'autoescape' => false,
		]);

		$resolver = new EmbedResolver(
			[],
			new MockHttpClient(),
			new ArrayAdapter(),
		);
		$queryExecutor = new QueryExecutor([]);

		$twig->addExtension(new BlockTwigExtension($resolver, $queryExecutor, IconResolverFactory::bundledOnly(), new IconFontAssets()));
		$twig->addGlobal('iikiti_can_edit', $canEdit);
		$twig->addGlobal('iikiti_editor_mode', $editorMode);

		return $twig;
	}

	public function testRegionEmitsDataComponentWhenCanEdit(): void
	{
		$twig = $this->makeTwig(true);

		$html = $twig->render('tpl', ['html' => '<p>Hi</p>']);

		self::assertStringContainsString('data-component="BlockEditorComponent"', $html);
		self::assertStringContainsString('data-region-id="main"', $html);
		self::assertStringContainsString('data-region-role="main"', $html);
		self::assertStringContainsString('data-allowed-types="heading,text"', $html);
		self::assertStringContainsString('>Hi</p>', $html);
	}

	public function testRegionEmitsNoMetadataWhenNotCanEdit(): void
	{
		$twig = $this->makeTwig(false);

		$html = $twig->render('tpl', ['html' => '<p>Hi</p>']);

		self::assertStringNotContainsString('data-component', $html);
		self::assertStringNotContainsString('data-region-id', $html);
		self::assertStringContainsString('iikiti-region iikiti-region--main', $html);
		self::assertStringContainsString('>Hi</p>', $html);
	}

	public function testRegionEmitsMetadataWithoutBlockMetadata(): void
	{
		$twig = $this->makeTwig(true, editorMode: false);

		$html = $twig->render('tpl', ['html' => '<p>Hi</p>']);

		self::assertStringContainsString('data-component="BlockEditorComponent"', $html);
		self::assertStringNotContainsString('data-block-', $html);
	}

	private IconFontAssets $iconFontAssets;

	private function makeIconTwig(): Environment
	{
		$this->iconFontAssets = new IconFontAssets();
		$twig = new Environment(new ArrayLoader([
			'icon' => '{{ iikiti_icon(name, renderer, size, color) }}',
		]), [
			'strict_variables' => false,
			'autoescape' => 'html',
		]);
		$twig->addExtension(new BlockTwigExtension(
			new EmbedResolver([], new MockHttpClient(), new ArrayAdapter()),
			new QueryExecutor([]),
			IconResolverFactory::bundledOnly(),
			$this->iconFontAssets,
		));

		return $twig;
	}

	public function testIconRendersSvgForKnownNameAndRenderer(): void
	{
		$html = $this->makeIconTwig()->render('icon', ['name' => 'box', 'renderer' => 'svg', 'size' => 20, 'color' => '#123456']);

		self::assertStringStartsWith('<svg ', $html);
		self::assertStringContainsString('class="iikiti-icon iikiti-icon--box"', $html);
		self::assertStringContainsString('stroke="#123456"', $html);
	}

	public function testIconEmitsNothingForUnknownName(): void
	{
		$html = $this->makeIconTwig()->render('icon', ['name' => 'nope', 'renderer' => 'svg']);

		self::assertSame('', $html);
	}

	public function testIconEmitsNothingForUnsupportedRenderer(): void
	{
		$html = $this->makeIconTwig()->render('icon', ['name' => 'box', 'renderer' => 'canvas']);

		self::assertSame('', $html);
	}

	public function testFontRendererEmitsGlyphAndMarksFontUsed(): void
	{
		$twig = $this->makeIconTwig();
		$html = $twig->render('icon', ['name' => 'box', 'renderer' => 'font']);

		self::assertStringContainsString('class="iikiti-icon-font"', $html);
		self::assertStringContainsString('data-icon="box"', $html);
		self::assertMatchesRegularExpression('/&#x[0-9A-F]{4};/', $html);
		self::assertTrue($this->iconFontAssets->isUsed());
	}

	public function testFontRendererEmitsNothingForUnknownNameAndDoesNotMarkFont(): void
	{
		$html = $this->makeIconTwig()->render('icon', ['name' => 'nope', 'renderer' => 'font']);

		self::assertSame('', $html);
		self::assertFalse($this->iconFontAssets->isUsed());
	}

	public function testSvgRendererDoesNotMarkFontUsed(): void
	{
		$this->makeIconTwig()->render('icon', ['name' => 'box', 'renderer' => 'svg']);

		self::assertFalse($this->iconFontAssets->isUsed());
	}

	public function testIconNeverEchoesHostileNameOrColour(): void
	{
		$html = $this->makeIconTwig()->render('icon', [
			'name' => '<script>alert(1)</script>',
			'renderer' => 'svg',
			'color' => '"><script>x</script>',
		]);

		self::assertSame('', $html);
		self::assertStringNotContainsString('<script>', $html);
	}
}

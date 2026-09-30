<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Render;

use iikiti\CMS\Web\BlockEditor\Embed\EmbedResolver;
use iikiti\CMS\Web\BlockEditor\Query\QueryExecutor;
use iikiti\CMS\Web\BlockEditor\Twig\BlockTwigExtension;
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

		$twig->addExtension(new BlockTwigExtension($resolver, $queryExecutor));
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
}

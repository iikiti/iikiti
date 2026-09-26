<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Render;

use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use iikiti\CMS\Web\BlockEditor\BlockType\CoreBlockTypeProvider;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderer;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class BlockRendererTest extends TestCase
{
	private BlockRenderer $renderer;

	protected function setUp(): void
	{
		$twig = new Environment(new FilesystemLoader(__DIR__.'/../../../../templates'), [
			'strict_variables' => false,
		]);

		$this->renderer = new BlockRenderer(new BlockTypeRegistry([new CoreBlockTypeProvider()]), $twig);
	}

	public function testRenderTreeEmitsNoMetadataInThePublicMode(): void
	{
		$context = new BlockRenderContext(editorMode: false);
		$html = $this->renderer->renderTree($this->sampleTree(), $context);

		$this->assertStringContainsString('iikiti-block iikiti-block--text', $html);
		$this->assertStringNotContainsString('data-block-', $html);
	}

	public function testRenderTreeEmitsMetadataInTheEditMode(): void
	{
		$context = new BlockRenderContext(editorMode: true);
		$html = $this->renderer->renderTree($this->sampleTree(), $context);

		$this->assertStringContainsString('data-block-type="text"', $html);
		$this->assertStringContainsString('data-block-content=', $html);
		$this->assertStringContainsString('data-block-style=', $html);
		$this->assertStringContainsString('iikiti-text', $html);
	}

	public function testContainerRecursivelyRendersChildren(): void
	{
		$tree = [
			[
				'id' => 'c1',
				'type' => 'container',
				'content' => [],
				'style' => ['base' => ['layout' => 'flex']],
				'children' => [['type' => 'heading', 'content' => ['level' => '2', 'text' => 'Hi']]],
			],
		];

		$html = $this->renderer->renderTree($tree, new BlockRenderContext(editorMode: false));

		$this->assertStringContainsString('iikiti-container', $html);
		$this->assertStringContainsString('iikiti-heading', $html);
		$this->assertStringContainsString('<h2', $html);
	}

	public function testUnknownBlockTypeRendersPlaceholder(): void
	{
		$html = $this->renderer->renderTree([['type' => 'not_a_block']], new BlockRenderContext(editorMode: false));

		$this->assertStringContainsString('iikiti-block--placeholder', $html);
		$this->assertStringNotContainsString('data-block-type', $html);
	}

	public function testEditHintBlockIsHiddenFromPublicButShownToEditors(): void
	{
		$tree = [
			[
				'type' => 'text',
				'content' => ['content' => '<p>Edit this page with <code>?edit</code>.</p>'],
				'style' => ['base' => []],
			],
			[
				'type' => 'text',
				'content' => ['content' => '<p>Public content</p>'],
				'style' => ['base' => []],
			],
		];

		$public = $this->renderer->renderTree($tree, new BlockRenderContext(editorMode: false, canEdit: false));
		$editor = $this->renderer->renderTree($tree, new BlockRenderContext(editorMode: true, canEdit: true));

		// Public output: instructional hint is suppressed for non-editors,
		// real content survives.
		self::assertStringNotContainsString('Edit this page with', $public);
		self::assertStringContainsString('Public content', $public);
		self::assertStringNotContainsString('data-block-', $public);

		// Editor output (logged-in editor): hint is visible and hydratable.
		self::assertStringContainsString('Edit this page with', $editor);
		self::assertStringContainsString('data-block-type="text"', $editor);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function sampleTree(): array
	{
		return [
			[
				'id' => 'b1',
				'type' => 'text',
				'content' => ['content' => '<p>Hello</p>'],
				'style' => ['base' => ['color' => '#000']],
			],
		];
	}
}

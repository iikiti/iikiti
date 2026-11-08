<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Render;

use iikiti\CMS\Web\BlockEditor\Render\CustomCssCollector;
use PHPUnit\Framework\TestCase;

final class CustomCssCollectorTest extends TestCase
{
	public function testDeduplicatesChunksAndEscapesStyleEndTags(): void
	{
		$collector = new CustomCssCollector();
		$css = '.scope{content:"</style>"}';
		$collector->append($css);
		$collector->append($css);

		self::assertSame('.scope{content:"\\3C /style>"}', $collector->stylesheet());
	}

	public function testEditorStylesheetsAreSeparatedByOwner(): void
	{
		$collector = new CustomCssCollector();
		$collector->append('.page{color:red}', 'iikiti-custom-css-preview-page-4');
		$collector->append('.block{color:blue}', 'iikiti-custom-css-block-blk_1');
		$collector->append('.block:hover{color:green}', 'iikiti-custom-css-block-blk_1');

		self::assertSame(
			'<style id="iikiti-custom-css-preview-page-4" data-iikiti-custom-css>.page{color:red}</style>'
			.'<style id="iikiti-custom-css-block-blk_1" data-iikiti-custom-css>.block{color:blue}.block:hover{color:green}</style>',
			$collector->stylesheets(),
		);
	}

	public function testExportsPageAndFragmentStylesAsDeduplicatedChunks(): void
	{
		$collector = new CustomCssCollector();
		$collector->append('.page{color:red}', 'page-style');
		$collector->append('.block{color:blue}', 'block-style');
		$collector->append('.block{color:blue}', 'block-style');

		self::assertSame([
			['id' => 'page-style', 'css' => '.page{color:red}'],
			['id' => 'block-style', 'css' => '.block{color:blue}'],
		], $collector->fragments(editorMode: true));
		self::assertSame([
			['id' => null, 'css' => '.page{color:red}'],
			['id' => null, 'css' => '.block{color:blue}'],
		], $collector->fragments(editorMode: false));
	}
}

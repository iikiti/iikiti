<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor;

use InvalidArgumentException;
use iikiti\CMS\Web\BlockEditor\CustomCssCompiler;
use PHPUnit\Framework\TestCase;

final class CustomCssCompilerTest extends TestCase
{
	public function testCompilesCssInsideScopeInCompressedForm(): void
	{
		$compiler = new CustomCssCompiler();

		$css = $compiler->compileScoped('.card { color: red; &:hover { color: blue; } }', 'body.iikiti-page-id--7');

		self::assertSame('body.iikiti-page-id--7{.card{color:red;&:hover{color:blue}}}', $css);
	}

	public function testWrapsResponsiveWidgetCssInItsBreakpointAndScope(): void
	{
		$compiler = new CustomCssCompiler();

		$css = $compiler->compileScoped('font-weight: bold;', '.iikiti-block-id--card', 768);

		self::assertSame('@media(min-width: 768px){.iikiti-block-id--card{font-weight:bold}}', $css);
	}

	public function testRejectsInvalidCssAndOversizedInput(): void
	{
		$compiler = new CustomCssCompiler();

		try {
			$compiler->validate('color red;');
			self::fail('Invalid CSS should throw.');
		} catch (InvalidArgumentException $error) {
			self::assertNotSame('', $error->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$compiler->validate(str_repeat('a', 100_001));
	}
}

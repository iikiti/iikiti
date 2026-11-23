<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor;

use InvalidArgumentException;
use iikiti\CMS\Web\BlockEditor\DelegatingCustomCssCompiler;
use iikiti\CMS\Web\BlockEditor\PlainCssCompiler;
use iikiti\Extension\CustomCss\Css\CustomCssCompiler;
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

	public function testPlainCssCompilerFallbackScopesAndValidatesBasicInput(): void
	{
		$compiler = new PlainCssCompiler();

		self::assertSame('body.iikiti-page-id--7{color: red;}', $compiler->compileScoped('color: red;', 'body.iikiti-page-id--7'));
		self::assertSame('@media(min-width: 768px){.box{margin: 0;}}', $compiler->compileScoped('margin: 0;', '.box', 768));
		self::assertSame('.box{content: "}"; /* { */}', $compiler->compileScoped('content: "}"; /* { */', '.box'));

		$this->expectException(InvalidArgumentException::class);
		$compiler->validate('body { color: red;');
	}

	public function testDelegatingCompilerUsesPluginWhenPresentAndFallsBackToPlain(): void
	{
		$pluginBacked = new DelegatingCustomCssCompiler(new PlainCssCompiler(), [new CustomCssCompiler()]);
		self::assertSame(
			'body.iikiti-page-id--7{.card{color:red;&:hover{color:blue}}}',
			$pluginBacked->compileScoped('.card { color: red; &:hover { color: blue; } }', 'body.iikiti-page-id--7'),
		);

		$fallbackOnly = new DelegatingCustomCssCompiler(new PlainCssCompiler(), []);
		self::assertSame('body.iikiti-page-id--7{color: red;}', $fallbackOnly->compileScoped('color: red;', 'body.iikiti-page-id--7'));

		$this->expectException(InvalidArgumentException::class);
		$pluginBacked->compileScoped('color: red;', '.card', -1);
	}
}

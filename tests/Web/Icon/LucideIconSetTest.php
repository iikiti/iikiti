<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Icon;

use iikiti\CMS\Web\Icon\LucideIconSet;
use PHPUnit\Framework\TestCase;

final class LucideIconSetTest extends TestCase
{
	public function testExposesNameAndKnownIcons(): void
	{
		$set = new LucideIconSet();

		$this->assertSame('lucide', $set->name());
		$this->assertContains('box', $set->names());
		$this->assertTrue($set->has('box'));
		$this->assertFalse($set->has('does-not-exist'));
	}

	public function testUnknownNameReturnsNullSvg(): void
	{
		$this->assertNull((new LucideIconSet())->svg('does-not-exist'));
	}

	public function testRendersSvgWithViewBoxSizeAndColour(): void
	{
		$svg = (new LucideIconSet())->svg('box', 32, '#ff0000');

		$this->assertNotNull($svg);
		$this->assertStringStartsWith('<svg ', $svg);
		$this->assertStringContainsString('width="32px"', $svg);
		$this->assertStringContainsString('height="32px"', $svg);
		$this->assertStringContainsString('viewBox="0 0 24 24"', $svg);
		$this->assertStringContainsString('stroke="#ff0000"', $svg);
		$this->assertStringContainsString('class="iikiti-icon iikiti-icon--box"', $svg);
	}

	public function testMarksSvgDecorativeForAssistiveTech(): void
	{
		$svg = (new LucideIconSet())->svg('box');

		$this->assertStringContainsString('aria-hidden="true"', $svg);
		$this->assertStringContainsString('focusable="false"', $svg);
	}

	public function testEscapesHostileColourValue(): void
	{
		$svg = (new LucideIconSet())->svg('box', 24, '"><script>alert(1)</script>');

		$this->assertStringNotContainsString('<script>', $svg);
		$this->assertStringContainsString('&quot;&gt;', $svg);
	}

	public function testClampsNonPositiveSizeToOne(): void
	{
		$svg = (new LucideIconSet())->svg('box', 0);

		$this->assertStringContainsString('width="1px"', $svg);
	}

	public function testOmittedSizeSizesToContainerInEm(): void
	{
		$svg = (new LucideIconSet())->svg('box');

		$this->assertStringContainsString('width="1em"', $svg);
		$this->assertStringContainsString('height="1em"', $svg);
	}

	public function testStrokeWidthIsFormattedWithoutTrailingZeros(): void
	{
		$this->assertStringContainsString('stroke-width="2"', (new LucideIconSet())->svg('box', 24, 'currentColor', 2.0));
		$this->assertStringContainsString('stroke-width="1.5"', (new LucideIconSet())->svg('box', 24, 'currentColor', 1.5));
	}

	public function testGlyphReturnsNumericReferenceForKnownIcon(): void
	{
		$glyph = (new LucideIconSet())->glyph('box');

		$this->assertNotNull($glyph);
		$this->assertMatchesRegularExpression('/^&#x[0-9A-F]{4};$/', $glyph);
	}

	public function testGlyphIsNullForUnknownIcon(): void
	{
		$this->assertNull((new LucideIconSet())->glyph('does-not-exist'));
	}
}

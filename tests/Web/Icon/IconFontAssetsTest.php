<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Icon;

use iikiti\CMS\Web\Icon\IconFontAssets;
use PHPUnit\Framework\TestCase;

final class IconFontAssetsTest extends TestCase
{
	public function testPublicPageLinksFontOnlyWhenAFontIconWasRendered(): void
	{
		$assets = new IconFontAssets(true);

		self::assertFalse($assets->shouldLink(false));

		$assets->markUsed();

		self::assertTrue($assets->shouldLink(false));
	}

	public function testDisabledPublicFontIsNeverLinkedOnPublicPages(): void
	{
		$assets = new IconFontAssets(false);
		$assets->markUsed();

		self::assertFalse($assets->shouldLink(false));
	}

	public function testEditorAndAdminAlwaysLinkTheFontEvenWhenPublicFontIsDisabled(): void
	{
		$assets = new IconFontAssets(false);

		self::assertTrue($assets->shouldLink(true));
	}

	public function testStylesheetPathIsRelativeToBuildOutput(): void
	{
		self::assertSame('build/vendor/lucide-font/lucide.css', IconFontAssets::STYLESHEET_PATH);
	}
}

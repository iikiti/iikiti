<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Icon;

use iikiti\CMS\Web\Icon\IconFontAssets;
use iikiti\CMS\Web\Icon\IconFontStylesheetProviderInterface;
use iikiti\Extension\IconsLucide\Icon\LucideIconFontProvider;
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

	public function testCollectsStylesheetPathsFromTaggedProviders(): void
	{
		$custom = new class implements IconFontStylesheetProviderInterface {
			public function getFontStylesheetPath(): string
			{
				return '/plugin-assets/custom/font.css';
			}
		};
		$inactive = new class implements IconFontStylesheetProviderInterface {
			public function getFontStylesheetPath(): ?string
			{
				return null;
			}
		};

		$assets = new IconFontAssets(true, [new LucideIconFontProvider(), $inactive, $custom, new LucideIconFontProvider()]);

		self::assertSame([
			'/plugin-assets/iikiti-icons-lucide/font/lucide.css',
			'/plugin-assets/custom/font.css',
		], $assets->getStylesheetPaths());
	}
}

<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Icon;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Provides public font stylesheet paths for bundled icon sets (e.g. from plugins).
 */
#[AutoconfigureTag('iikiti.cms.icon_font_provider')]
interface IconFontStylesheetProviderInterface
{
	/**
	 * Public URL path or asset-relative path for the icon font stylesheet, or null when inactive.
	 */
	public function getFontStylesheetPath(): ?string;
}

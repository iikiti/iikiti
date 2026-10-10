<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Icon;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tracks whether the current response uses the icon font, so the layout links the
 * Lucide font stylesheet only on pages that need it (the page body renders before
 * the layout, so the flag is known by the time the head is written).
 *
 * Request-scoped: the service is shared within one request and is reset per
 * request by the container.
 */
final class IconFontAssets
{
	/** Path to the font stylesheet, relative to the public build directory (see webpack.config.mjs). */
	public const STYLESHEET_PATH = 'build/vendor/lucide-font/lucide.css';

	private bool $used = false;

	/**
	 * @param bool $publicFontEnabled `iikiti_icon.public_font`: when false, public pages
	 *                                never link the font. Editor/admin pages pass `$always`.
	 */
	public function __construct(
		#[Autowire('%iikiti_icon.public_font%')]
		private readonly bool $publicFontEnabled = true,
	) {
	}

	public function markUsed(): void
	{
		$this->used = true;
	}

	/**
	 * Whether the font stylesheet should be linked for this response.
	 *
	 * @param bool $editorOrAdmin editor and admin pages always get the font
	 */
	public function shouldLink(bool $editorOrAdmin): bool
	{
		return $editorOrAdmin || ($this->used && $this->publicFontEnabled);
	}

	public function isUsed(): bool
	{
		return $this->used;
	}
}

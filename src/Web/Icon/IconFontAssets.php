<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Icon;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tracks whether the current response uses the icon font, so the layout links any
 * active icon-font stylesheets only on pages that need them (the page body renders
 * before the layout, so the flag is known by the time the head is written).
 *
 * Request-scoped: the service is shared within one request and is reset per
 * request by the container.
 */
final class IconFontAssets
{
	private bool $used = false;

	/**
	 * @param bool                                          $publicFontEnabled `iikiti_icon.public_font`: when false, public pages never link the font. Editor/admin pages pass `$always`.
	 * @param iterable<IconFontStylesheetProviderInterface> $providers
	 */
	public function __construct(
		#[Autowire('%iikiti_icon.public_font%')]
		private readonly bool $publicFontEnabled = true,
		#[AutowireIterator('iikiti.cms.icon_font_provider')]
		private readonly iterable $providers = [],
	) {
	}

	public function markUsed(): void
	{
		$this->used = true;
	}

	/**
	 * Whether font stylesheets should be linked for this response.
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

	/**
	 * @return list<string>
	 */
	public function getStylesheetPaths(): array
	{
		$paths = [];
		foreach ($this->providers as $provider) {
			$path = $provider->getFontStylesheetPath();
			if (is_string($path) && '' !== trim($path)) {
				$paths[] = trim($path);
			}
		}

		return array_values(array_unique($paths));
	}
}

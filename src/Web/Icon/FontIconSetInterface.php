<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Icon;

/**
 * Extension of {@see IconSet} for icon sets backed by a CSS `@font-face`
 * icon font.
 */
interface FontIconSetInterface extends IconSet
{
	/** Font family name declared by the set's stylesheet (e.g. `lucide`), or null if none. */
	public function fontFamily(): ?string;
}

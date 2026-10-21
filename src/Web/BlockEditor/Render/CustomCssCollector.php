<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Render;

/** Collects already compiled CSS chunks for one rendered page. */
final class CustomCssCollector
{
	/** @var array<string,true> */
	private array $chunks = [];

	public function append(string $css): void
	{
		$css = trim($css);
		if ('' !== $css) {
			$this->chunks[$css] = true;
		}
	}

	public function stylesheet(): string
	{
		$css = implode('', array_keys($this->chunks));

		return str_ireplace('</style', '\\3C /style', $css);
	}
}

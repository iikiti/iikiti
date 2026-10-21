<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Render;

/** Collects already compiled CSS chunks for one rendered page. */
final class CustomCssCollector
{
	/** @var array<string,true> */
	private array $chunks = [];
	/** @var array<string,array<string,true>> */
	private array $ownedChunks = [];

	public function append(string $css, ?string $styleId = null): void
	{
		$css = trim($css);
		if ('' === $css) {
			return;
		}

		$this->chunks[$css] = true;
		$this->ownedChunks[$styleId ?? 'iikiti-custom-css'][$css] = true;
	}

	public function stylesheet(): string
	{
		$css = implode('', array_keys($this->chunks));

		return $this->escapeStyleEndTag($css);
	}

	public function stylesheets(): string
	{
		$html = '';
		foreach ($this->ownedChunks as $styleId => $chunks) {
			$css = $this->escapeStyleEndTag(implode('', array_keys($chunks)));
			$html .= '<style id="'.htmlspecialchars($styleId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" data-iikiti-custom-css>'.$css.'</style>';
		}

		return $html;
	}

	private function escapeStyleEndTag(string $css): string
	{
		return str_ireplace('</style', '\\3C /style', $css);
	}
}

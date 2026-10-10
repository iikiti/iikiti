<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Icon;

/**
 * The bundled Lucide icon set (ISC). Geometry comes from {@see LucideIconData}.
 */
final class LucideIconSet implements IconSet
{
	#[\Override]
	public function name(): string
	{
		return 'lucide';
	}

	#[\Override]
	public function names(): array
	{
		return array_keys(LucideIconData::ICONS);
	}

	#[\Override]
	public function has(string $name): bool
	{
		return isset(LucideIconData::ICONS[$name]);
	}

	#[\Override]
	public function svg(string $name, ?int $size = null, string $color = 'currentColor', float $strokeWidth = 2.0): ?string
	{
		if (!$this->has($name)) {
			return null;
		}

		$dimension = null === $size ? '1em' : (string) max(1, $size).'px';
		$strokeWidth = max(0.0, $strokeWidth);

		$children = '';
		foreach (LucideIconData::ICONS[$name] as [$tag, $attributes]) {
			$children .= '<'.$this->tag($tag);
			foreach ($attributes as $attribute => $value) {
				$children .= ' '.$this->attributeName($attribute).'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
			}
			$children .= '/>';
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$s" height="%1$s" viewBox="0 0 24 24" fill="none" '
			.'stroke="%2$s" stroke-width="%3$s" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" '
			.'focusable="false" class="iikiti-icon iikiti-icon--%4$s">%5$s</svg>',
			$dimension,
			htmlspecialchars($color, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
			rtrim(rtrim(number_format($strokeWidth, 2, '.', ''), '0'), '.'),
			htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
			$children,
		);
	}

	#[\Override]
	public function glyph(string $name): ?string
	{
		$codepoint = LucideFontCodepoints::CODEPOINTS[$name] ?? null;
		if (null === $codepoint || !$this->has($name)) {
			return null;
		}

		return sprintf('&#x%04X;', $codepoint);
	}

	/** Guards element names; the data is static, but this keeps the contract explicit. */
	private function tag(string $tag): string
	{
		return preg_match('/^[a-z][a-z0-9]*$/', $tag) === 1 ? $tag : 'path';
	}

	/** Guards attribute names against injection. */
	private function attributeName(string $attribute): string
	{
		return preg_match('/^[a-z][a-z0-9-]*$/', $attribute) === 1 ? $attribute : 'data-invalid';
	}
}

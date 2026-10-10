<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Icon;

/**
 * A named collection of icons the icon block can render.
 *
 * The same set is the single source of truth for the public renderer, the
 * editor picker, and the canvas (editor config), so all three agree.
 */
interface IconSet
{
	/** Stable identifier, e.g. `lucide`. */
	public function name(): string;

	/**
	 * Every icon name in the set, in display order.
	 *
	 * @return list<string>
	 */
	public function names(): array;

	public function has(string $name): bool;

	/**
	 * Server-generated SVG markup for `$name`, or null when the name is unknown.
	 * A null `$size` sizes the icon to its container (`1em`); an int is pixels.
	 * Output is built from trusted static data only; never from caller input.
	 */
	public function svg(string $name, ?int $size = null, string $color = 'currentColor', float $strokeWidth = 2.0): ?string;

	/**
	 * Icon-font markup for `$name` (a single glyph character as a numeric character
	 * reference), or null when the set has no font glyph for it.
	 */
	public function glyph(string $name): ?string;
}

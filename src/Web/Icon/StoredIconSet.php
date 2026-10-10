<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Icon;

use iikiti\CMS\Entity\IconSetEntity;
use iikiti\CMS\Repository\IconRepository;
use iikiti\CMS\Repository\IconSetRepository;

/**
 * Read-only {@see IconSet} over one admin-managed set (stored, sanitised SVG).
 * Icons are addressed by their bare name within the set.
 */
final class StoredIconSet implements IconSet
{
	/** @var array<string, string>|null name => sanitised svg, loaded lazily */
	private ?array $svgs = null;

	public function __construct(
		private readonly IconSetEntity $set,
		private readonly IconRepository $icons,
	) {
	}

	public function slug(): string
	{
		return $this->set->getSlug();
	}

	#[\Override]
	public function name(): string
	{
		return $this->set->getSlug();
	}

	#[\Override]
	public function names(): array
	{
		return array_keys($this->load());
	}

	#[\Override]
	public function has(string $name): bool
	{
		return isset($this->load()[$name]);
	}

	/**
	 * Stored SVG is already sanitised; it is returned as-is. The size and colour
	 * contract matches the bundled set, so stored icons scale and colour the same way.
	 */
	#[\Override]
	public function svg(string $name, ?int $size = null, string $color = 'currentColor', float $strokeWidth = 2.0): ?string
	{
		$svg = $this->load()[$name] ?? null;
		if (null === $svg) {
			return null;
		}

		$dimension = null === $size ? '1em' : (string) max(1, $size).'px';

		return $this->applyPresentation($svg, $dimension, $color, $name);
	}

	/** Stored icons have no font glyph; the font renderer does not apply to admin sets. */
	#[\Override]
	public function glyph(string $name): ?string
	{
		return null;
	}

	/**
	 * Set the outer width/height and stroke presentation on the already-sanitised root.
	 * Only attributes on the root <svg> are rewritten, using fixed values we control.
	 */
	private function applyPresentation(string $svg, string $dimension, string $color, string $name): string
	{
		$colour = htmlspecialchars($color, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$class = 'iikiti-icon iikiti-icon--'.htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$loaded || null === $document->documentElement) {
			return '';
		}

		$root = $document->documentElement;
		$root->setAttribute('width', $dimension);
		$root->setAttribute('height', $dimension);
		$root->setAttribute('stroke', $colour);
		$root->setAttribute('fill', 'none');
		$root->setAttribute('aria-hidden', 'true');
		$root->setAttribute('focusable', 'false');
		$root->setAttribute('class', $class);

		return $document->saveXML($root) ?: '';
	}

	/** @return array<string, string> */
	private function load(): array
	{
		if (null === $this->svgs) {
			$this->svgs = [];
			foreach ($this->icons->findBySet($this->set) as $icon) {
				$this->svgs[$icon->getName()] = $icon->getSvg();
			}
		}

		return $this->svgs;
	}
}
